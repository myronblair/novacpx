<?php
/**
 * DockerManager — Docker Engine install, container lifecycle, compose stacks, app catalog
 */
class DockerManager {

    private DB $db;
    private string $appsDir = '/opt/novacpx/docker-apps';

    public function __construct() {
        $this->db = DB::getInstance();
    }

    // ── Engine ────────────────────────────────────────────────────────────────

    public function isInstalled(): bool {
        return is_executable('/usr/bin/docker') || is_executable('/usr/local/bin/docker');
    }

    public function install(): string {
        if ($this->isInstalled()) return 'Docker is already installed';
        // Write install script to /tmp then run it via sudo bash (no interactive terminal needed)
        $script = <<<'SH'
#!/bin/bash
set -e
export DEBIAN_FRONTEND=noninteractive
curl -fsSL https://get.docker.com -o /tmp/ncpx-get-docker.sh
bash /tmp/ncpx-get-docker.sh
rm -f /tmp/ncpx-get-docker.sh
systemctl enable --now docker
usermod -aG docker www-data
SH;
        $scriptFile = '/tmp/ncpx-docker-install.sh';
        file_put_contents($scriptFile, $script);
        chmod($scriptFile, 0700);
        $out = [];
        exec('sudo bash ' . escapeshellarg($scriptFile) . ' 2>&1', $out, $rc);
        @unlink($scriptFile);
        if ($rc !== 0) throw new RuntimeException("Docker install failed: " . implode("\n", $out));
        novacpx_log('info', 'DockerManager: Docker CE installed');
        return 'Docker installed successfully';
    }

    public function engineStatus(): array {
        if (!$this->isInstalled()) return ['installed' => false];
        $version = trim(shell_exec('sudo docker version --format "{{.Server.Version}}" 2>/dev/null') ?? '');
        $running = !empty($version);
        $df = [];
        if ($running) {
            $raw = shell_exec('sudo docker system df --format json 2>/dev/null') ?? '';
            foreach (explode("\n", trim($raw)) as $line) {
                $obj = json_decode($line, true);
                if ($obj) $df[] = $obj;
            }
        }
        return [
            'installed' => true,
            'running'   => $running,
            'version'   => $version,
            'disk'      => $df,
        ];
    }

    public function systemPrune(bool $volumes = false): string {
        $flag = $volumes ? '--volumes' : '';
        $out = shell_exec("sudo docker system prune -f {$flag} 2>&1") ?? '';
        novacpx_log('info', 'DockerManager: system prune');
        return trim($out);
    }

    // ── Containers ────────────────────────────────────────────────────────────

    public function listContainers(?int $accountId = null): array {
        $query = "SELECT * FROM docker_containers";
        $params = [];
        if ($accountId) { $query .= " WHERE account_id = ?"; $params[] = $accountId; }
        $query .= " ORDER BY created_at DESC";
        $rows = $this->db->fetchAll($query, $params);
        // Sync live status from docker daemon
        foreach ($rows as &$row) {
            if ($row['container_id']) {
                $st = trim(shell_exec("sudo docker inspect --format='{{.State.Status}}' " . escapeshellarg($row['container_id']) . " 2>/dev/null") ?? '');
                if ($st && $row['status'] !== $st) {
                    $this->db->execute("UPDATE docker_containers SET status=? WHERE id=?", [$st, $row['id']]);
                    $row['status'] = $st;
                }
            }
        }
        return $rows;
    }

    public function containerAction(string $containerId, string $action): string {
        $this->validateContainerId($containerId);
        $allowed = ['start','stop','restart','pause','unpause','kill'];
        if (!in_array($action, $allowed)) throw new RuntimeException("Invalid action: $action");
        $out = shell_exec("sudo docker {$action} " . escapeshellarg($containerId) . " 2>&1") ?? '';
        $status = match($action) {
            'start','restart','unpause' => 'running',
            'stop','pause','kill'       => 'stopped',
            default                     => 'unknown',
        };
        $this->db->execute("UPDATE docker_containers SET status=? WHERE container_id=?", [$status, $containerId]);
        return trim($out);
    }

    public function removeContainer(string $containerId, bool $force = false): void {
        $this->validateContainerId($containerId);
        $flag = $force ? '-f' : '';
        shell_exec("sudo docker rm {$flag} " . escapeshellarg($containerId) . " 2>&1");
        $this->db->execute("DELETE FROM docker_containers WHERE container_id=?", [$containerId]);
    }

