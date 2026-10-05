<?php
// Copyright (c) 2026 TomTom Enterprises. Licensed under the MIT License (see LICENSE).
/**
 * Offline test for ncr_stack_policy() and the catalog template generator.
 * Needs only php-cli and docker compose (it asks `docker compose config` to normalise each file); no panel, DB or containers.
 *   php tools/test-stack-policy.php [path/to/DockerManager.php]
 * Exit code 0 = every expectation held.
 */
$lib = $argv[1] ?? __DIR__ . '/../panel/lib/DockerManager.php';
require_once $lib;
define('NOVACPX_ROOT_LIB', 1);
require_once __DIR__ . '/../deploy/novacpx-root';

$quota = ['max_containers' => 3, 'max_memory_mb' => 768, 'max_cpus' => 1.5];
$fail = 0; $total = 0;

function check(string $name, string $yaml, array $quota, bool $expectOk, string $mustMention = ''): void {
    global $fail, $total;
    $total++;
    $dir = sys_get_temp_dir() . '/ncpx-policy-' . bin2hex(random_bytes(4));
    mkdir($dir, 0700, true);
    file_put_contents("$dir/docker-compose.yml", $yaml);
    $ok = true; $msg = '';
    try { ncr_stack_policy("$dir/docker-compose.yml", $dir, $quota); }
    catch (RuntimeException $e) { $ok = false; $msg = $e->getMessage(); }
    shell_exec('rm -rf ' . escapeshellarg($dir));
    $good = ($ok === $expectOk) && ($expectOk || $mustMention === '' || stripos($msg, $mustMention) !== false);
    if (!$good) $fail++;
    printf("%-4s %-46s %s\n", $good ? 'PASS' : 'FAIL', $name, $ok ? 'allowed' : 'refused: ' . substr($msg, 0, 70));
}

echo "== legitimate stacks (must be allowed)\n";
check('web + db, named volumes',
    "services:\n  web:\n    image: wordpress:latest\n    environment:\n      A: b\n    volumes:\n      - wp:/var/www/html\n  db:\n    image: mariadb:10.11\n    volumes:\n      - db:/var/lib/mysql\nvolumes:\n  wp:\n  db:\n", $quota, true);
check('nginx, high port, bind inside stack dir',
    "services:\n  n:\n    image: nginx:alpine\n    ports: ['18080:80']\n    volumes:\n      - ./html:/usr/share/nginx/html:ro\n", $quota, true);
check('container-only port', "services:\n  a:\n    image: node:20-alpine\n    ports:\n      - '3000'\n", $quota, true);
check('user + read_only + tmpfs', "services:\n  a:\n    image: alpine\n    user: '1000'\n    read_only: true\n    tmpfs: [/tmp]\n", $quota, true);

echo "== hostile stacks (must be refused)\n";
check('privileged: true', "services:\n  a:\n    image: alpine\n    privileged: true\n", $quota, false, 'privileged');
check('flow-style privileged', "services: {a: {image: alpine, privileged: true}}\n", $quota, false, 'privileged');
check('bind-mount host root', "services:\n  a:\n    image: alpine\n    volumes:\n      - /:/host\n", $quota, false, 'outside');
check('docker.sock', "services:\n  a:\n    image: alpine\n    volumes:\n      - /var/run/docker.sock:/var/run/docker.sock\n", $quota, false, 'outside');
check('relative escape ../../', "services:\n  a:\n    image: alpine\n    volumes:\n      - ../../etc:/x\n", $quota, false, 'outside');
check('network_mode: host', "services:\n  a:\n    image: alpine\n    network_mode: host\n", $quota, false, 'network_mode');
check('pid: host', "services:\n  a:\n    image: alpine\n    pid: host\n", $quota, false, 'pid');
check('ipc: host', "services:\n  a:\n    image: alpine\n    ipc: host\n", $quota, false, 'ipc');
check('cap_add SYS_ADMIN', "services:\n  a:\n    image: alpine\n    cap_add: [SYS_ADMIN]\n", $quota, false, 'cap_add');
check('devices', "services:\n  a:\n    image: alpine\n    devices: ['/dev/sda:/dev/sda']\n", $quota, false, 'devices');
check('security_opt seccomp unconfined', "services:\n  a:\n    image: alpine\n    security_opt: ['seccomp:unconfined']\n", $quota, false, 'security_opt');
check('sysctls', "services:\n  a:\n    image: alpine\n    sysctls:\n      net.ipv4.ip_forward: 1\n", $quota, false, 'sysctls');
check('published port 22', "services:\n  a:\n    image: alpine\n    ports: ['22:22']\n", $quota, false, 'port');
check('published port 80', "services:\n  a:\n    image: alpine\n    ports: ['80:80']\n", $quota, false, 'port');
check('published port 3306 (host MySQL)', "services:\n  a:\n    image: alpine\n    ports: ['3306:3306']\n", $quota, false, 'port');
check('include: file', "include:\n  - /etc/passwd\nservices:\n  a:\n    image: alpine\n", $quota, false, 'include');
check('env_file: /etc/shadow', "services:\n  a:\n    image: alpine\n    env_file: /etc/shadow\n", $quota, false, 'env_file');
check('extends', "services:\n  a:\n    extends:\n      file: /x.yml\n      service: y\n", $quota, false, 'extends');
check('build context', "services:\n  a:\n    build: .\n", $quota, false, 'build');
check('${HOME} interpolation', "services:\n  a:\n    image: alpine\n    environment:\n      H: \${HOME}\n", $quota, false, 'substitution');
check('volume driver_opts bind /', "services:\n  a:\n    image: alpine\n    volumes:\n      - vol1:/x\nvolumes:\n  vol1:\n    driver_opts:\n      type: none\n      o: bind\n      device: /\n", $quota, false, 'Volume');
check('external network', "services:\n  a:\n    image: alpine\n    networks: [ext]\nnetworks:\n  ext:\n    external: true\n", $quota, false, 'Network');
check('external volume', "services:\n  a:\n    image: alpine\n    volumes:\n      - vol1:/x\nvolumes:\n  vol1:\n    external: true\n", $quota, false, 'Volume');
check('more services than quota', "services:\n  a: {image: alpine}\n  b: {image: alpine}\n  c: {image: alpine}\n  d: {image: alpine}\n", $quota, false, 'quota');
check('replicas 5', "services:\n  a:\n    image: alpine\n    deploy:\n      replicas: 5\n", $quota, false, 'replicas');
check('extra_hosts host-gateway', "services:\n  a:\n    image: alpine\n    extra_hosts: ['h:host-gateway']\n", $quota, false, 'extra_hosts');
check('image with a space', "services:\n  a:\n    image: 'alpine --privileged'\n", $quota, false, 'image');
check('not a compose file', "this: is\nnot: compose\n", $quota, false, 'Invalid');

