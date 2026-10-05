<?php
// Copyright (c) 2026 TomTom Enterprises. Licensed under the MIT License (see LICENSE).
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }   // command line only
/**
 * Offline checks for deploy/novacpx-root (the privileged helper): every validator, the generated vhost / pool text and
 * the zone-file filter. Nothing is executed as root and nothing on disk is changed.
 *   php tools/test-root-helper.php
 */
define('NOVACPX_ROOT_LIB', 1);
require __DIR__ . '/../deploy/novacpx-root';

$fail = 0; $total = 0;
function refused(callable $f): bool { try { $f(); return false; } catch (NcrRefused) { return true; } }
function t(string $name, bool $ok): void { global $fail, $total; $total++; if (!$ok) $fail++; printf("%-4s %s\n", $ok ? 'PASS' : 'FAIL', $name); }

echo "== names and paths\n";
foreach (['alice', 'a1_b2', 'web_site9'] as $u) t("username ok: $u", v_username($u) === $u);
foreach (['', 'A', 'root;id', '../x', 'a b', '1abc', str_repeat('a', 40), "a\nb", 'x$(id)', 'a-b'] as $u) t('username refused: ' . json_encode($u), refused(fn() => v_username($u)));
foreach (['example.com', 'a-b.example.co.uk', 'sub.domain.org'] as $d) t("domain ok: $d", v_domain($d) === $d);
foreach (['', 'localhost', 'a..b.com', '-a.com', 'a.com;rm', "a.com\nb", 'a b.com', 'x.c', str_repeat('a', 70) . '.com', '../etc/passwd', 'A.COM'] as $d) t('domain refused: ' . json_encode($d), refused(fn() => v_domain($d)));
t('php version ok', v_phpver('8.3') === '8.3');
foreach (['8.3;id', '9.9', '../8.3', 8.3] as $v) t('php version refused: ' . json_encode($v), refused(fn() => v_phpver($v)));
foreach (['root', 'daemon', 'www-data', 'ubuntu', 'nobody', 'syslog'] as $u) t("managed account refused: $u", refused(fn() => v_managed_user($u)));
t('missing account refused', refused(fn() => v_managed_user('zz_nobody_here')));
t('bad container ref refused', refused(fn() => v_container_ref('x;id')) && refused(fn() => v_container_ref('-f')) && refused(fn() => v_container_ref('a b')));
t('container ref ok', v_container_ref('novacpx-user-app') === 'novacpx-user-app' && v_container_ref(str_repeat('a1', 32)) !== '');
foreach (['/', '/etc', '/opt/novacpx/docker-apps', '/opt/novacpx/docker-apps/admin', '/opt/novacpx/docker-apps/../../etc/x', '/opt/novacpx/docker-apps/account-1/../../x', '/opt/novacpx/docker-apps/x/y',
          '/opt/novacpx/docker-apps/account-1/a b', '/opt/novacpx/docker-apps/account-1/a/b', '/home/x/stack'] as $d) t("stack dir refused: $d", refused(fn() => v_stack_dir($d)));
t('project name', v_project('novacpx-a2-ghost') === 'novacpx-a2-ghost' && refused(fn() => v_project('other-a2')) && refused(fn() => v_project('novacpx-a2;x')));
t('image name', v_image('nginx:1.25-alpine') !== '' && refused(fn() => v_image('-v /:/h x')) && refused(fn() => v_image('a b')) && refused(fn() => v_image("a\nb")));
t('ip', v_ip('10.0.0.1') !== '' && refused(fn() => v_ip('1.2.3.4;x')) && refused(fn() => v_ip('999.1.1.1')));
t('int bounds', v_int('5', 1, 10, 'x') === 5 && refused(fn() => v_int('11', 1, 10, 'x')) && refused(fn() => v_int('-1', 0, 10, 'x')) && refused(fn() => v_int('1e3', 0, 5000, 'x')) && refused(fn() => v_int(null, 0, 1, 'x')));