    public function containerLogs(string $containerId, int $lines = 100): string {
        $this->validateContainerId($containerId);
        return shell_exec("sudo docker logs --tail=" . (int)$lines . " " . escapeshellarg($containerId) . " 2>&1") ?? '';
    }

    public function containerInspect(string $containerId): array {
        $this->validateContainerId($containerId);
        $json = shell_exec("sudo docker inspect " . escapeshellarg($containerId) . " 2>&1") ?? '';
        $data = json_decode($json, true);
        return is_array($data) ? ($data[0] ?? []) : [];
    }

    public function runContainer(int $accountId, string $image, string $name, array $opts = []): array {
        $account = $this->db->fetchOne("SELECT a.username, u.id as user_id FROM accounts a JOIN users u ON u.id=a.user_id WHERE a.id=?", [$accountId]);
        if (!$account) throw new RuntimeException("Account not found");

        // Quota check
        $quota = $this->getQuota((int)$account['user_id']);
        $count = (int)$this->db->fetchOne("SELECT COUNT(*) as c FROM docker_containers WHERE account_id=?", [$accountId])['c'];
        if ($count >= $quota['max_containers']) throw new RuntimeException("Container quota exceeded ({$quota['max_containers']} max)");

        $memMb  = min((int)($opts['memory_mb'] ?? 256), $quota['max_memory_mb']);
        $cpus   = min((float)($opts['cpus'] ?? 0.5), (float)$quota['max_cpus']);
        $safeName = 'novacpx-' . $account['username'] . '-' . preg_replace('/[^a-z0-9_-]/', '', strtolower($name));

        $portArgs = '';
        if (!empty($opts['ports'])) {
            foreach ((array)$opts['ports'] as $p) {
                if (preg_match('/^\d+:\d+$/', $p)) $portArgs .= " -p " . escapeshellarg($p);
            }
        }
        $envArgs = '';
        if (!empty($opts['env'])) {
            foreach ((array)$opts['env'] as $k => $v) {
                if (preg_match('/^[A-Z_][A-Z0-9_]*$/', $k)) $envArgs .= " -e " . escapeshellarg("{$k}={$v}");
            }
        }

        $cmd = "sudo docker run -d --name " . escapeshellarg($safeName)
             . " --memory={$memMb}m --cpus={$cpus}"
             . " --restart=unless-stopped"
             . $portArgs . $envArgs
             . " " . escapeshellarg($image) . " 2>&1";

        $out = shell_exec($cmd) ?? '';
        $cid = trim($out);

        if (strlen($cid) !== 64 || !ctype_xdigit($cid)) {
            throw new RuntimeException("Container failed to start: $out");
        }

        $this->db->execute(
            "INSERT INTO docker_containers (account_id, container_id, name, image, status, ports, memory_mb, cpus) VALUES (?,?,?,?,'running',?,?,?)",
            [$accountId, $cid, $safeName, $image, json_encode($opts['ports'] ?? []), $memMb, $cpus]
        );
        novacpx_log('info', "DockerManager: container {$safeName} started for account {$accountId}");
        return ['container_id' => $cid, 'name' => $safeName];
    }

    // ── Images ────────────────────────────────────────────────────────────────

    public function listImages(): array {
        $out = shell_exec("sudo docker images --format '{{json .}}' 2>/dev/null") ?? '';
        $images = [];
        foreach (explode("\n", trim($out)) as $line) {
            $obj = json_decode($line, true);
            if ($obj) $images[] = $obj;
        }
        return $images;
    }

    public function pullImage(string $image): string {
        if (!preg_match('/^[a-zA-Z0-9._\-\/:@]+$/', $image)) throw new RuntimeException("Invalid image name");
        $out = shell_exec("sudo docker pull " . escapeshellarg($image) . " 2>&1") ?? '';
        novacpx_log('info', "DockerManager: pulled image {$image}");
        return trim($out);
    }

    public function removeImage(string $imageId): string {
        if (!preg_match('/^[a-zA-Z0-9:._\-\/]+$/', $imageId)) throw new RuntimeException("Invalid image ID");
        return trim(shell_exec("sudo docker rmi " . escapeshellarg($imageId) . " 2>&1") ?? '');
    }

    // ── Volumes & Networks ────────────────────────────────────────────────────

    public function listVolumes(): array {
        $out = shell_exec("sudo docker volume ls --format '{{json .}}' 2>/dev/null") ?? '';
        $vols = [];
        foreach (explode("\n", trim($out)) as $line) {
            $obj = json_decode($line, true);
            if ($obj) $vols[] = $obj;
        }
        return $vols;
    }

