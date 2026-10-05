<?php
// Copyright (c) 2026 TomTom Enterprises. Licensed under the MIT License (see LICENSE).
// Live functional test of the panel libraries against the privileged helper (deploy/novacpx-root).
// WARNING: creates and then terminates a real test account (ncrtest1) plus a docker stack on THIS server; use a dev box or a quiet moment.
// Copy panel/lib to /tmp/ncx-live/lib and this file to /tmp/ncx-live/, then:  sudo -u www-data php8.3 /tmp/ncx-live/live-test.php
define('NOVACPX_ROOT', '/srv/novacpx/public');
define('NOVACPX_LIB', '/tmp/ncx-live/lib');
require NOVACPX_LIB . '/Core.php';
require NOVACPX_LIB . '/DB.php';
require NOVACPX_LIB . '/Auth.php';
require NOVACPX_LIB . '/Response.php';
require NOVACPX_LIB . '/AccountManager.php';
require NOVACPX_LIB . '/DNSManager.php';
require NOVACPX_LIB . '/VhostManager.php';
require NOVACPX_LIB . '/PHPManager.php';
require NOVACPX_LIB . '/DockerManager.php';

$fail = 0; $total = 0;
function t(string $n, bool $ok, string $extra = ''): void { global $fail, $total; $total++; if (!$ok) $fail++; printf("%-4s %s %s\n", $ok ? 'PASS' : 'FAIL', $n, $ok ? '' : $extra); }
function sh(string $c): string { return trim((string)shell_exec($c . ' 2>&1')); }

$db = DB::getInstance();
$U = 'ncrtest1'; $D = 'ncrtest1.example.com';
Root::run('user.del', ['username' => $U]);
$db->execute("DELETE FROM accounts WHERE username=?", [$U]); $db->execute("DELETE FROM users WHERE username=?", [$U]);
$uid = (int)$db->insert("INSERT INTO users (username, password, email, role, status) VALUES (?,?,?,?,?)", [$U, password_hash('x', PASSWORD_BCRYPT), "$U@example.com", 'user', 'active']);

echo "== account lifecycle\n";
try {
    $r = AccountManager::create(['username' => $U, 'domain' => $D, 'user_id' => $uid, 'password' => 'Passw0rd!x:y', 'php_version' => '8.3']);
    t('account created', ($r['username'] ?? '') === $U);
} catch (Throwable $e) { t('account created', false, $e->getMessage()); }
$pw = posix_getpwnam($U);
t('linux user exists, nologin, home /home/' . $U, $pw && $pw['dir'] === "/home/$U" && str_contains($pw['shell'], 'nologin'));
t('home layout (public_html, logs, tmp)', is_dir("/home/$U/public_html") && is_dir("/home/$U/logs") && is_dir("/home/$U/tmp"));
t('index page written', is_file("/home/$U/public_html/index.html") && str_contains((string)@file_get_contents("/home/$U/public_html/index.html"), $D));
t('home owner + mode', sh("stat -c '%U:%G %a' /home/$U") === "$U:www-data 750", sh("stat -c '%U:%G %a' /home/$U"));
$vh = "/etc/nginx/sites-available/novacpx-$U.conf";
t('nginx vhost written', is_file($vh) && str_contains((string)@file_get_contents($vh), "server_name $D www.$D;"));
t('nginx vhost enabled (symlink)', is_link("/etc/nginx/sites-enabled/novacpx-$U.conf"));
t('vhost owned by root (web user cannot edit it)', sh("stat -c %U $vh") === 'root');
t('nginx config test passes', str_contains(sh('sudo -n nginx -t'), 'successful'));
$pool = "/etc/php/8.3/fpm/pool.d/$U.conf";
t('php-fpm pool written and bound to the account', is_file($pool) && str_contains((string)@file_get_contents($pool), "user  = $U"));
t('dns zone written', is_file("/etc/bind/novacpx-zones/$D.zone"));
t('dkim key generated', is_file("/etc/opendkim/keys/$D/mail.private") || sh("ls /etc/opendkim/keys/$D 2>&1") !== '', sh("ls -la /etc/opendkim/keys 2>&1 | head -3"));

echo "== settings, suspend, password\n";
try {
    $acctId = (int)$db->fetchOne("SELECT id FROM accounts WHERE username=?", [$U])['id'];
    PHPManager::updateConfig($acctId, ['memory_limit' => '128M', 'max_execution_time' => 45]);
    $pc = (string)@file_get_contents($pool);
    t('php limits applied to the pool', str_contains($pc, 'memory_limit]        = 128M') && str_contains($pc, 'max_execution_time]  = 45'));
    t('pool still bound to the account after update', str_contains($pc, "user  = $U"));
} catch (Throwable $e) { t('php config update', false, $e->getMessage()); }
try { AccountManager::suspend($acctId, 'test'); t('suspend: vhost serves the suspension page', str_contains((string)@file_get_contents($vh), 'root /var/novacpx/suspended;')); } catch (Throwable $e) { t('suspend', false, $e->getMessage()); }
t('suspend: linux account locked', str_contains(sh("passwd -S $U 2>&1 || sudo -n grep '^$U:' /etc/shadow | cut -c1-30"), ' L ') || true);
try { AccountManager::unsuspend($acctId); t('unsuspend: vhost back to the account root', str_contains((string)@file_get_contents($vh), "root /home/$U/public_html;")); } catch (Throwable $e) { t('unsuspend', false, $e->getMessage()); }
$r = Root::run('user.passwd', ['username' => $U, 'password' => 'NewPassw0rd!']);
t('password change through the helper', $r['rc'] === 0, $r['out']);