echo "== packages\n";
foreach (['php8.3-gd', 'rspamd', 'dovecot-imapd', 'certbot', 'postfix'] as $x) t("package ok: $x", v_package($x) === $x);
foreach (['bash', 'sudo', 'openssh-server', 'php8.3-gd;id', '../x', '-oAPT::Update::Pre-Invoke::=id', 'coreutils', 'systemd', 'docker-ce', 'netcat-openbsd', 'php8.3'] as $x) t("package refused: $x", refused(fn() => v_package($x)));

echo "== generated web configuration\n";
$n = ncr_vhost_text('nginx', 'alice', 'example.com', '8.3', false, 80, false);
t('nginx vhost has server name and root', str_contains($n, 'server_name example.com www.example.com;') && str_contains($n, 'root /home/alice/public_html;'));
t('nginx vhost uses that account\'s socket', str_contains($n, 'unix:/run/php/php8.3-fpm-alice.sock'));
t('nginx suspended vhost serves the suspension page', str_contains(ncr_vhost_text('nginx', 'alice', 'example.com', '8.3', false, 80, true), 'root /var/novacpx/suspended;'));
t('nginx ssl vhost points at the account cert dir', str_contains(ncr_vhost_text('nginx', 'alice', 'example.com', '8.3', true, 80, false), 'ssl_certificate /etc/novacpx/ssl/accounts/alice/cert.pem;'));
$a = ncr_vhost_text('apache', 'alice', 'example.com', '8.3', true, 8090, false);
t('apache ssl vhost + redirect', str_contains($a, '<VirtualHost *:443>') && str_contains($a, '<VirtualHost *:8090>') && str_contains($a, 'Redirect permanent / https://example.com/'));
t('vhost text never contains template leftovers', !str_contains($n . $a, '{$'));
$pool = ncr_pool_text('alice', '8.3', ['memory_limit' => '512M', 'max_execution_time' => 60, 'upload_max_filesize' => '10M']);
t('pool is bound to the account', str_contains($pool, "[alice]\nuser  = alice\ngroup = www-data") && str_contains($pool, 'open_basedir]  = /home/alice/:/tmp/'));
t('pool applies the settings', str_contains($pool, 'memory_limit]        = 512M') && str_contains($pool, 'max_execution_time]  = 60'));
$evil = ncr_pool_text('alice', '8.3', ['memory_limit' => "1G\nuser = root", 'upload_max_filesize' => 'x;y', 'post_max_size' => '64M']);
t('pool ignores malformed settings (no injected directives)', !str_contains($evil, 'user = root') && substr_count($evil, "user") === 1 && str_contains($evil, 'memory_limit]        = 256M'));
t('pool refuses a bad execution time', refused(fn() => ncr_pool_text('alice', '8.3', ['max_execution_time' => '30;x'])));

echo "== dns zone filter\n";
$zone = "\$TTL 3600\n@ IN SOA ns1.example.com. admin.example.com. (\n    2026100201 ; serial\n    3600       ; refresh\n    900        ; retry\n    604800     ; expire\n    300 ; minimum\n)\n\n@ IN NS ns1.example.com.\n@ IN NS ns2.example.com.\n\n@ 3600 IN A 1.2.3.4\nwww 3600 IN CNAME example.com.\n@ 3600 IN MX 10 mail.example.com.\n@ 3600 IN TXT \"v=spf1 mx a ~all\"\nmail._domainkey 300 IN TXT \"v=DKIM1; k=rsa; p=ABC+/=\"\n";
t('normal zone accepted', !refused(fn() => ncr_check_zone_text('example.com', $zone)));
t('own $ORIGIN accepted', !refused(fn() => ncr_check_zone_text('example.com', "\$ORIGIN example.com.\n" . $zone)));
t('foreign $ORIGIN refused', refused(fn() => ncr_check_zone_text('example.com', "\$ORIGIN evil.com.\n" . $zone)));
foreach (["\$INCLUDE /etc/shadow", "\$GENERATE 1-10 a\$ A 1.2.3.\$", "@ IN A 1.2.3.4\nfoo bar baz", "@ IN SOA a. b. (\n)\n; ok\n/etc/passwd", "x IN A 1.1.1.1\x01"] as $bad) {
    t('zone refused: ' . str_replace("\n", '\n', substr($bad, 0, 40)), refused(fn() => ncr_check_zone_text('example.com', $bad)));
}