    public function listNetworks(): array {
        $out = shell_exec("sudo docker network ls --format '{{json .}}' 2>/dev/null") ?? '';
        $nets = [];
        foreach (explode("\n", trim($out)) as $line) {
            $obj = json_decode($line, true);
            if ($obj) $nets[] = $obj;
        }
        return $nets;
    }

    // ── Compose Stacks ────────────────────────────────────────────────────────

    public function composeAction(int $stackId, string $action): string {
        $stack = $this->db->fetchOne("SELECT * FROM docker_compose_stacks WHERE id=?", [$stackId]);
        if (!$stack) throw new RuntimeException("Stack not found");
        $dir = $stack['stack_dir'];
        if (!is_dir($dir)) throw new RuntimeException("Stack directory not found");

        $allowed = ['up','down','pull','logs'];
        if (!in_array($action, $allowed)) throw new RuntimeException("Invalid action");

        $cmd = match($action) {
            'up'   => "sudo docker compose -f " . escapeshellarg("{$dir}/docker-compose.yml") . " up -d 2>&1",
            'down' => "sudo docker compose -f " . escapeshellarg("{$dir}/docker-compose.yml") . " down 2>&1",
            'pull' => "sudo docker compose -f " . escapeshellarg("{$dir}/docker-compose.yml") . " pull 2>&1",
            'logs' => "sudo docker compose -f " . escapeshellarg("{$dir}/docker-compose.yml") . " logs --tail=100 2>&1",
        };
        $out = shell_exec($cmd) ?? '';
        $status = match($action) { 'up' => 'running', 'down' => 'stopped', default => $stack['status'] };
        $this->db->execute("UPDATE docker_compose_stacks SET status=? WHERE id=?", [$status, $stackId]);
        return trim($out);
    }

    public function createStack(?int $accountId, string $name, string $composeYaml): array {
        $safeName = preg_replace('/[^a-z0-9_-]/', '', strtolower($name));
        $dir = "{$this->appsDir}/" . ($accountId ? "account-{$accountId}" : 'admin') . "/{$safeName}";
        if (!is_dir($dir)) {
            shell_exec('sudo mkdir -p ' . escapeshellarg($dir) . ' 2>/dev/null');
            shell_exec('sudo chown www-data:www-data ' . escapeshellarg($dir) . ' 2>/dev/null');
            shell_exec('sudo chmod 750 ' . escapeshellarg($dir) . ' 2>/dev/null');
        }
        if (!is_dir($dir)) throw new RuntimeException("Failed to create stack directory: {$dir}");
        file_put_contents("{$dir}/docker-compose.yml", $composeYaml);
        $this->db->execute(
            "INSERT INTO docker_compose_stacks (account_id, name, stack_dir, compose_file, status) VALUES (?,?,?,?,'pending')",
            [$accountId, $safeName, $dir, $composeYaml]
        );
        $id = $this->db->fetchOne("SELECT LAST_INSERT_ID() as id")['id'];
        novacpx_log('info', "DockerManager: created stack {$safeName}");
        return ['id' => $id, 'dir' => $dir];
    }

    public function listStacks(?int $accountId = null): array {
        $q = "SELECT * FROM docker_compose_stacks";
        $p = [];
        if ($accountId !== null) { $q .= " WHERE account_id=?"; $p[] = $accountId; }
        $q .= " ORDER BY created_at DESC";
        return $this->db->fetchAll($q, $p);
    }

    public function removeStack(int $stackId): void {
        $stack = $this->db->fetchOne("SELECT * FROM docker_compose_stacks WHERE id=?", [$stackId]);
        if (!$stack) throw new RuntimeException("Stack not found");
        // Bring down first
        $dir = $stack['stack_dir'];
        if (is_dir($dir) && is_file("{$dir}/docker-compose.yml")) {
            shell_exec("sudo docker compose -f " . escapeshellarg("{$dir}/docker-compose.yml") . " down 2>&1");
        }
        $this->db->execute("DELETE FROM docker_compose_stacks WHERE id=?", [$stackId]);
    }

    // ── Quotas ────────────────────────────────────────────────────────────────

    public function getQuota(int $userId): array {
        $q = $this->db->fetchOne("SELECT * FROM docker_quotas WHERE user_id=?", [$userId]);
        return $q ?: ['max_containers' => 2, 'max_memory_mb' => 512, 'max_cpus' => 1.0];
    }