echo "== catalog templates (generated with normal values; every one must parse, and we list which the policy blocks)\n";
$ref = new ReflectionClass('DockerManager');
$dm  = $ref->newInstanceWithoutConstructor();
$gen = $ref->getMethod('generateComposeYaml'); $gen->setAccessible(true);
$cat = $ref->getMethod('getCatalog');
$apps = array_keys($cat->invoke(null));
$ok = 'Str0ng.Pass_word-123';
$passed = []; $blocked = []; $broken = [];
foreach ($apps as $app) {
    try { $yaml = $gen->invoke($dm, $app, 'example.com', ['db_pass' => $ok, 'admin_pass' => $ok, 'admin_user' => 'admin']); }
    catch (Throwable $e) { $broken[] = "$app (generator: " . $e->getMessage() . ")"; continue; }
    $dir = sys_get_temp_dir() . '/ncpx-cat-' . bin2hex(random_bytes(4)); mkdir($dir, 0700, true);
    file_put_contents("$dir/docker-compose.yml", $yaml);
    try { ncr_stack_policy("$dir/docker-compose.yml", $dir, ['max_containers' => 10, 'max_memory_mb' => 4096, 'max_cpus' => 4]); $passed[] = $app; }
    catch (RuntimeException $e) {
        if (stripos($e->getMessage(), 'Invalid compose') === 0) $broken[] = "$app ({$e->getMessage()})";
        else $blocked[] = "$app: " . substr($e->getMessage(), 0, 60);
    }
    shell_exec('rm -rf ' . escapeshellarg($dir));
}
printf("allowed %d of %d apps; blocked by policy %d; unparseable %d\n", count($passed), count($apps), count($blocked), count($broken));
foreach ($blocked as $b) echo "  blocked  $b\n";
foreach ($broken as $b) echo "  BROKEN   $b\n";
$total++; if ($broken) $fail++;

echo "== injection through catalog parameters (the generator must refuse, never emit extra YAML)\n";
foreach ([['db_pass', "x\n    privileged: true"], ['admin_pass', "a b"], ['admin_user', "x'\"y"], ['db_pass', 'p#ss'], ['db_pass', '$HOME'], ['db_pass', 'a:b'], ['image', 'node:20 --privileged']] as [$k, $v]) {
    $total++;
    try { $gen->invoke($dm, $k === 'image' ? 'nodejs' : 'wordpress', 'example.com', [$k => $v]); $good = false; $why = 'ACCEPTED'; }
    catch (RuntimeException $e) { $good = true; $why = 'refused'; }
    if (!$good) $fail++;
    printf("%-4s %-46s %s\n", $good ? 'PASS' : 'FAIL', "param $k = " . json_encode($v), $why);
}
echo "\n" . ($fail ? "$fail of $total checks FAILED\n" : "all $total checks passed\n");
exit($fail ? 1 : 0);