echo "== requests that must be refused before anything runs\n";
$cases = [
    'user.add reserved name'      => fn() => cmd_user_add(['username' => 'root']),
    'user.add shell metachar'     => fn() => cmd_user_add(['username' => 'a;id']),
    'user.add ubuntu'             => fn() => cmd_user_add(['username' => 'ubuntu']),
    'user.passwd root'            => fn() => cmd_user_passwd(['username' => 'root', 'password' => 'x']),
    'user.del root'               => fn() => cmd_user_del(['username' => 'root']),
    'user.del www-data'           => fn() => cmd_user_del(['username' => 'www-data']),
    'home.init root'              => fn() => cmd_home_init(['username' => 'root']),
    'vhost for system user'       => fn() => cmd_web_vhost_write(['username' => 'daemon', 'domain' => 'a.com', 'php_version' => '8.3']),
    'vhost bad domain'            => fn() => cmd_web_vhost_write(['username' => 'zz_nobody_here', 'domain' => 'a.com;x', 'php_version' => '8.3']),
    'pool for system user'        => fn() => cmd_php_pool_write(['username' => 'root', 'php_version' => '8.3']),
    'apt: shell package'          => fn() => cmd_pkg_apt(['op' => 'install', 'packages' => ['bash']]),
    'apt: option injection'       => fn() => cmd_pkg_apt(['op' => 'install', 'packages' => ['-oAPT::Update::Pre-Invoke::=id']]),
    'apt: bad op'                 => fn() => cmd_pkg_apt(['op' => 'dist-upgrade']),
    'apt: too many'               => fn() => cmd_pkg_apt(['op' => 'install', 'packages' => array_fill(0, 11, 'rspamd')]),
    'pecl bad ext'                => fn() => cmd_php_pecl(['php_version' => '8.3', 'extension' => 'x;id']),
    'fail2ban set action'         => fn() => cmd_fail2ban(['args' => ['set', 'sshd', 'addaction', 'x']]),
    'fail2ban actionban'          => fn() => cmd_fail2ban(['args' => ['set', 'sshd', 'action', 'x', 'actionban', 'id']]),
    'fail2ban bad ip'             => fn() => cmd_fail2ban(['args' => ['set', 'sshd', 'banip', '1.2.3.4;id']]),
    'fail2ban start'              => fn() => cmd_fail2ban(['args' => ['start']]),
    'fail2ban bad jail'           => fn() => cmd_fail2ban(['args' => ['status', '../x']]),
    'fail2ban defaults bad ip'    => fn() => cmd_fail2ban_defaults(['ignoreip' => ['1.2.3.4', 'x; evil']]),
    'fail2ban defaults bantime'   => fn() => cmd_fail2ban_defaults(['bantime' => '10']),
    'docker run bad name'         => fn() => cmd_docker_run(['name' => 'evil', 'image' => 'nginx', 'memory_mb' => 64, 'cpus' => 1]),
    'docker run bad image'        => fn() => cmd_docker_run(['name' => 'novacpx-user-app', 'image' => '-v /:/h alpine', 'memory_mb' => 64, 'cpus' => 1]),
    'docker run low port'         => fn() => cmd_docker_run(['name' => 'novacpx-user-app', 'image' => 'nginx', 'memory_mb' => 64, 'cpus' => 1, 'ports' => ['22:22']]),
    'docker run bad env'          => fn() => cmd_docker_run(['name' => 'novacpx-user-app', 'image' => 'nginx', 'memory_mb' => 64, 'cpus' => 1, 'env' => ['x y' => '1']]),
    'docker run zero memory'      => fn() => cmd_docker_run(['name' => 'novacpx-user-app', 'image' => 'nginx', 'memory_mb' => 0, 'cpus' => 1]),
    'docker action bad'           => fn() => cmd_docker_action(['id' => 'abc123abc123', 'action' => 'exec']),
    'docker list bad'             => fn() => cmd_docker_list(['what' => 'secrets']),
    'docker rmi option'           => fn() => cmd_docker_rmi(['image' => '-f']),
    'stack prepare traversal'     => fn() => cmd_docker_stack_prepare(['dir' => '/opt/novacpx/docker-apps/account-1/../../../etc']),
    'stack prepare outside'       => fn() => cmd_docker_stack_prepare(['dir' => '/etc/cron.d/x']),
    'compose bad action'          => fn() => cmd_docker_compose(['dir' => '/opt/novacpx/docker-apps/admin/x', 'action' => 'exec']),
    'compose outside dir'         => fn() => cmd_docker_compose(['dir' => '/tmp/x', 'action' => 'up']),
    'compose down bad project'    => fn() => cmd_docker_compose_down_project(['project' => 'portainer']),
    'mail bad mailbox'            => fn() => cmd_mail_sync(['mailboxes' => [['email' => 'a@b.com', 'username' => '../x']]]),
    'mail bad alias'              => fn() => cmd_mail_sync(['aliases' => [['source' => "a@b.com\nx", 'destination' => 'c@d.com']]]),
    'dkim bad selector'           => fn() => cmd_dkim_genkey(['domain' => 'a.com', 'selector' => '../x']),
    'dkim bad domain'             => fn() => cmd_dkim_genkey(['domain' => '../../etc', 'selector' => 'mail']),
    'dns bad zone'                => fn() => cmd_dns_zone(['domain' => 'a.com', 'content' => '$INCLUDE /etc/shadow']),
    'dns bad domain'              => fn() => cmd_dns_zone(['domain' => '../x', 'content' => '']),
    'dns remove bad domain'       => fn() => cmd_dns_remove(['domain' => '../../etc/passwd']),
    'proxy bad upstream'          => fn() => cmd_proxy_sync(['hosts' => [['domain' => 'a.com', 'upstream' => 'http://x; include /etc/passwd']]]),
    'apache port bad'             => fn() => cmd_web_apache_port(['from' => 'a', 'to' => 80]),
    'dbengine bad engine'         => fn() => cmd_dbengine(['engine' => 'sqlite;id', 'action' => 'install']),
    'dbengine bad action'         => fn() => cmd_dbengine(['engine' => 'mysql', 'action' => 'purge']),
    'db tool bad tool'            => fn() => cmd_db_tool(['tool' => 'bash', 'action' => 'install']),
    'db tool bad action'          => fn() => cmd_db_tool(['tool' => 'adminer', 'action' => 'exec']),
    'proxy local bad action'      => fn() => cmd_proxy_local(['action' => 'format']),
    'proxy local low port'        => fn() => cmd_proxy_local(['action' => 'enable', 'apache_port' => 80]),
    'ssl store garbage'           => fn() => cmd_web_ssl_store(['username' => 'zz_nobody_here', 'cert' => 'x', 'key' => 'y']),
];
foreach ($cases as $name => $f) t("refused: $name", refused($f));
t('every command is registered to a real function', count(array_filter(NCR_COMMANDS, 'function_exists')) === count(NCR_COMMANDS));

echo "\n" . ($fail ? "$fail of $total checks FAILED\n" : "all $total checks passed\n");
exit($fail ? 1 : 0);