    public function setQuota(int $userId, int $maxContainers, int $maxMemoryMb, float $maxCpus): void {
        $this->db->execute(
            "INSERT INTO docker_quotas (user_id, max_containers, max_memory_mb, max_cpus)
             VALUES (?,?,?,?)
             ON DUPLICATE KEY UPDATE max_containers=VALUES(max_containers), max_memory_mb=VALUES(max_memory_mb), max_cpus=VALUES(max_cpus)",
            [$userId, $maxContainers, $maxMemoryMb, $maxCpus]
        );
    }

    // ── App Catalog (#35) ─────────────────────────────────────────────────────

    public static function getCatalog(): array {
        return [
            'wordpress' => [
                'name'        => 'WordPress',
                'description' => 'WordPress + MariaDB + Nginx',
                'icon'        => 'W',
                'params'      => [
                    ['key' => 'domain',    'label' => 'Domain',         'type' => 'text',     'required' => true],
                    ['key' => 'db_pass',   'label' => 'DB Password',    'type' => 'password', 'required' => true],
                    ['key' => 'admin_user','label' => 'WP Admin User',  'type' => 'text',     'required' => true],
                    ['key' => 'admin_pass','label' => 'WP Admin Pass',  'type' => 'password', 'required' => true],
                    ['key' => 'admin_email','label' => 'WP Admin Email','type' => 'email',    'required' => true],
                ],
            ],
            'ghost' => [
                'name'        => 'Ghost',
                'description' => 'Ghost blog platform',
                'icon'        => 'G',
                'params'      => [
                    ['key' => 'domain',  'label' => 'Domain', 'type' => 'text', 'required' => true],
                    ['key' => 'db_pass', 'label' => 'DB Password', 'type' => 'password', 'required' => true],
                ],
            ],
            'nextcloud' => [
                'name'        => 'Nextcloud',
                'description' => 'Nextcloud file storage',
                'icon'        => 'N',
                'params'      => [
                    ['key' => 'domain',       'label' => 'Domain',        'type' => 'text',     'required' => true],
                    ['key' => 'admin_user',   'label' => 'Admin Username', 'type' => 'text',     'required' => true],
                    ['key' => 'admin_pass',   'label' => 'Admin Password', 'type' => 'password', 'required' => true],
                    ['key' => 'db_pass',      'label' => 'DB Password',    'type' => 'password', 'required' => true],
                ],
            ],
            'gitea' => [
                'name'        => 'Gitea',
                'description' => 'Self-hosted Git service',
                'icon'        => 'Git',
                'params'      => [
                    ['key' => 'domain',  'label' => 'Domain', 'type' => 'text', 'required' => true],
                    ['key' => 'db_pass', 'label' => 'DB Password', 'type' => 'password', 'required' => true],
                ],
            ],
            'matomo' => [
                'name'        => 'Matomo',
                'description' => 'Analytics platform',
                'icon'        => 'M',
                'params'      => [
                    ['key' => 'domain',  'label' => 'Domain', 'type' => 'text', 'required' => true],
                    ['key' => 'db_pass', 'label' => 'DB Password', 'type' => 'password', 'required' => true],
                ],
            ],
            'vaultwarden' => [
                'name'        => 'Vaultwarden',
                'description' => 'Bitwarden-compatible password manager',
                'icon'        => 'V',
                'params'      => [
                    ['key' => 'domain',     'label' => 'Domain',     'type' => 'text',     'required' => true],
                    ['key' => 'admin_token','label' => 'Admin Token', 'type' => 'password', 'required' => true],
                ],
            ],
            'nodejs' => [
                'name'        => 'Node.js App',
                'description' => 'Node.js application (auto-detects port)',
                'icon'        => 'JS',
                'params'      => [
                    ['key' => 'image',   'label' => 'Docker Image',  'type' => 'text',     'required' => true, 'placeholder' => 'node:20-alpine'],
                    ['key' => 'port',    'label' => 'App Port',      'type' => 'number',   'required' => true, 'placeholder' => '3000'],
                    ['key' => 'domain',  'label' => 'Domain',        'type' => 'text',     'required' => true],
                ],
            ],
            'flask' => [
                'name'        => 'Python / Flask',
                'description' => 'Python Flask application',
                'icon'        => 'Py',
                'params'      => [
                    ['key' => 'image',  'label' => 'Docker Image', 'type' => 'text',   'required' => true, 'placeholder' => 'python:3.12-slim'],
                    ['key' => 'port',   'label' => 'App Port',     'type' => 'number', 'required' => true, 'placeholder' => '5000'],
                    ['key' => 'domain', 'label' => 'Domain',       'type' => 'text',   'required' => true],
                ],
            ],
            'static' => [
                'name'        => 'Static Site',
                'description' => 'Static site served via Nginx',
                'icon'        => 'HTML',
                'params'      => [
                    ['key' => 'domain', 'label' => 'Domain', 'type' => 'text', 'required' => true],
                    ['key' => 'port',   'label' => 'Port',   'type' => 'number', 'required' => true, 'placeholder' => '8080'],
                ],
            ],
        ];
    }