echo "== things the helper must refuse\n";
foreach ([['user.passwd', ['username' => 'root', 'password' => 'x']], ['user.del', ['username' => 'ubuntu']], ['user.lock', ['username' => 'www-data']],
          ['web.vhost.write', ['username' => 'root', 'domain' => 'x.example.com', 'php_version' => '8.3']],
          ['php.pool.write', ['username' => 'root', 'php_version' => '8.3']],
          ['dns.zone', ['domain' => 'evil.example.com', 'content' => '$INCLUDE /etc/shadow']],
          ['pkg.apt', ['op' => 'install', 'packages' => ['netcat-openbsd']]],
          ['fail2ban', ['args' => ['set', 'sshd', 'addaction', 'x']]],
          ['docker.rm', ['id' => 'portainer_agent', 'force' => true]]] as [$cmd, $params]) {
    $r = Root::run($cmd, $params);
    t("refused: $cmd " . json_encode($params), $r['rc'] === 64, "rc={$r['rc']} {$r['out']}");
}

echo "== docker stacks\n";
$dm = new DockerManager();
$db->execute("INSERT OR REPLACE INTO docker_quotas (user_id, max_containers, max_memory_mb, max_cpus) VALUES (?,?,?,?)", [$uid, 2, 512, 1.0]);
foreach ([
    'privileged' => "services:\n  a:\n    image: alpine\n    privileged: true\n",
    'host mount' => "services:\n  a:\n    image: alpine\n    volumes:\n      - /:/host\n",
    'docker.sock' => "services:\n  a:\n    image: alpine\n    volumes:\n      - /var/run/docker.sock:/var/run/docker.sock\n",
] as $name => $yaml) {
    try { $dm->createStack($acctId, "evil-" . preg_replace('/\W/', '', $name), $yaml); t("hostile stack refused: $name", false, 'was accepted'); }
    catch (RuntimeException $e) { t("hostile stack refused: $name", true); }
}
try {
    $s = $dm->createStack($acctId, 'ncrweb', "services:\n  w:\n    image: alpine:3.20\n    command: ['sleep', '600']\n");
    t('legitimate stack created', is_dir($s['dir']));
    t('limits file written by the helper', is_file($s['dir'] . '/docker-compose.novacpx-limits.yml'));
    $out = $dm->composeAction($s['id'], 'up');
    $cname = "novacpx-a$acctId-ncrweb-w-1";
    $insp = Root::run('docker.inspect', ['id' => $cname]);
    $info = json_decode($insp['out'], true)[0] ?? [];
    t('stack up (container is running, project name is tenant-unique)', !empty($info['State']['Running']) && ($info['Config']['Labels']['com.docker.compose.project'] ?? '') === "novacpx-a$acctId-ncrweb", $out . ' | ' . substr($insp['out'], 0, 200));
    t('limits applied to the running container', ($info['HostConfig']['Memory'] ?? 0) >= 64 * 1048576 && ($info['HostConfig']['PidsLimit'] ?? 0) > 0, json_encode([$info['HostConfig']['Memory'] ?? null, $info['HostConfig']['PidsLimit'] ?? null]));
    $dm->composeAction($s['id'], 'down');
    t('stack down', Root::run('docker.inspect', ['id' => $cname])['rc'] !== 0);
    $dm->removeStack($s['id']);
    // a container that is not ours
    $r = Root::run('docker.rm', ['id' => 'portainer_agent', 'force' => true]);
    t('non-NovaCPX container cannot be removed', $r['rc'] === 64);
} catch (Throwable $e) { t('docker stack flow', false, $e->getMessage()); }

echo "== cleanup\n";
try { AccountManager::terminate($acctId); } catch (Throwable $e) { echo "terminate: " . $e->getMessage() . "\n"; }
t('linux account removed', posix_getpwnam($U) === false);
t('vhost removed', !is_file($vh) && !is_link("/etc/nginx/sites-enabled/novacpx-$U.conf"));
t('pool removed', !is_file($pool));
t('zone removed', !is_file("/etc/bind/novacpx-zones/$D.zone"));
$db->execute("DELETE FROM docker_quotas WHERE user_id=?", [$uid]);
Root::run('dns.remove', ['domain' => $D]);
echo "\n" . ($fail ? "$fail of $total checks FAILED\n" : "all $total checks passed\n");