    public function launchFromCatalog(int $accountId, string $appKey, array $params): array {
        $catalog = self::getCatalog();
        if (!isset($catalog[$appKey])) throw new RuntimeException("Unknown app: $appKey");

        $domain  = preg_replace('/[^a-z0-9.\-]/', '', strtolower($params['domain'] ?? ''));
        if (!$domain) throw new RuntimeException("domain is required");

        $yaml = $this->generateComposeYaml($appKey, $domain, $params);
        $stack = $this->createStack($accountId, "{$appKey}-{$domain}", $yaml);

        // Pull images and start stack in background (image pulls can take minutes)
        $dir    = $stack['dir'];
        $stackId = (int)$stack['id'];
        $logFile = escapeshellarg("/tmp/novacpx-stack-{$stackId}.log");
        $compose = escapeshellarg("{$dir}/docker-compose.yml");
        shell_exec("nohup sudo docker compose -f {$compose} up -d > {$logFile} 2>&1 &");
        $this->db->execute("UPDATE docker_compose_stacks SET status='starting' WHERE id=?", [$stackId]);
        novacpx_log('info', "DockerManager: launching {$appKey} for account {$accountId} on {$domain} (async)");
        return ['stack_id' => $stackId, 'dir' => $dir, 'output' => 'Launching in background — refresh in a moment to see status'];
    }

    private function generateComposeYaml(string $appKey, string $domain, array $p): string {
        $dbPass    = $p['db_pass']    ?? bin2hex(random_bytes(8));
        $adminPass = $p['admin_pass'] ?? bin2hex(random_bytes(8));
        $adminUser = $p['admin_user'] ?? 'admin';

        return match($appKey) {
            'wordpress' => "version: '3.8'\nservices:\n  db:\n    image: mariadb:10.11\n    restart: unless-stopped\n    environment:\n      MYSQL_ROOT_PASSWORD: {$dbPass}\n      MYSQL_DATABASE: wordpress\n      MYSQL_USER: wordpress\n      MYSQL_PASSWORD: {$dbPass}\n    volumes:\n      - db_data:/var/lib/mysql\n  wordpress:\n    image: wordpress:latest\n    restart: unless-stopped\n    depends_on: [db]\n    environment:\n      WORDPRESS_DB_HOST: db\n      WORDPRESS_DB_NAME: wordpress\n      WORDPRESS_DB_USER: wordpress\n      WORDPRESS_DB_PASSWORD: {$dbPass}\n    volumes:\n      - wp_data:/var/www/html\n    labels:\n      - 'novacpx.domain={$domain}'\nvolumes:\n  db_data:\n  wp_data:\n",

            'ghost'     => "version: '3.8'\nservices:\n  db:\n    image: mariadb:10.11\n    restart: unless-stopped\n    environment:\n      MYSQL_ROOT_PASSWORD: {$dbPass}\n      MYSQL_DATABASE: ghost\n      MYSQL_USER: ghost\n      MYSQL_PASSWORD: {$dbPass}\n    volumes:\n      - db_data:/var/lib/mysql\n  ghost:\n    image: ghost:5\n    restart: unless-stopped\n    depends_on: [db]\n    environment:\n      url: https://{$domain}\n      database__client: mysql\n      database__connection__host: db\n      database__connection__user: ghost\n      database__connection__password: {$dbPass}\n      database__connection__database: ghost\n    volumes:\n      - ghost_data:/var/lib/ghost/content\n    labels:\n      - 'novacpx.domain={$domain}'\nvolumes:\n  db_data:\n  ghost_data:\n",

            'nextcloud' => "version: '3.8'\nservices:\n  db:\n    image: mariadb:10.11\n    restart: unless-stopped\n    environment:\n      MYSQL_ROOT_PASSWORD: {$dbPass}\n      MYSQL_DATABASE: nextcloud\n      MYSQL_USER: nextcloud\n      MYSQL_PASSWORD: {$dbPass}\n    volumes:\n      - db_data:/var/lib/mysql\n  nextcloud:\n    image: nextcloud:latest\n    restart: unless-stopped\n    depends_on: [db]\n    environment:\n      NEXTCLOUD_ADMIN_USER: {$adminUser}\n      NEXTCLOUD_ADMIN_PASSWORD: {$adminPass}\n      MYSQL_HOST: db\n      MYSQL_DATABASE: nextcloud\n      MYSQL_USER: nextcloud\n      MYSQL_PASSWORD: {$dbPass}\n      NEXTCLOUD_TRUSTED_DOMAINS: {$domain}\n    volumes:\n      - nc_data:/var/www/html\n    labels:\n      - 'novacpx.domain={$domain}'\nvolumes:\n  db_data:\n  nc_data:\n",

            'gitea'     => "version: '3.8'\nservices:\n  db:\n    image: mariadb:10.11\n    restart: unless-stopped\n    environment:\n      MYSQL_ROOT_PASSWORD: {$dbPass}\n      MYSQL_DATABASE: gitea\n      MYSQL_USER: gitea\n      MYSQL_PASSWORD: {$dbPass}\n    volumes:\n      - db_data:/var/lib/mysql\n  gitea:\n    image: gitea/gitea:latest\n    restart: unless-stopped\n    depends_on: [db]\n    environment:\n      DB_TYPE: mysql\n      DB_HOST: db:3306\n      DB_NAME: gitea\n      DB_USER: gitea\n      DB_PASSWD: {$dbPass}\n    volumes:\n      - gitea_data:/data\n    labels:\n      - 'novacpx.domain={$domain}'\nvolumes:\n  db_data:\n  gitea_data:\n",

            'matomo'    => "version: '3.8'\nservices:\n  db:\n    image: mariadb:10.11\n    restart: unless-stopped\n    environment:\n      MYSQL_ROOT_PASSWORD: {$dbPass}\n      MYSQL_DATABASE: matomo\n      MYSQL_USER: matomo\n      MYSQL_PASSWORD: {$dbPass}\n    volumes:\n      - db_data:/var/lib/mysql\n  matomo:\n    image: matomo:latest\n    restart: unless-stopped\n    depends_on: [db]\n    environment:\n      MATOMO_DATABASE_HOST: db\n      MATOMO_DATABASE_DBNAME: matomo\n      MATOMO_DATABASE_USERNAME: matomo\n      MATOMO_DATABASE_PASSWORD: {$dbPass}\n    volumes:\n      - matomo_data:/var/www/html\n    labels:\n      - 'novacpx.domain={$domain}'\nvolumes:\n  db_data:\n  matomo_data:\n",

            'vaultwarden' => "version: '3.8'\nservices:\n  vaultwarden:\n    image: vaultwarden/server:latest\n    restart: unless-stopped\n    environment:\n      DOMAIN: https://{$domain}\n      ADMIN_TOKEN: " . ($p['admin_token'] ?? bin2hex(random_bytes(16))) . "\n      SIGNUPS_ALLOWED: 'false'\n    volumes:\n      - vw_data:/data\n    labels:\n      - 'novacpx.domain={$domain}'\nvolumes:\n  vw_data:\n",

            'nodejs', 'flask' => (function() use ($p, $domain, $appKey) {
                $image = $p['image'] ?? ($appKey === 'nodejs' ? 'node:20-alpine' : 'python:3.12-slim');
                $port  = (int)($p['port'] ?? 3000);
                return "version: '3.8'\nservices:\n  app:\n    image: " . $image . "\n    restart: unless-stopped\n    ports:\n      - '{$port}'\n    labels:\n      - 'novacpx.domain={$domain}'\n";
            })(),

            'static' => "version: '3.8'\nservices:\n  static:\n    image: nginx:alpine\n    restart: unless-stopped\n    ports:\n      - '" . ((int)($p['port'] ?? 8080)) . "'\n    volumes:\n      - ./public:/usr/share/nginx/html:ro\n    labels:\n      - 'novacpx.domain={$domain}'\n",

            default => throw new RuntimeException("No compose template for: $appKey"),
        };
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function validateContainerId(string $id): void {
        if (!preg_match('/^[a-f0-9]{8,64}$/', $id)) throw new RuntimeException("Invalid container ID");
    }
}
