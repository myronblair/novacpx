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
        $out = trim(shell_exec("sudo docker rmi " . escapeshellarg($imageId) . " 2>&1") ?? '');
        if (stripos($out, "Error") !== false || stripos($out, "conflict") !== false) {
            throw new \RuntimeException($out);
        }
        return $out;
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
            'uptime-kuma' => [
                'name'        => 'Uptime Kuma',
                'description' => 'Self-hosted uptime monitoring dashboard',
                'icon'        => 'UK',
                'params'      => [
                    ['key' => 'domain', 'label' => 'Domain', 'type' => 'text', 'required' => true],
                ],
            ],
            'portainer' => [
                'name'        => 'Portainer CE',
                'description' => 'Docker container management web UI',
                'icon'        => 'P',
                'params'      => [
                    ['key' => 'domain', 'label' => 'Domain', 'type' => 'text', 'required' => true],
                ],
            ],
            'minio' => [
                'name'        => 'MinIO',
                'description' => 'S3-compatible object storage',
                'icon'        => 'S3',
                'params'      => [
                    ['key' => 'domain',     'label' => 'Domain',     'type' => 'text',     'required' => true],
                    ['key' => 'access_key', 'label' => 'Access Key', 'type' => 'text',     'required' => true],
                    ['key' => 'secret_key', 'label' => 'Secret Key', 'type' => 'password', 'required' => true],
                ],
            ],
            'n8n' => [
                'name'        => 'n8n',
                'description' => 'Workflow automation (Zapier alternative)',
                'icon'        => 'n8n',
                'params'      => [
                    ['key' => 'domain', 'label' => 'Domain', 'type' => 'text', 'required' => true],
                ],
            ],
            'directus' => [
                'name'        => 'Directus',
                'description' => 'Headless CMS + data platform (Postgres)',
                'icon'        => 'Dir',
                'params'      => [
                    ['key' => 'domain',      'label' => 'Domain',         'type' => 'text',     'required' => true],
                    ['key' => 'admin_email', 'label' => 'Admin Email',    'type' => 'email',    'required' => true],
                    ['key' => 'admin_pass',  'label' => 'Admin Password', 'type' => 'password', 'required' => true],
                    ['key' => 'db_pass',     'label' => 'DB Password',    'type' => 'password', 'required' => true],
                ],
            ],
            'listmonk' => [
                'name'        => 'Listmonk',
                'description' => 'Newsletter & mailing list manager (Postgres)',
                'icon'        => 'LM',
                'params'      => [
                    ['key' => 'domain',  'label' => 'Domain',      'type' => 'text',     'required' => true],
                    ['key' => 'db_pass', 'label' => 'DB Password', 'type' => 'password', 'required' => true],
                ],
            ],
            'umami' => [
                'name'        => 'Umami',
                'description' => 'Privacy-focused web analytics (Postgres)',
                'icon'        => 'Um',
                'params'      => [
                    ['key' => 'domain',  'label' => 'Domain',      'type' => 'text',     'required' => true],
                    ['key' => 'db_pass', 'label' => 'DB Password', 'type' => 'password', 'required' => true],
                ],
            ],
            'photoprism' => [
                'name'        => 'PhotoPrism',
                'description' => 'AI-powered photo management (MariaDB)',
                'icon'        => 'PP',
                'params'      => [
                    ['key' => 'domain',     'label' => 'Domain',         'type' => 'text',     'required' => true],
                    ['key' => 'admin_pass', 'label' => 'Admin Password', 'type' => 'password', 'required' => true],
                    ['key' => 'db_pass',    'label' => 'DB Password',    'type' => 'password', 'required' => true],
                ],
            ],
            'meilisearch' => [
                'name'        => 'Meilisearch',
                'description' => 'Fast, typo-tolerant search engine',
                'icon'        => 'MS',
                'params'      => [
                    ['key' => 'domain',     'label' => 'Domain',     'type' => 'text',     'required' => true],
                    ['key' => 'master_key', 'label' => 'Master Key', 'type' => 'password', 'required' => true],
                ],
            ],
            'wikijs' => [
                'name'        => 'Wiki.js',
                'description' => 'Modern wiki platform (Postgres)',
                'icon'        => 'Wiki',
                'params'      => [
                    ['key' => 'domain',  'label' => 'Domain',      'type' => 'text',     'required' => true],
                    ['key' => 'db_pass', 'label' => 'DB Password', 'type' => 'password', 'required' => true],
                ],
            ],
            'vikunja' => [
                'name'        => 'Vikunja',
                'description' => 'Open-source task & project management (MariaDB)',
                'icon'        => 'Vik',
                'params'      => [
                    ['key' => 'domain',  'label' => 'Domain',      'type' => 'text',     'required' => true],
                    ['key' => 'db_pass', 'label' => 'DB Password', 'type' => 'password', 'required' => true],
                ],
            ],
            'mattermost' => [
                'name'        => 'Mattermost',
                'description' => 'Open-source team messaging, Slack alternative (Postgres)',
                'icon'        => 'MM',
                'params'      => [
                    ['key' => 'domain',  'label' => 'Domain',      'type' => 'text',     'required' => true],
                    ['key' => 'db_pass', 'label' => 'DB Password', 'type' => 'password', 'required' => true],
                ],
            ],
            // ── Media & Files ──────────────────────────────────────────────────────
            'jellyfin' => [
                'name'        => 'Jellyfin',
                'description' => 'Free media server — movies, TV & music',
                'icon'        => 'JF',
                'params'      => [
                    ['key' => 'domain', 'label' => 'Domain', 'type' => 'text', 'required' => true],
                ],
            ],
            'navidrome' => [
                'name'        => 'Navidrome',
                'description' => 'Music streaming server (Subsonic-compatible API)',
                'icon'        => 'Nav',
                'params'      => [
                    ['key' => 'domain', 'label' => 'Domain', 'type' => 'text', 'required' => true],
                ],
            ],
            'kavita' => [
                'name'        => 'Kavita',
                'description' => 'eBook, manga & comic server',
                'icon'        => 'Kav',
                'params'      => [
                    ['key' => 'domain', 'label' => 'Domain', 'type' => 'text', 'required' => true],
                ],
            ],
            'paperless-ngx' => [
                'name'        => 'Paperless-ngx',
                'description' => 'Document management & OCR system (Postgres + Redis)',
                'icon'        => 'Doc',
                'params'      => [
                    ['key' => 'domain',     'label' => 'Domain',         'type' => 'text',     'required' => true],
                    ['key' => 'admin_user', 'label' => 'Admin Username', 'type' => 'text',     'required' => true],
                    ['key' => 'admin_pass', 'label' => 'Admin Password', 'type' => 'password', 'required' => true],
                    ['key' => 'db_pass',    'label' => 'DB Password',    'type' => 'password', 'required' => true],
                ],
            ],
            'filebrowser' => [
                'name'        => 'File Browser',
                'description' => 'Web-based file manager for your server files',
                'icon'        => 'FB',
                'params'      => [
                    ['key' => 'domain', 'label' => 'Domain', 'type' => 'text', 'required' => true],
                ],
            ],
            'seafile' => [
                'name'        => 'Seafile',
                'description' => 'Dropbox-like file sync & share (MariaDB + Memcached)',
                'icon'        => 'Sea',
                'params'      => [
                    ['key' => 'domain',      'label' => 'Domain',         'type' => 'text',     'required' => true],
                    ['key' => 'admin_email', 'label' => 'Admin Email',    'type' => 'email',    'required' => true],
                    ['key' => 'admin_pass',  'label' => 'Admin Password', 'type' => 'password', 'required' => true],
                    ['key' => 'db_pass',     'label' => 'DB Password',    'type' => 'password', 'required' => true],
                ],
            ],
            'immich' => [
                'name'        => 'Immich',
                'description' => 'Google Photos alternative — photo & video backup (Postgres)',
                'icon'        => 'Imm',
                'params'      => [
                    ['key' => 'domain',  'label' => 'Domain',      'type' => 'text',     'required' => true],
                    ['key' => 'db_pass', 'label' => 'DB Password', 'type' => 'password', 'required' => true],
                ],
            ],
            // ── Monitoring & DevOps ────────────────────────────────────────────────
            'adminer' => [
                'name'        => 'Adminer',
                'description' => 'Lightweight multi-database admin UI (MySQL/PG/SQLite)',
                'icon'        => 'Adm',
                'params'      => [
                    ['key' => 'domain', 'label' => 'Domain', 'type' => 'text', 'required' => true],
                ],
            ],
            'grafana' => [
                'name'        => 'Grafana',
                'description' => 'Monitoring & observability dashboards',
                'icon'        => 'Grf',
                'params'      => [
                    ['key' => 'domain',     'label' => 'Domain',         'type' => 'text',     'required' => true],
                    ['key' => 'admin_user', 'label' => 'Admin Username', 'type' => 'text',     'required' => true],
                    ['key' => 'admin_pass', 'label' => 'Admin Password', 'type' => 'password', 'required' => true],
                ],
            ],
            'prometheus' => [
                'name'        => 'Prometheus',
                'description' => 'Metrics collection & alerting engine',
                'icon'        => 'Prom',
                'params'      => [
                    ['key' => 'domain', 'label' => 'Domain', 'type' => 'text', 'required' => true],
                ],
            ],
            'netdata' => [
                'name'        => 'Netdata',
                'description' => 'Real-time per-second system performance monitoring',
                'icon'        => 'ND',
                'params'      => [
                    ['key' => 'domain', 'label' => 'Domain', 'type' => 'text', 'required' => true],
                ],
            ],
            'glances' => [
                'name'        => 'Glances',
                'description' => 'Web-based system monitoring dashboard',
                'icon'        => 'Gl',
                'params'      => [
                    ['key' => 'domain', 'label' => 'Domain', 'type' => 'text', 'required' => true],
                ],
            ],
            'healthchecks' => [
                'name'        => 'Healthchecks',
                'description' => 'Cron job & scheduled task uptime monitoring (Postgres)',
                'icon'        => 'HC',
                'params'      => [
                    ['key' => 'domain',      'label' => 'Domain',         'type' => 'text',     'required' => true],
                    ['key' => 'admin_email', 'label' => 'Admin Email',    'type' => 'email',    'required' => true],
                    ['key' => 'admin_pass',  'label' => 'Admin Password', 'type' => 'password', 'required' => true],
                    ['key' => 'db_pass',     'label' => 'DB Password',    'type' => 'password', 'required' => true],
                ],
            ],
            'registry' => [
                'name'        => 'Docker Registry',
                'description' => 'Private Docker image registry',
                'icon'        => 'Reg',
                'params'      => [
                    ['key' => 'domain', 'label' => 'Domain', 'type' => 'text', 'required' => true],
                ],
            ],
            'verdaccio' => [
                'name'        => 'Verdaccio',
                'description' => 'Private npm / yarn / pnpm package registry',
                'icon'        => 'NPM',
                'params'      => [
                    ['key' => 'domain', 'label' => 'Domain', 'type' => 'text', 'required' => true],
                ],
            ],
            'watchtower' => [
                'name'        => 'Watchtower',
                'description' => 'Automatically update running Docker containers',
                'icon'        => 'WT',
                'params'      => [
                    ['key' => 'domain', 'label' => 'Domain', 'type' => 'text', 'required' => true],
                ],
            ],
            // ── Git & CI/CD ────────────────────────────────────────────────────────
            'forgejo' => [
                'name'        => 'Forgejo',
                'description' => 'Self-hosted Git forge — Gitea community fork (MariaDB)',
                'icon'        => 'FGJ',
                'params'      => [
                    ['key' => 'domain',  'label' => 'Domain',      'type' => 'text',     'required' => true],
                    ['key' => 'db_pass', 'label' => 'DB Password', 'type' => 'password', 'required' => true],
                ],
            ],
            'woodpecker-ci' => [
                'name'        => 'Woodpecker CI',
                'description' => 'Lightweight CI/CD pipeline server + agent',
                'icon'        => 'WP',
                'params'      => [
                    ['key' => 'domain',     'label' => 'Domain',     'type' => 'text', 'required' => true],
                    ['key' => 'admin_user', 'label' => 'Admin User', 'type' => 'text', 'required' => true],
                ],
            ],
            // ── Knowledge & Publishing ─────────────────────────────────────────────
            'bookstack' => [
                'name'        => 'BookStack',
                'description' => 'Simple wiki & documentation platform (MariaDB)',
                'icon'        => 'BS',
                'params'      => [
                    ['key' => 'domain',  'label' => 'Domain',      'type' => 'text',     'required' => true],
                    ['key' => 'db_pass', 'label' => 'DB Password', 'type' => 'password', 'required' => true],
                ],
            ],
            'hedgedoc' => [
                'name'        => 'HedgeDoc',
                'description' => 'Collaborative real-time Markdown editor (Postgres)',
                'icon'        => 'HD',
                'params'      => [
                    ['key' => 'domain',  'label' => 'Domain',      'type' => 'text',     'required' => true],
                    ['key' => 'db_pass', 'label' => 'DB Password', 'type' => 'password', 'required' => true],
                ],
            ],
            'freshrss' => [
                'name'        => 'FreshRSS',
                'description' => 'Self-hosted RSS & Atom news aggregator',
                'icon'        => 'RSS',
                'params'      => [
                    ['key' => 'domain', 'label' => 'Domain', 'type' => 'text', 'required' => true],
                ],
            ],
            'wallabag' => [
                'name'        => 'Wallabag',
                'description' => 'Read-it-later & web article archiver (MariaDB)',
                'icon'        => 'Wbg',
                'params'      => [
                    ['key' => 'domain',  'label' => 'Domain',      'type' => 'text',     'required' => true],
                    ['key' => 'db_pass', 'label' => 'DB Password', 'type' => 'password', 'required' => true],
                ],
            ],
            'homepage' => [
                'name'        => 'Homepage',
                'description' => 'Customizable application start page & dashboard',
                'icon'        => 'HP',
                'params'      => [
                    ['key' => 'domain', 'label' => 'Domain', 'type' => 'text', 'required' => true],
                ],
            ],
            // ── Auth & Security ────────────────────────────────────────────────────
            'keycloak' => [
                'name'        => 'Keycloak',
                'description' => 'Enterprise IAM & single sign-on (Postgres)',
                'icon'        => 'KC',
                'params'      => [
                    ['key' => 'domain',     'label' => 'Domain',         'type' => 'text',     'required' => true],
                    ['key' => 'admin_user', 'label' => 'Admin Username', 'type' => 'text',     'required' => true],
                    ['key' => 'admin_pass', 'label' => 'Admin Password', 'type' => 'password', 'required' => true],
                    ['key' => 'db_pass',    'label' => 'DB Password',    'type' => 'password', 'required' => true],
                ],
            ],
            'authentik' => [
                'name'        => 'Authentik',
                'description' => 'Identity provider & SSO gateway (Postgres + Redis)',
                'icon'        => 'Auth',
                'params'      => [
                    ['key' => 'domain',  'label' => 'Domain',      'type' => 'text',     'required' => true],
                    ['key' => 'db_pass', 'label' => 'DB Password', 'type' => 'password', 'required' => true],
                ],
            ],
            'passbolt' => [
                'name'        => 'Passbolt CE',
                'description' => 'Open-source team password manager (MariaDB)',
                'icon'        => 'PBlt',
                'params'      => [
                    ['key' => 'domain',  'label' => 'Domain',      'type' => 'text',     'required' => true],
                    ['key' => 'db_pass', 'label' => 'DB Password', 'type' => 'password', 'required' => true],
                ],
            ],
            // ── Analytics ─────────────────────────────────────────────────────────
            'plausible' => [
                'name'        => 'Plausible Analytics',
                'description' => 'Privacy-first analytics — Postgres + ClickHouse (resource-heavy)',
                'icon'        => 'Plau',
                'params'      => [
                    ['key' => 'domain',  'label' => 'Domain',      'type' => 'text',     'required' => true],
                    ['key' => 'db_pass', 'label' => 'DB Password', 'type' => 'password', 'required' => true],
                ],
            ],
            // ── Low-code & No-code ─────────────────────────────────────────────────
            'baserow' => [
                'name'        => 'Baserow',
                'description' => 'No-code database platform, Airtable alternative (Postgres)',
                'icon'        => 'BRow',
                'params'      => [
                    ['key' => 'domain',  'label' => 'Domain',      'type' => 'text',     'required' => true],
                    ['key' => 'db_pass', 'label' => 'DB Password', 'type' => 'password', 'required' => true],
                ],
            ],
            'appsmith' => [
                'name'        => 'Appsmith',
                'description' => 'Low-code internal app builder (bundled CE image)',
                'icon'        => 'Apps',
                'params'      => [
                    ['key' => 'domain', 'label' => 'Domain', 'type' => 'text', 'required' => true],
                ],
            ],
            'nocodb' => [
                'name'        => 'NocoDB',
                'description' => 'Airtable alternative — spreadsheet meets database',
                'icon'        => 'Noco',
                'params'      => [
                    ['key' => 'domain', 'label' => 'Domain', 'type' => 'text', 'required' => true],
                ],
            ],
            // ── Communication & Support ────────────────────────────────────────────
            'rocketchat' => [
                'name'        => 'Rocket.Chat',
                'description' => 'Open-source team messaging platform (MongoDB)',
                'icon'        => 'RC',
                'params'      => [
                    ['key' => 'domain', 'label' => 'Domain', 'type' => 'text', 'required' => true],
                ],
            ],
            'chatwoot' => [
                'name'        => 'Chatwoot',
                'description' => 'Customer support & live chat platform (Postgres + Redis)',
                'icon'        => 'CW',
                'params'      => [
                    ['key' => 'domain',  'label' => 'Domain',      'type' => 'text',     'required' => true],
                    ['key' => 'db_pass', 'label' => 'DB Password', 'type' => 'password', 'required' => true],
                ],
            ],
            'zammad' => [
                'name'        => 'Zammad',
                'description' => 'Help desk & ticketing system (Postgres)',
                'icon'        => 'Zam',
                'params'      => [
                    ['key' => 'domain',  'label' => 'Domain',      'type' => 'text',     'required' => true],
                    ['key' => 'db_pass', 'label' => 'DB Password', 'type' => 'password', 'required' => true],
                ],
            ],
            // ── Business & Productivity ────────────────────────────────────────────
            'invoiceninja' => [
                'name'        => 'Invoice Ninja',
                'description' => 'Invoicing, billing & time-tracking (MariaDB)',
                'icon'        => 'IN',
                'params'      => [
                    ['key' => 'domain',  'label' => 'Domain',      'type' => 'text',     'required' => true],
                    ['key' => 'db_pass', 'label' => 'DB Password', 'type' => 'password', 'required' => true],
                ],
            ],
            'linkding' => [
                'name'        => 'Linkding',
                'description' => 'Minimal self-hosted bookmark manager',
                'icon'        => 'LD',
                'params'      => [
                    ['key' => 'domain',     'label' => 'Domain',         'type' => 'text',     'required' => true],
                    ['key' => 'admin_user', 'label' => 'Admin Username', 'type' => 'text',     'required' => true],
                    ['key' => 'admin_pass', 'label' => 'Admin Password', 'type' => 'password', 'required' => true],
                ],
            ],
            'mealie' => [
                'name'        => 'Mealie',
                'description' => 'Self-hosted recipe manager & meal planner',
                'icon'        => 'Meal',
                'params'      => [
                    ['key' => 'domain',      'label' => 'Domain',         'type' => 'text',     'required' => true],
                    ['key' => 'admin_email', 'label' => 'Admin Email',    'type' => 'email',    'required' => true],
                    ['key' => 'admin_pass',  'label' => 'Admin Password', 'type' => 'password', 'required' => true],
                ],
            ],
            // ── Design & Collaboration ─────────────────────────────────────────────
            'penpot' => [
                'name'        => 'Penpot',
                'description' => 'Open-source design & prototyping tool (Postgres + Redis)',
                'icon'        => 'PenP',
                'params'      => [
                    ['key' => 'domain',  'label' => 'Domain',      'type' => 'text',     'required' => true],
                    ['key' => 'db_pass', 'label' => 'DB Password', 'type' => 'password', 'required' => true],
                ],
            ],
            'excalidraw' => [
                'name'        => 'Excalidraw',
                'description' => 'Virtual whiteboard & collaborative sketching tool',
                'icon'        => 'Exc',
                'params'      => [
                    ['key' => 'domain', 'label' => 'Domain', 'type' => 'text', 'required' => true],
                ],
            ],
            'stirlingpdf' => [
                'name'        => 'Stirling PDF',
                'description' => 'Self-hosted PDF manipulation & conversion toolkit',
                'icon'        => 'PDF',
                'params'      => [
                    ['key' => 'domain', 'label' => 'Domain', 'type' => 'text', 'required' => true],
                ],
            ],
            // ── AI / LLM ──────────────────────────────────────────────────────────
            'ollama' => [
                'name'        => 'Ollama',
                'description' => 'Run large language models locally (llama3, mistral, gemma, etc.)',
                'icon'        => 'LLM',
                'params'      => [
                    ['key' => 'domain', 'label' => 'Domain', 'type' => 'text', 'required' => true],
                ],
            ],
            'open-webui' => [
                'name'        => 'Open WebUI',
                'description' => 'ChatGPT-style UI for Ollama and OpenAI-compatible APIs',
                'icon'        => 'OWU',
                'params'      => [
                    ['key' => 'domain',       'label' => 'Domain',        'type' => 'text',     'required' => true],
                    ['key' => 'ollama_url',   'label' => 'Ollama URL',    'type' => 'text',     'required' => false],
                ],
            ],
            'flowise' => [
                'name'        => 'Flowise',
                'description' => 'Drag & drop LLM workflow builder (LangChain/LlamaIndex UI)',
                'icon'        => 'Flow',
                'params'      => [
                    ['key' => 'domain',      'label' => 'Domain',        'type' => 'text',     'required' => true],
                    ['key' => 'admin_user',  'label' => 'Admin Username','type' => 'text',     'required' => true],
                    ['key' => 'admin_pass',  'label' => 'Admin Password','type' => 'password', 'required' => true],
                ],
            ],
            'langfuse' => [
                'name'        => 'Langfuse',
                'description' => 'Open-source LLM observability: traces, evals, and prompt management',
                'icon'        => 'LFuse',
                'params'      => [
                    ['key' => 'domain',  'label' => 'Domain',      'type' => 'text',     'required' => true],
                    ['key' => 'db_pass', 'label' => 'DB Password', 'type' => 'password', 'required' => true],
                ],
            ],
            'anythingllm' => [
                'name'        => 'AnythingLLM',
                'description' => 'All-in-one AI app: chat with docs, RAG, agents, multi-user',
                'icon'        => 'ALLM',
                'params'      => [
                    ['key' => 'domain', 'label' => 'Domain', 'type' => 'text', 'required' => true],
                ],
            ],
            'localai' => [
                'name'        => 'LocalAI',
                'description' => 'OpenAI-compatible API using CPU/GPU models (gguf, diffusers)',
                'icon'        => 'LAI',
                'params'      => [
                    ['key' => 'domain', 'label' => 'Domain', 'type' => 'text', 'required' => true],
                ],
            ],
            'comfyui' => [
                'name'        => 'ComfyUI',
                'description' => 'Node-based Stable Diffusion image generation UI',
                'icon'        => 'CUI',
                'params'      => [
                    ['key' => 'domain', 'label' => 'Domain', 'type' => 'text', 'required' => true],
                ],
            ],
            // ── Developer Tools ───────────────────────────────────────────────────
            'code-server' => [
                'name'        => 'code-server',
                'description' => 'VS Code in the browser — full IDE accessible anywhere',
                'icon'        => 'CS',
                'params'      => [
                    ['key' => 'domain',      'label' => 'Domain',   'type' => 'text',     'required' => true],
                    ['key' => 'admin_pass',  'label' => 'Password', 'type' => 'password', 'required' => true],
                ],
            ],
            'jenkins' => [
                'name'        => 'Jenkins',
                'description' => 'Automation server for CI/CD pipelines',
                'icon'        => 'Jenk',
                'params'      => [
                    ['key' => 'domain', 'label' => 'Domain', 'type' => 'text', 'required' => true],
                ],
            ],
            'sonarqube' => [
                'name'        => 'SonarQube',
                'description' => 'Static code analysis & quality gate platform',
                'icon'        => 'SQ',
                'params'      => [
                    ['key' => 'domain',  'label' => 'Domain',      'type' => 'text',     'required' => true],
                    ['key' => 'db_pass', 'label' => 'DB Password', 'type' => 'password', 'required' => true],
                ],
            ],
            'vault' => [
                'name'        => 'HashiCorp Vault',
                'description' => 'Secrets management, encryption as a service',
                'icon'        => 'Vlt',
                'params'      => [
                    ['key' => 'domain', 'label' => 'Domain', 'type' => 'text', 'required' => true],
                ],
            ],
            'mailhog' => [
                'name'        => 'MailHog',
                'description' => 'Email testing tool — catches outbound mail in a web UI',
                'icon'        => 'MHog',
                'params'      => [
                    ['key' => 'domain', 'label' => 'Domain', 'type' => 'text', 'required' => true],
                ],
            ],
            'dozzle' => [
                'name'        => 'Dozzle',
                'description' => 'Real-time Docker container log viewer in the browser',
                'icon'        => 'Dozz',
                'params'      => [
                    ['key' => 'domain', 'label' => 'Domain', 'type' => 'text', 'required' => true],
                ],
            ],
            'yacht' => [
                'name'        => 'Yacht',
                'description' => 'Web UI for managing Docker containers and images',
                'icon'        => 'Ycht',
                'params'      => [
                    ['key' => 'domain',      'label' => 'Domain',         'type' => 'text',     'required' => true],
                    ['key' => 'admin_email', 'label' => 'Admin Email',    'type' => 'email',    'required' => true],
                    ['key' => 'admin_pass',  'label' => 'Admin Password', 'type' => 'password', 'required' => true],
                ],
            ],
            'semaphore' => [
                'name'        => 'Semaphore UI',
                'description' => 'Modern web UI for Ansible, Terraform, and task runners',
                'icon'        => 'Sem',
                'params'      => [
                    ['key' => 'domain',      'label' => 'Domain',         'type' => 'text',     'required' => true],
                    ['key' => 'admin_user',  'label' => 'Admin Username', 'type' => 'text',     'required' => true],
                    ['key' => 'admin_pass',  'label' => 'Admin Password', 'type' => 'password', 'required' => true],
                    ['key' => 'db_pass',     'label' => 'DB Password',    'type' => 'password', 'required' => true],
                ],
            ],
            // ── Databases ─────────────────────────────────────────────────────────
            'redis-standalone' => [
                'name'        => 'Redis',
                'description' => 'In-memory key-value store + Redis Commander web UI',
                'icon'        => 'Redis',
                'params'      => [
                    ['key' => 'domain',      'label' => 'Domain',          'type' => 'text',     'required' => true],
                    ['key' => 'admin_pass',  'label' => 'Redis Password',  'type' => 'password', 'required' => true],
                ],
            ],
            'mongodb' => [
                'name'        => 'MongoDB',
                'description' => 'NoSQL document database with Mongo Express web UI',
                'icon'        => 'Mongo',
                'params'      => [
                    ['key' => 'domain',      'label' => 'Domain',         'type' => 'text',     'required' => true],
                    ['key' => 'admin_user',  'label' => 'Root Username',  'type' => 'text',     'required' => true],
                    ['key' => 'admin_pass',  'label' => 'Root Password',  'type' => 'password', 'required' => true],
                ],
            ],
            'postgresql' => [
                'name'        => 'PostgreSQL',
                'description' => 'Relational database with pgAdmin 4 web UI',
                'icon'        => 'PG',
                'params'      => [
                    ['key' => 'domain',      'label' => 'Domain',         'type' => 'text',     'required' => true],
                    ['key' => 'db_pass',     'label' => 'DB Password',    'type' => 'password', 'required' => true],
                    ['key' => 'admin_email', 'label' => 'pgAdmin Email',  'type' => 'email',    'required' => true],
                    ['key' => 'admin_pass',  'label' => 'pgAdmin Password','type' => 'password','required' => true],
                ],
            ],
            'mariadb' => [
                'name'        => 'MariaDB',
                'description' => 'MariaDB relational database with phpMyAdmin web UI',
                'icon'        => 'MDB',
                'params'      => [
                    ['key' => 'domain',      'label' => 'Domain',         'type' => 'text',     'required' => true],
                    ['key' => 'db_pass',     'label' => 'Root Password',  'type' => 'password', 'required' => true],
                ],
            ],
            'elasticsearch' => [
                'name'        => 'Elasticsearch',
                'description' => 'Distributed search & analytics engine with Kibana',
                'icon'        => 'ES',
                'params'      => [
                    ['key' => 'domain',      'label' => 'Domain',         'type' => 'text',     'required' => true],
                    ['key' => 'admin_pass',  'label' => 'Elastic Password','type' => 'password','required' => true],
                ],
            ],
            'influxdb' => [
                'name'        => 'InfluxDB',
                'description' => 'Time-series database for metrics & IoT data',
                'icon'        => 'IDB',
                'params'      => [
                    ['key' => 'domain',       'label' => 'Domain',         'type' => 'text',     'required' => true],
                    ['key' => 'admin_user',   'label' => 'Admin Username', 'type' => 'text',     'required' => true],
                    ['key' => 'admin_pass',   'label' => 'Admin Password', 'type' => 'password', 'required' => true],
                    ['key' => 'admin_email',  'label' => 'Admin Email',    'type' => 'email',    'required' => true],
                ],
            ],
            'neo4j' => [
                'name'        => 'Neo4j',
                'description' => 'Graph database with browser-based query UI',
                'icon'        => 'Neo',
                'params'      => [
                    ['key' => 'domain',      'label' => 'Domain',         'type' => 'text',     'required' => true],
                    ['key' => 'admin_pass',  'label' => 'Neo4j Password', 'type' => 'password', 'required' => true],
                ],
            ],
            'qdrant' => [
                'name'        => 'Qdrant',
                'description' => 'Vector database for AI/ML similarity search',
                'icon'        => 'Qdrt',
                'params'      => [
                    ['key' => 'domain', 'label' => 'Domain', 'type' => 'text', 'required' => true],
                ],
            ],
            // ── Monitoring & Observability ────────────────────────────────────────
            'loki' => [
                'name'        => 'Loki + Grafana',
                'description' => 'Log aggregation stack: Promtail → Loki → Grafana',
                'icon'        => 'Loki',
                'params'      => [
                    ['key' => 'domain',      'label' => 'Domain',          'type' => 'text',     'required' => true],
                    ['key' => 'admin_pass',  'label' => 'Grafana Password','type' => 'password', 'required' => true],
                ],
            ],
            'jaeger' => [
                'name'        => 'Jaeger',
                'description' => 'Distributed tracing platform (OpenTelemetry-compatible)',
                'icon'        => 'Jaeg',
                'params'      => [
                    ['key' => 'domain', 'label' => 'Domain', 'type' => 'text', 'required' => true],
                ],
            ],
            'victoria-metrics' => [
                'name'        => 'VictoriaMetrics',
                'description' => 'High-performance drop-in replacement for Prometheus storage',
                'icon'        => 'VM',
                'params'      => [
                    ['key' => 'domain', 'label' => 'Domain', 'type' => 'text', 'required' => true],
                ],
            ],
            'changedetection' => [
                'name'        => 'changedetection.io',
                'description' => 'Monitor any website for content changes, with alerts',
                'icon'        => 'Chng',
                'params'      => [
                    ['key' => 'domain',     'label' => 'Domain',   'type' => 'text',     'required' => true],
                    ['key' => 'admin_pass', 'label' => 'Password', 'type' => 'password', 'required' => false],
                ],
            ],
            'metabase' => [
                'name'        => 'Metabase',
                'description' => 'Business intelligence & analytics with drag-drop dashboards',
                'icon'        => 'Meta',
                'params'      => [
                    ['key' => 'domain',      'label' => 'Domain',      'type' => 'text',     'required' => true],
                    ['key' => 'db_pass',     'label' => 'DB Password', 'type' => 'password', 'required' => true],
                    ['key' => 'admin_email', 'label' => 'Admin Email', 'type' => 'email',    'required' => true],
                    ['key' => 'admin_pass',  'label' => 'Admin Pass',  'type' => 'password', 'required' => true],
                ],
            ],
            // ── Networking & Security ─────────────────────────────────────────────
            'pihole' => [
                'name'        => 'Pi-hole',
                'description' => 'Network-level ad & tracker blocker with DNS sinkhole',
                'icon'        => 'PiH',
                'params'      => [
                    ['key' => 'domain',      'label' => 'Domain',          'type' => 'text',     'required' => true],
                    ['key' => 'admin_pass',  'label' => 'Admin Password',  'type' => 'password', 'required' => true],
                ],
            ],
            'adguard-home' => [
                'name'        => 'AdGuard Home',
                'description' => 'Network-wide DNS ad blocker with a polished web UI',
                'icon'        => 'AGH',
                'params'      => [
                    ['key' => 'domain', 'label' => 'Domain', 'type' => 'text', 'required' => true],
                ],
            ],
            'nginx-proxy-manager' => [
                'name'        => 'Nginx Proxy Manager',
                'description' => 'Reverse proxy + SSL management with a friendly UI',
                'icon'        => 'NPM',
                'params'      => [
                    ['key' => 'domain',      'label' => 'Domain',         'type' => 'text',     'required' => true],
                    ['key' => 'db_pass',     'label' => 'DB Password',    'type' => 'password', 'required' => true],
                    ['key' => 'admin_email', 'label' => 'Admin Email',    'type' => 'email',    'required' => true],
                    ['key' => 'admin_pass',  'label' => 'Admin Password', 'type' => 'password', 'required' => true],
                ],
            ],
            'traefik' => [
                'name'        => 'Traefik',
                'description' => 'Cloud-native reverse proxy and load balancer with dashboard',
                'icon'        => 'Trf',
                'params'      => [
                    ['key' => 'domain', 'label' => 'Domain', 'type' => 'text', 'required' => true],
                ],
            ],
            'wg-easy' => [
                'name'        => 'WireGuard Easy',
                'description' => 'WireGuard VPN server with a simple web management UI',
                'icon'        => 'WGE',
                'params'      => [
                    ['key' => 'domain',      'label' => 'Domain',          'type' => 'text',     'required' => true],
                    ['key' => 'admin_pass',  'label' => 'UI Password',     'type' => 'password', 'required' => true],
                ],
            ],
            'cloudflared' => [
                'name'        => 'Cloudflare Tunnel',
                'description' => 'Expose local services via Cloudflare Tunnel (cloudflared)',
                'icon'        => 'CFT',
                'params'      => [
                    ['key' => 'domain', 'label' => 'Domain',       'type' => 'text', 'required' => true],
                    ['key' => 'token',  'label' => 'Tunnel Token', 'type' => 'text', 'required' => true],
                ],
            ],
            'crowdsec' => [
                'name'        => 'CrowdSec',
                'description' => 'Collaborative intrusion detection & threat intelligence',
                'icon'        => 'CS',
                'params'      => [
                    ['key' => 'domain', 'label' => 'Domain', 'type' => 'text', 'required' => true],
                ],
            ],
            'authelia' => [
                'name'        => 'Authelia',
                'description' => 'SSO authentication & 2FA gateway for reverse-proxied apps',
                'icon'        => 'Auth',
                'params'      => [
                    ['key' => 'domain',      'label' => 'Domain',        'type' => 'text',     'required' => true],
                    ['key' => 'admin_pass',  'label' => 'JWT Secret',    'type' => 'password', 'required' => true],
                ],
            ],
            // ── CMS & E-commerce ──────────────────────────────────────────────────
            'drupal' => [
                'name'        => 'Drupal',
                'description' => 'Enterprise content management system (Postgres backend)',
                'icon'        => 'Drpl',
                'params'      => [
                    ['key' => 'domain',  'label' => 'Domain',      'type' => 'text',     'required' => true],
                    ['key' => 'db_pass', 'label' => 'DB Password', 'type' => 'password', 'required' => true],
                ],
            ],
            'joomla' => [
                'name'        => 'Joomla',
                'description' => 'Popular open-source CMS with thousands of extensions',
                'icon'        => 'Joom',
                'params'      => [
                    ['key' => 'domain',  'label' => 'Domain',      'type' => 'text',     'required' => true],
                    ['key' => 'db_pass', 'label' => 'DB Password', 'type' => 'password', 'required' => true],
                ],
            ],
            'grav' => [
                'name'        => 'Grav CMS',
                'description' => 'Flat-file CMS — no database required, blazing fast',
                'icon'        => 'Grav',
                'params'      => [
                    ['key' => 'domain', 'label' => 'Domain', 'type' => 'text', 'required' => true],
                ],
            ],
            'prestashop' => [
                'name'        => 'PrestaShop',
                'description' => 'Full-featured open-source e-commerce platform',
                'icon'        => 'PS',
                'params'      => [
                    ['key' => 'domain',      'label' => 'Domain',         'type' => 'text',     'required' => true],
                    ['key' => 'db_pass',     'label' => 'DB Password',    'type' => 'password', 'required' => true],
                    ['key' => 'admin_email', 'label' => 'Admin Email',    'type' => 'email',    'required' => true],
                    ['key' => 'admin_pass',  'label' => 'Admin Password', 'type' => 'password', 'required' => true],
                ],
            ],
            'opencart' => [
                'name'        => 'OpenCart',
                'description' => 'Lightweight open-source e-commerce shopping cart',
                'icon'        => 'OC',
                'params'      => [
                    ['key' => 'domain',  'label' => 'Domain',      'type' => 'text',     'required' => true],
                    ['key' => 'db_pass', 'label' => 'DB Password', 'type' => 'password', 'required' => true],
                ],
            ],
            'medusa' => [
                'name'        => 'Medusa',
                'description' => 'Open-source headless commerce platform (Node.js + Postgres)',
                'icon'        => 'Med',
                'params'      => [
                    ['key' => 'domain',  'label' => 'Domain',      'type' => 'text',     'required' => true],
                    ['key' => 'db_pass', 'label' => 'DB Password', 'type' => 'password', 'required' => true],
                ],
            ],
            'answer' => [
                'name'        => 'Answer',
                'description' => 'Q&A community platform — like Stack Overflow for your team',
                'icon'        => 'Ans',
                'params'      => [
                    ['key' => 'domain',  'label' => 'Domain',      'type' => 'text',     'required' => true],
                    ['key' => 'db_pass', 'label' => 'DB Password', 'type' => 'password', 'required' => true],
                ],
            ],
            // ── Project Management ────────────────────────────────────────────────
            'plane' => [
                'name'        => 'Plane',
                'description' => 'Open-source project management (Jira alternative)',
                'icon'        => 'Pln',
                'params'      => [
                    ['key' => 'domain',  'label' => 'Domain',      'type' => 'text',     'required' => true],
                    ['key' => 'db_pass', 'label' => 'DB Password', 'type' => 'password', 'required' => true],
                ],
            ],
            'openproject' => [
                'name'        => 'OpenProject',
                'description' => 'Full-featured project management with Gantt charts & Agile boards',
                'icon'        => 'OP',
                'params'      => [
                    ['key' => 'domain',  'label' => 'Domain',         'type' => 'text',     'required' => true],
                    ['key' => 'db_pass', 'label' => 'DB Password',    'type' => 'password', 'required' => true],
                    ['key' => 'admin_pass', 'label' => 'Admin Password', 'type' => 'password', 'required' => true],
                ],
            ],
            'leantime' => [
                'name'        => 'Leantime',
                'description' => 'Strategic project management for non-project managers',
                'icon'        => 'LT',
                'params'      => [
                    ['key' => 'domain',      'label' => 'Domain',         'type' => 'text',     'required' => true],
                    ['key' => 'db_pass',     'label' => 'DB Password',    'type' => 'password', 'required' => true],
                    ['key' => 'admin_email', 'label' => 'Admin Email',    'type' => 'email',    'required' => true],
                    ['key' => 'admin_pass',  'label' => 'Admin Password', 'type' => 'password', 'required' => true],
                ],
            ],
            'wekan' => [
                'name'        => 'WeKan',
                'description' => 'Open-source kanban board (Trello alternative)',
                'icon'        => 'Wkn',
                'params'      => [
                    ['key' => 'domain',      'label' => 'Domain',         'type' => 'text',     'required' => true],
                    ['key' => 'admin_user',  'label' => 'Admin Username', 'type' => 'text',     'required' => true],
                    ['key' => 'admin_pass',  'label' => 'Admin Password', 'type' => 'password', 'required' => true],
                    ['key' => 'admin_email', 'label' => 'Admin Email',    'type' => 'email',    'required' => true],
                ],
            ],
            'focalboard' => [
                'name'        => 'Focalboard',
                'description' => 'Mattermost project management — kanban, table, gallery views',
                'icon'        => 'FB',
                'params'      => [
                    ['key' => 'domain',  'label' => 'Domain',      'type' => 'text',     'required' => true],
                    ['key' => 'db_pass', 'label' => 'DB Password', 'type' => 'password', 'required' => true],
                ],
            ],
            // ── Communication ─────────────────────────────────────────────────────
            'matrix-synapse' => [
                'name'        => 'Matrix Synapse',
                'description' => 'Federated, end-to-end encrypted chat server (Element-compatible)',
                'icon'        => 'Mtrx',
                'params'      => [
                    ['key' => 'domain',      'label' => 'Domain',         'type' => 'text',     'required' => true],
                    ['key' => 'db_pass',     'label' => 'DB Password',    'type' => 'password', 'required' => true],
                    ['key' => 'admin_pass',  'label' => 'Shared Secret',  'type' => 'password', 'required' => true],
                ],
            ],
            'gotify' => [
                'name'        => 'Gotify',
                'description' => 'Self-hosted push notifications server for apps & scripts',
                'icon'        => 'Gtfy',
                'params'      => [
                    ['key' => 'domain',     'label' => 'Domain',          'type' => 'text',     'required' => true],
                    ['key' => 'admin_pass', 'label' => 'Admin Password',  'type' => 'password', 'required' => true],
                ],
            ],
            'ntfy' => [
                'name'        => 'ntfy',
                'description' => 'Simple HTTP-based push notification service',
                'icon'        => 'ntfy',
                'params'      => [
                    ['key' => 'domain', 'label' => 'Domain', 'type' => 'text', 'required' => true],
                ],
            ],
            'grist' => [
                'name'        => 'Grist',
                'description' => 'Modern spreadsheet + database hybrid — Airtable alternative',
                'icon'        => 'Grst',
                'params'      => [
                    ['key' => 'domain',      'label' => 'Domain',         'type' => 'text',     'required' => true],
                    ['key' => 'admin_email', 'label' => 'Admin Email',    'type' => 'email',    'required' => true],
                    ['key' => 'admin_pass',  'label' => 'Admin Password', 'type' => 'password', 'required' => true],
                ],
            ],
            'mailpit' => [
                'name'        => 'Mailpit',
                'description' => 'Email testing tool with modern UI — MailHog successor',
                'icon'        => 'MPit',
                'params'      => [
                    ['key' => 'domain', 'label' => 'Domain', 'type' => 'text', 'required' => true],
                ],
            ],
            // ── File Storage & Backup ─────────────────────────────────────────────
            'syncthing' => [
                'name'        => 'Syncthing',
                'description' => 'Continuous peer-to-peer file synchronization tool',
                'icon'        => 'Sync',
                'params'      => [
                    ['key' => 'domain', 'label' => 'Domain', 'type' => 'text', 'required' => true],
                ],
            ],
            'sftpgo' => [
                'name'        => 'SFTPGo',
                'description' => 'Full-featured SFTP/FTP/WebDAV server with web admin UI',
                'icon'        => 'SFTP',
                'params'      => [
                    ['key' => 'domain',      'label' => 'Domain',         'type' => 'text',     'required' => true],
                    ['key' => 'admin_user',  'label' => 'Admin Username', 'type' => 'text',     'required' => true],
                    ['key' => 'admin_pass',  'label' => 'Admin Password', 'type' => 'password', 'required' => true],
                ],
            ],
            'owncloud' => [
                'name'        => 'ownCloud',
                'description' => 'Enterprise file sync and share platform (Nextcloud predecessor)',
                'icon'        => 'OC',
                'params'      => [
                    ['key' => 'domain',      'label' => 'Domain',         'type' => 'text',     'required' => true],
                    ['key' => 'db_pass',     'label' => 'DB Password',    'type' => 'password', 'required' => true],
                    ['key' => 'admin_pass',  'label' => 'Admin Password', 'type' => 'password', 'required' => true],
                ],
            ],
            'duplicati' => [
                'name'        => 'Duplicati',
                'description' => 'Encrypted backups to cloud/local storage with web UI',
                'icon'        => 'Dup',
                'params'      => [
                    ['key' => 'domain', 'label' => 'Domain', 'type' => 'text', 'required' => true],
                ],
            ],
            'calibre-web' => [
                'name'        => 'Calibre-Web',
                'description' => 'Web interface for your Calibre ebook library',
                'icon'        => 'Cal',
                'params'      => [
                    ['key' => 'domain', 'label' => 'Domain', 'type' => 'text', 'required' => true],
                ],
            ],
            // ── ERP & Business ────────────────────────────────────────────────────
            'odoo' => [
                'name'        => 'Odoo',
                'description' => 'Full-suite open-source ERP: CRM, accounting, inventory, HR',
                'icon'        => 'Odoo',
                'params'      => [
                    ['key' => 'domain',      'label' => 'Domain',         'type' => 'text',     'required' => true],
                    ['key' => 'db_pass',     'label' => 'DB Password',    'type' => 'password', 'required' => true],
                    ['key' => 'admin_pass',  'label' => 'Master Password','type' => 'password', 'required' => true],
                ],
            ],
            'akaunting' => [
                'name'        => 'Akaunting',
                'description' => 'Free accounting software for small businesses',
                'icon'        => 'Aknt',
                'params'      => [
                    ['key' => 'domain',      'label' => 'Domain',         'type' => 'text',     'required' => true],
                    ['key' => 'db_pass',     'label' => 'DB Password',    'type' => 'password', 'required' => true],
                    ['key' => 'admin_email', 'label' => 'Admin Email',    'type' => 'email',    'required' => true],
                    ['key' => 'admin_pass',  'label' => 'Admin Password', 'type' => 'password', 'required' => true],
                ],
            ],
            'monica' => [
                'name'        => 'Monica',
                'description' => 'Personal CRM — track relationships, interactions, birthdays',
                'icon'        => 'Mon',
                'params'      => [
                    ['key' => 'domain',      'label' => 'Domain',         'type' => 'text',     'required' => true],
                    ['key' => 'db_pass',     'label' => 'DB Password',    'type' => 'password', 'required' => true],
                    ['key' => 'admin_email', 'label' => 'Admin Email',    'type' => 'email',    'required' => true],
                    ['key' => 'admin_pass',  'label' => 'Admin Password', 'type' => 'password', 'required' => true],
                ],
            ],
            'twenty' => [
                'name'        => 'Twenty CRM',
                'description' => 'Modern open-source CRM (Salesforce alternative)',
                'icon'        => 'Twty',
                'params'      => [
                    ['key' => 'domain',  'label' => 'Domain',      'type' => 'text',     'required' => true],
                    ['key' => 'db_pass', 'label' => 'DB Password', 'type' => 'password', 'required' => true],
                ],
            ],
            'dolibarr' => [
                'name'        => 'Dolibarr',
                'description' => 'ERP & CRM for SMBs: invoicing, stock, HR, and more',
                'icon'        => 'Doli',
                'params'      => [
                    ['key' => 'domain',      'label' => 'Domain',         'type' => 'text',     'required' => true],
                    ['key' => 'db_pass',     'label' => 'DB Password',    'type' => 'password', 'required' => true],
                    ['key' => 'admin_pass',  'label' => 'Admin Password', 'type' => 'password', 'required' => true],
                ],
            ],
            // ── Media ─────────────────────────────────────────────────────────────
            'audiobookshelf' => [
                'name'        => 'Audiobookshelf',
                'description' => 'Self-hosted audiobook & podcast server with mobile apps',
                'icon'        => 'ABS',
                'params'      => [
                    ['key' => 'domain', 'label' => 'Domain', 'type' => 'text', 'required' => true],
                ],
            ],
            'komga' => [
                'name'        => 'Komga',
                'description' => 'Comic/manga library server with OPDS & web reader',
                'icon'        => 'Kmga',
                'params'      => [
                    ['key' => 'domain',      'label' => 'Domain',         'type' => 'text',     'required' => true],
                    ['key' => 'admin_email', 'label' => 'Admin Email',    'type' => 'email',    'required' => true],
                    ['key' => 'admin_pass',  'label' => 'Admin Password', 'type' => 'password', 'required' => true],
                ],
            ],
            'grocy' => [
                'name'        => 'Grocy',
                'description' => 'Self-hosted grocery management, stock tracking & meal planning',
                'icon'        => 'Grcy',
                'params'      => [
                    ['key' => 'domain', 'label' => 'Domain', 'type' => 'text', 'required' => true],
                ],
            ],
            'tube-archivist' => [
                'name'        => 'TubeArchivist',
                'description' => 'Self-hosted YouTube archive with search and web player',
                'icon'        => 'TA',
                'params'      => [
                    ['key' => 'domain',      'label' => 'Domain',         'type' => 'text',     'required' => true],
                    ['key' => 'admin_user',  'label' => 'Admin Username', 'type' => 'text',     'required' => true],
                    ['key' => 'admin_pass',  'label' => 'Admin Password', 'type' => 'password', 'required' => true],
                ],
            ],
            'affine' => [
                'name'        => 'AFFiNE',
                'description' => 'Next-gen knowledge base — docs, whiteboard, and database in one',
                'icon'        => 'Afn',
                'params'      => [
                    ['key' => 'domain',  'label' => 'Domain',      'type' => 'text',     'required' => true],
                    ['key' => 'db_pass', 'label' => 'DB Password', 'type' => 'password', 'required' => true],
                ],
            ],
            // ── Smart Home ────────────────────────────────────────────────────────
            'home-assistant' => [
                'name'        => 'Home Assistant',
                'description' => 'Open-source home automation platform',
                'icon'        => 'HA',
                'params'      => [
                    ['key' => 'domain', 'label' => 'Domain', 'type' => 'text', 'required' => true],
                ],
            ],
            'node-red' => [
                'name'        => 'Node-RED',
                'description' => 'Flow-based programming tool for IoT and automation',
                'icon'        => 'NR',
                'params'      => [
                    ['key' => 'domain', 'label' => 'Domain', 'type' => 'text', 'required' => true],
                ],
            ],
            'mosquitto' => [
                'name'        => 'Mosquitto MQTT',
                'description' => 'Lightweight MQTT message broker for IoT devices',
                'icon'        => 'MQTT',
                'params'      => [
                    ['key' => 'domain',      'label' => 'Domain',          'type' => 'text',     'required' => true],
                    ['key' => 'admin_user',  'label' => 'MQTT Username',   'type' => 'text',     'required' => true],
                    ['key' => 'admin_pass',  'label' => 'MQTT Password',   'type' => 'password', 'required' => true],
                ],
            ],
            'zigbee2mqtt' => [
                'name'        => 'Zigbee2MQTT',
                'description' => 'Bridge Zigbee devices to MQTT without proprietary hubs',
                'icon'        => 'Z2M',
                'params'      => [
                    ['key' => 'domain', 'label' => 'Domain',             'type' => 'text', 'required' => true],
                    ['key' => 'mqtt_host', 'label' => 'MQTT Host/IP',   'type' => 'text', 'required' => true],
                ],
            ],
            // ── Dashboards & Admin ────────────────────────────────────────────────
            'dashy' => [
                'name'        => 'Dashy',
                'description' => 'Highly customizable self-hosted start page and dashboard',
                'icon'        => 'Dash',
                'params'      => [
                    ['key' => 'domain', 'label' => 'Domain', 'type' => 'text', 'required' => true],
                ],
            ],
            'homarr' => [
                'name'        => 'Homarr',
                'description' => 'Sleek home server dashboard with app integrations',
                'icon'        => 'Hom',
                'params'      => [
                    ['key' => 'domain', 'label' => 'Domain', 'type' => 'text', 'required' => true],
                ],
            ],
            'homer' => [
                'name'        => 'Homer',
                'description' => 'Static YAML-configured home page for your services',
                'icon'        => 'Hmr',
                'params'      => [
                    ['key' => 'domain', 'label' => 'Domain', 'type' => 'text', 'required' => true],
                ],
            ],
            'it-tools' => [
                'name'        => 'IT Tools',
                'description' => 'Collection of handy online tools for developers (offline)',
                'icon'        => 'IT',
                'params'      => [
                    ['key' => 'domain', 'label' => 'Domain', 'type' => 'text', 'required' => true],
                ],
            ],
            'cloudbeaver' => [
                'name'        => 'CloudBeaver',
                'description' => 'Web-based universal database management UI (DBeaver cloud)',
                'icon'        => 'CBvr',
                'params'      => [
                    ['key' => 'domain', 'label' => 'Domain', 'type' => 'text', 'required' => true],
                ],
            ],
            'pgadmin' => [
                'name'        => 'pgAdmin 4',
                'description' => 'Feature-rich web UI for managing PostgreSQL databases',
                'icon'        => 'PGA',
                'params'      => [
                    ['key' => 'domain',      'label' => 'Domain',         'type' => 'text',     'required' => true],
                    ['key' => 'admin_email', 'label' => 'Admin Email',    'type' => 'email',    'required' => true],
                    ['key' => 'admin_pass',  'label' => 'Admin Password', 'type' => 'password', 'required' => true],
                ],
            ],
            'phpmyadmin' => [
                'name'        => 'phpMyAdmin',
                'description' => 'Classic web-based MySQL/MariaDB database admin interface',
                'icon'        => 'PMA',
                'params'      => [
                    ['key' => 'domain',   'label' => 'Domain',       'type' => 'text',     'required' => true],
                    ['key' => 'db_host',  'label' => 'DB Host',      'type' => 'text',     'required' => true],
                    ['key' => 'db_pass',  'label' => 'Root Password','type' => 'password', 'required' => true],
                ],
            ],
            'infisical' => [
                'name'        => 'Infisical',
                'description' => 'Open-source secrets management platform (Vault alternative)',
                'icon'        => 'Inf',
                'params'      => [
                    ['key' => 'domain',  'label' => 'Domain',      'type' => 'text',     'required' => true],
                    ['key' => 'db_pass', 'label' => 'DB Password', 'type' => 'password', 'required' => true],
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
        $this->db->execute("UPDATE docker_compose_stacks SET status='pending' WHERE id=?", [$stackId]);
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

            'uptime-kuma' => "version: '3.8'\nservices:\n  uptime-kuma:\n    image: louislam/uptime-kuma:latest\n    restart: unless-stopped\n    volumes:\n      - kuma_data:/app/data\n    labels:\n      - 'novacpx.domain={$domain}'\nvolumes:\n  kuma_data:\n",

            'portainer' => "version: '3.8'\nservices:\n  portainer:\n    image: portainer/portainer-ce:latest\n    restart: unless-stopped\n    volumes:\n      - /var/run/docker.sock:/var/run/docker.sock\n      - portainer_data:/data\n    labels:\n      - 'novacpx.domain={$domain}'\nvolumes:\n  portainer_data:\n",

            'minio' => "version: '3.8'\nservices:\n  minio:\n    image: minio/minio:latest\n    restart: unless-stopped\n    command: server /data --console-address ':9001'\n    environment:\n      MINIO_ROOT_USER: " . ($p['access_key'] ?? 'minioadmin') . "\n      MINIO_ROOT_PASSWORD: " . ($p['secret_key'] ?? bin2hex(random_bytes(8))) . "\n    volumes:\n      - minio_data:/data\n    labels:\n      - 'novacpx.domain={$domain}'\nvolumes:\n  minio_data:\n",

            'n8n' => "version: '3.8'\nservices:\n  n8n:\n    image: n8nio/n8n:latest\n    restart: unless-stopped\n    environment:\n      N8N_HOST: {$domain}\n      N8N_PROTOCOL: https\n      WEBHOOK_URL: https://{$domain}/\n      DB_TYPE: sqlite\n    volumes:\n      - n8n_data:/home/node/.n8n\n    labels:\n      - 'novacpx.domain={$domain}'\nvolumes:\n  n8n_data:\n",

            'directus' => (function() use ($p, $domain, $dbPass, $adminPass): string {
                $email  = preg_replace('/[^a-zA-Z0-9@._\-]/', '', $p['admin_email'] ?? 'admin@' . $domain);
                $secret = bin2hex(random_bytes(16));
                return "version: '3.8'\nservices:\n  db:\n    image: postgres:16-alpine\n    restart: unless-stopped\n    environment:\n      POSTGRES_DB: directus\n      POSTGRES_USER: directus\n      POSTGRES_PASSWORD: {$dbPass}\n    volumes:\n      - db_data:/var/lib/postgresql/data\n  directus:\n    image: directus/directus:latest\n    restart: unless-stopped\n    depends_on: [db]\n    environment:\n      SECRET: {$secret}\n      DB_CLIENT: pg\n      DB_HOST: db\n      DB_PORT: '5432'\n      DB_DATABASE: directus\n      DB_USER: directus\n      DB_PASSWORD: {$dbPass}\n      ADMIN_EMAIL: {$email}\n      ADMIN_PASSWORD: {$adminPass}\n    volumes:\n      - directus_uploads:/directus/uploads\n    labels:\n      - 'novacpx.domain={$domain}'\nvolumes:\n  db_data:\n  directus_uploads:\n";
            })(),

            'listmonk' => "version: '3.8'\nservices:\n  db:\n    image: postgres:16-alpine\n    restart: unless-stopped\n    environment:\n      POSTGRES_DB: listmonk\n      POSTGRES_USER: listmonk\n      POSTGRES_PASSWORD: {$dbPass}\n    volumes:\n      - db_data:/var/lib/postgresql/data\n  listmonk:\n    image: listmonk/listmonk:latest\n    restart: unless-stopped\n    depends_on: [db]\n    environment:\n      LISTMONK_db__host: db\n      LISTMONK_db__port: '5432'\n      LISTMONK_db__user: listmonk\n      LISTMONK_db__password: {$dbPass}\n      LISTMONK_db__database: listmonk\n    labels:\n      - 'novacpx.domain={$domain}'\nvolumes:\n  db_data:\n",

            'umami' => "version: '3.8'\nservices:\n  db:\n    image: postgres:16-alpine\n    restart: unless-stopped\n    environment:\n      POSTGRES_DB: umami\n      POSTGRES_USER: umami\n      POSTGRES_PASSWORD: {$dbPass}\n    volumes:\n      - db_data:/var/lib/postgresql/data\n  umami:\n    image: ghcr.io/umami-software/umami:postgresql-latest\n    restart: unless-stopped\n    depends_on: [db]\n    environment:\n      DATABASE_URL: postgresql://umami:{$dbPass}@db:5432/umami\n      APP_SECRET: " . bin2hex(random_bytes(16)) . "\n    labels:\n      - 'novacpx.domain={$domain}'\nvolumes:\n  db_data:\n",

            'photoprism' => "version: '3.8'\nservices:\n  db:\n    image: mariadb:10.11\n    restart: unless-stopped\n    environment:\n      MYSQL_ROOT_PASSWORD: {$dbPass}\n      MYSQL_DATABASE: photoprism\n      MYSQL_USER: photoprism\n      MYSQL_PASSWORD: {$dbPass}\n    volumes:\n      - db_data:/var/lib/mysql\n  photoprism:\n    image: photoprism/photoprism:latest\n    restart: unless-stopped\n    depends_on: [db]\n    environment:\n      PHOTOPRISM_ADMIN_PASSWORD: {$adminPass}\n      PHOTOPRISM_SITE_URL: https://{$domain}/\n      PHOTOPRISM_DATABASE_DRIVER: mysql\n      PHOTOPRISM_DATABASE_SERVER: db:3306\n      PHOTOPRISM_DATABASE_NAME: photoprism\n      PHOTOPRISM_DATABASE_USER: photoprism\n      PHOTOPRISM_DATABASE_PASSWORD: {$dbPass}\n    volumes:\n      - photoprism_data:/photoprism/storage\n      - photoprism_originals:/photoprism/originals\n    labels:\n      - 'novacpx.domain={$domain}'\nvolumes:\n  db_data:\n  photoprism_data:\n  photoprism_originals:\n",

            'meilisearch' => "version: '3.8'\nservices:\n  meilisearch:\n    image: getmeili/meilisearch:latest\n    restart: unless-stopped\n    environment:\n      MEILI_MASTER_KEY: " . ($p['master_key'] ?? bin2hex(random_bytes(16))) . "\n      MEILI_ENV: production\n    volumes:\n      - meili_data:/meili_data\n    labels:\n      - 'novacpx.domain={$domain}'\nvolumes:\n  meili_data:\n",

            'wikijs' => "version: '3.8'\nservices:\n  db:\n    image: postgres:16-alpine\n    restart: unless-stopped\n    environment:\n      POSTGRES_DB: wiki\n      POSTGRES_USER: wiki\n      POSTGRES_PASSWORD: {$dbPass}\n    volumes:\n      - db_data:/var/lib/postgresql/data\n  wiki:\n    image: ghcr.io/requarks/wiki:2\n    restart: unless-stopped\n    depends_on: [db]\n    environment:\n      DB_TYPE: postgres\n      DB_HOST: db\n      DB_PORT: '5432'\n      DB_USER: wiki\n      DB_PASS: {$dbPass}\n      DB_NAME: wiki\n    labels:\n      - 'novacpx.domain={$domain}'\nvolumes:\n  db_data:\n",

            'vikunja' => "version: '3.8'\nservices:\n  db:\n    image: mariadb:10.11\n    restart: unless-stopped\n    environment:\n      MYSQL_ROOT_PASSWORD: {$dbPass}\n      MYSQL_DATABASE: vikunja\n      MYSQL_USER: vikunja\n      MYSQL_PASSWORD: {$dbPass}\n    volumes:\n      - db_data:/var/lib/mysql\n  vikunja:\n    image: vikunja/vikunja:latest\n    restart: unless-stopped\n    depends_on: [db]\n    environment:\n      VIKUNJA_DATABASE_HOST: db\n      VIKUNJA_DATABASE_PASSWORD: {$dbPass}\n      VIKUNJA_DATABASE_TYPE: mysql\n      VIKUNJA_DATABASE_USER: vikunja\n      VIKUNJA_DATABASE_DATABASE: vikunja\n      VIKUNJA_SERVICE_JWTSECRET: " . bin2hex(random_bytes(16)) . "\n      VIKUNJA_SERVICE_FRONTENDURL: https://{$domain}/\n    volumes:\n      - vikunja_files:/app/vikunja/files\n    labels:\n      - 'novacpx.domain={$domain}'\nvolumes:\n  db_data:\n  vikunja_files:\n",

            'mattermost' => "version: '3.8'\nservices:\n  db:\n    image: postgres:16-alpine\n    restart: unless-stopped\n    environment:\n      POSTGRES_USER: mattermost\n      POSTGRES_PASSWORD: {$dbPass}\n      POSTGRES_DB: mattermost\n    volumes:\n      - db_data:/var/lib/postgresql/data\n  mattermost:\n    image: mattermost/mattermost-team-edition:latest\n    restart: unless-stopped\n    depends_on: [db]\n    environment:\n      MM_SQLSETTINGS_DRIVERNAME: postgres\n      MM_SQLSETTINGS_DATASOURCE: postgres://mattermost:{$dbPass}@db:5432/mattermost?sslmode=disable\n      MM_SERVICESETTINGS_SITEURL: https://{$domain}\n    volumes:\n      - mm_data:/mattermost/data\n      - mm_logs:/mattermost/logs\n      - mm_config:/mattermost/config\n    labels:\n      - 'novacpx.domain={$domain}'\nvolumes:\n  db_data:\n  mm_data:\n  mm_logs:\n  mm_config:\n",

            // ── Media & Files ──────────────────────────────────────────────────────

            'jellyfin' => "version: '3.8'\nservices:\n  jellyfin:\n    image: jellyfin/jellyfin:latest\n    restart: unless-stopped\n    volumes:\n      - jellyfin_config:/config\n      - jellyfin_cache:/cache\n    labels:\n      - 'novacpx.domain={$domain}'\nvolumes:\n  jellyfin_config:\n  jellyfin_cache:\n",

            'navidrome' => "version: '3.8'\nservices:\n  navidrome:\n    image: deluan/navidrome:latest\n    restart: unless-stopped\n    environment:\n      ND_SCANSCHEDULE: 1h\n      ND_LOGLEVEL: info\n      ND_BASEURL: ''\n    volumes:\n      - navidrome_data:/data\n    labels:\n      - 'novacpx.domain={$domain}'\nvolumes:\n  navidrome_data:\n",

            'kavita' => "version: '3.8'\nservices:\n  kavita:\n    image: jvmilazz0/kavita:latest\n    restart: unless-stopped\n    volumes:\n      - kavita_data:/kavita/config\n    labels:\n      - 'novacpx.domain={$domain}'\nvolumes:\n  kavita_data:\n",

            'paperless-ngx' => (function() use ($p, $domain, $dbPass, $adminPass, $adminUser): string {
                $secret = bin2hex(random_bytes(24));
                return "version: '3.8'\nservices:\n  db:\n    image: postgres:16-alpine\n    restart: unless-stopped\n    environment:\n      POSTGRES_DB: paperless\n      POSTGRES_USER: paperless\n      POSTGRES_PASSWORD: {$dbPass}\n    volumes:\n      - db_data:/var/lib/postgresql/data\n  redis:\n    image: redis:7-alpine\n    restart: unless-stopped\n  paperless:\n    image: ghcr.io/paperless-ngx/paperless-ngx:latest\n    restart: unless-stopped\n    depends_on: [db, redis]\n    environment:\n      PAPERLESS_REDIS: redis://redis:6379\n      PAPERLESS_DBHOST: db\n      PAPERLESS_DBNAME: paperless\n      PAPERLESS_DBUSER: paperless\n      PAPERLESS_DBPASS: {$dbPass}\n      PAPERLESS_URL: https://{$domain}\n      PAPERLESS_SECRET_KEY: {$secret}\n      PAPERLESS_ADMIN_USER: {$adminUser}\n      PAPERLESS_ADMIN_PASSWORD: {$adminPass}\n    volumes:\n      - paperless_data:/usr/src/paperless/data\n      - paperless_media:/usr/src/paperless/media\n      - paperless_consume:/usr/src/paperless/consume\n    labels:\n      - 'novacpx.domain={$domain}'\nvolumes:\n  db_data:\n  paperless_data:\n  paperless_media:\n  paperless_consume:\n";
            })(),

            'filebrowser' => "version: '3.8'\nservices:\n  filebrowser:\n    image: filebrowser/filebrowser:latest\n    restart: unless-stopped\n    volumes:\n      - /home:/srv\n      - filebrowser_db:/database\n    labels:\n      - 'novacpx.domain={$domain}'\nvolumes:\n  filebrowser_db:\n",

            'seafile' => (function() use ($p, $domain, $dbPass, $adminPass): string {
                $email = preg_replace('/[^a-zA-Z0-9@._\-]/', '', $p['admin_email'] ?? 'admin@' . $domain);
                return "version: '3.8'\nservices:\n  db:\n    image: mariadb:10.11\n    restart: unless-stopped\n    environment:\n      MYSQL_ROOT_PASSWORD: {$dbPass}\n      MYSQL_LOG_CONSOLE: 'true'\n    volumes:\n      - db_data:/var/lib/mysql\n  memcached:\n    image: memcached:1.6\n    restart: unless-stopped\n  seafile:\n    image: seafileltd/seafile-mc:latest\n    restart: unless-stopped\n    depends_on: [db, memcached]\n    environment:\n      DB_HOST: db\n      DB_ROOT_PASSWD: {$dbPass}\n      SEAFILE_ADMIN_EMAIL: {$email}\n      SEAFILE_ADMIN_PASSWORD: {$adminPass}\n      SEAFILE_SERVER_HOSTNAME: {$domain}\n      SEAFILE_SERVER_LETSENCRYPT: 'false'\n    volumes:\n      - seafile_data:/shared\n    labels:\n      - 'novacpx.domain={$domain}'\nvolumes:\n  db_data:\n  seafile_data:\n";
            })(),

            'immich' => "version: '3.8'\nservices:\n  db:\n    image: tensorchord/pgvecto-rs:pg16-v0.2.0\n    restart: unless-stopped\n    environment:\n      POSTGRES_DB: immich\n      POSTGRES_USER: postgres\n      POSTGRES_PASSWORD: {$dbPass}\n    volumes:\n      - db_data:/var/lib/postgresql/data\n  redis:\n    image: redis:7-alpine\n    restart: unless-stopped\n  immich-server:\n    image: ghcr.io/immich-app/immich-server:release\n    restart: unless-stopped\n    depends_on: [db, redis]\n    environment:\n      DB_HOSTNAME: db\n      DB_DATABASE_NAME: immich\n      DB_USERNAME: postgres\n      DB_PASSWORD: {$dbPass}\n      REDIS_HOSTNAME: redis\n    volumes:\n      - immich_upload:/usr/src/app/upload\n    labels:\n      - 'novacpx.domain={$domain}'\n  immich-machine-learning:\n    image: ghcr.io/immich-app/immich-machine-learning:release\n    restart: unless-stopped\n    volumes:\n      - immich_model_cache:/cache\nvolumes:\n  db_data:\n  immich_upload:\n  immich_model_cache:\n",

            // ── Monitoring & DevOps ────────────────────────────────────────────────

            'adminer' => "version: '3.8'\nservices:\n  adminer:\n    image: adminer:latest\n    restart: unless-stopped\n    labels:\n      - 'novacpx.domain={$domain}'\n",

            'grafana' => "version: '3.8'\nservices:\n  grafana:\n    image: grafana/grafana:latest\n    restart: unless-stopped\n    environment:\n      GF_SECURITY_ADMIN_USER: {$adminUser}\n      GF_SECURITY_ADMIN_PASSWORD: {$adminPass}\n      GF_SERVER_ROOT_URL: https://{$domain}\n    volumes:\n      - grafana_data:/var/lib/grafana\n    labels:\n      - 'novacpx.domain={$domain}'\nvolumes:\n  grafana_data:\n",

            'prometheus' => "version: '3.8'\nservices:\n  prometheus:\n    image: prom/prometheus:latest\n    restart: unless-stopped\n    command:\n      - '--config.file=/etc/prometheus/prometheus.yml'\n      - '--storage.tsdb.path=/prometheus'\n      - '--storage.tsdb.retention.time=30d'\n    volumes:\n      - prometheus_data:/prometheus\n    labels:\n      - 'novacpx.domain={$domain}'\nvolumes:\n  prometheus_data:\n",

            'netdata' => "version: '3.8'\nservices:\n  netdata:\n    image: netdata/netdata:latest\n    restart: unless-stopped\n    cap_add:\n      - SYS_PTRACE\n      - SYS_ADMIN\n    security_opt:\n      - apparmor:unconfined\n    volumes:\n      - netdata_config:/etc/netdata\n      - netdata_lib:/var/lib/netdata\n      - netdata_cache:/var/cache/netdata\n      - /proc:/host/proc:ro\n      - /sys:/host/sys:ro\n    labels:\n      - 'novacpx.domain={$domain}'\nvolumes:\n  netdata_config:\n  netdata_lib:\n  netdata_cache:\n",

            'glances' => "version: '3.8'\nservices:\n  glances:\n    image: nicolargo/glances:latest-full\n    restart: unless-stopped\n    environment:\n      GLANCES_OPT: -w\n    volumes:\n      - /var/run/docker.sock:/var/run/docker.sock:ro\n      - /proc:/proc:ro\n      - /sys:/sys:ro\n    labels:\n      - 'novacpx.domain={$domain}'\n",

            'healthchecks' => (function() use ($p, $domain, $dbPass, $adminPass): string {
                $email  = preg_replace('/[^a-zA-Z0-9@._\-]/', '', $p['admin_email'] ?? 'admin@' . $domain);
                $secret = bin2hex(random_bytes(24));
                return "version: '3.8'\nservices:\n  db:\n    image: postgres:16-alpine\n    restart: unless-stopped\n    environment:\n      POSTGRES_DB: healthchecks\n      POSTGRES_USER: healthchecks\n      POSTGRES_PASSWORD: {$dbPass}\n    volumes:\n      - db_data:/var/lib/postgresql/data\n  healthchecks:\n    image: healthchecks/healthchecks:latest\n    restart: unless-stopped\n    depends_on: [db]\n    environment:\n      SECRET_KEY: {$secret}\n      SITE_ROOT: https://{$domain}\n      DEFAULT_FROM_EMAIL: hc@{$domain}\n      DB: postgres\n      DB_HOST: db\n      DB_NAME: healthchecks\n      DB_USER: healthchecks\n      DB_PASSWORD: {$dbPass}\n      SUPERUSER_EMAIL: {$email}\n      SUPERUSER_PASSWORD: {$adminPass}\n    labels:\n      - 'novacpx.domain={$domain}'\nvolumes:\n  db_data:\n";
            })(),

            'registry' => "version: '3.8'\nservices:\n  registry:\n    image: registry:2\n    restart: unless-stopped\n    volumes:\n      - registry_data:/var/lib/registry\n    labels:\n      - 'novacpx.domain={$domain}'\nvolumes:\n  registry_data:\n",

            'verdaccio' => "version: '3.8'\nservices:\n  verdaccio:\n    image: verdaccio/verdaccio:latest\n    restart: unless-stopped\n    volumes:\n      - verdaccio_storage:/verdaccio/storage\n      - verdaccio_conf:/verdaccio/conf\n      - verdaccio_plugins:/verdaccio/plugins\n    labels:\n      - 'novacpx.domain={$domain}'\nvolumes:\n  verdaccio_storage:\n  verdaccio_conf:\n  verdaccio_plugins:\n",

            'watchtower' => "version: '3.8'\nservices:\n  watchtower:\n    image: containrrr/watchtower:latest\n    restart: unless-stopped\n    volumes:\n      - /var/run/docker.sock:/var/run/docker.sock\n    labels:\n      - 'novacpx.domain={$domain}'\n",

            // ── Git & CI/CD ────────────────────────────────────────────────────────

            'forgejo' => "version: '3.8'\nservices:\n  db:\n    image: mariadb:10.11\n    restart: unless-stopped\n    environment:\n      MYSQL_ROOT_PASSWORD: {$dbPass}\n      MYSQL_DATABASE: forgejo\n      MYSQL_USER: forgejo\n      MYSQL_PASSWORD: {$dbPass}\n    volumes:\n      - db_data:/var/lib/mysql\n  forgejo:\n    image: codeberg.org/forgejo/forgejo:latest\n    restart: unless-stopped\n    depends_on: [db]\n    environment:\n      USER_UID: '1000'\n      USER_GID: '1000'\n      FORGEJO__database__DB_TYPE: mysql\n      FORGEJO__database__HOST: db:3306\n      FORGEJO__database__NAME: forgejo\n      FORGEJO__database__USER: forgejo\n      FORGEJO__database__PASSWD: {$dbPass}\n    volumes:\n      - forgejo_data:/data\n    labels:\n      - 'novacpx.domain={$domain}'\nvolumes:\n  db_data:\n  forgejo_data:\n",

            'woodpecker-ci' => (function() use ($domain, $adminUser): string {
                $secret = bin2hex(random_bytes(16));
                return "version: '3.8'\nservices:\n  woodpecker-server:\n    image: woodpeckerci/woodpecker-server:latest\n    restart: unless-stopped\n    environment:\n      WOODPECKER_OPEN: 'true'\n      WOODPECKER_ADMIN: {$adminUser}\n      WOODPECKER_AGENT_SECRET: {$secret}\n      WOODPECKER_HOST: https://{$domain}\n    volumes:\n      - woodpecker_data:/var/lib/woodpecker\n    labels:\n      - 'novacpx.domain={$domain}'\n  woodpecker-agent:\n    image: woodpeckerci/woodpecker-agent:latest\n    restart: unless-stopped\n    depends_on: [woodpecker-server]\n    environment:\n      WOODPECKER_SERVER: woodpecker-server:9000\n      WOODPECKER_AGENT_SECRET: {$secret}\n    volumes:\n      - /var/run/docker.sock:/var/run/docker.sock\nvolumes:\n  woodpecker_data:\n";
            })(),

            // ── Knowledge & Publishing ─────────────────────────────────────────────

            'bookstack' => "version: '3.8'\nservices:\n  db:\n    image: mariadb:10.11\n    restart: unless-stopped\n    environment:\n      MYSQL_ROOT_PASSWORD: {$dbPass}\n      MYSQL_DATABASE: bookstack\n      MYSQL_USER: bookstack\n      MYSQL_PASSWORD: {$dbPass}\n    volumes:\n      - db_data:/var/lib/mysql\n  bookstack:\n    image: lscr.io/linuxserver/bookstack:latest\n    restart: unless-stopped\n    depends_on: [db]\n    environment:\n      APP_URL: https://{$domain}\n      DB_HOST: db\n      DB_DATABASE: bookstack\n      DB_USERNAME: bookstack\n      DB_PASSWORD: {$dbPass}\n    volumes:\n      - bookstack_data:/config\n    labels:\n      - 'novacpx.domain={$domain}'\nvolumes:\n  db_data:\n  bookstack_data:\n",

            'hedgedoc' => (function() use ($domain, $dbPass): string {
                $secret = bin2hex(random_bytes(16));
                return "version: '3.8'\nservices:\n  db:\n    image: postgres:16-alpine\n    restart: unless-stopped\n    environment:\n      POSTGRES_DB: hedgedoc\n      POSTGRES_USER: hedgedoc\n      POSTGRES_PASSWORD: {$dbPass}\n    volumes:\n      - db_data:/var/lib/postgresql/data\n  hedgedoc:\n    image: quay.io/hedgedoc/hedgedoc:latest\n    restart: unless-stopped\n    depends_on: [db]\n    environment:\n      CMD_DOMAIN: {$domain}\n      CMD_URL_ADDPORT: 'false'\n      CMD_PROTOCOL_USESSL: 'true'\n      CMD_DB_URL: postgres://hedgedoc:{$dbPass}@db:5432/hedgedoc\n      CMD_SESSION_SECRET: {$secret}\n      CMD_ALLOW_ANONYMOUS: 'true'\n    volumes:\n      - hedgedoc_uploads:/hedgedoc/public/uploads\n    labels:\n      - 'novacpx.domain={$domain}'\nvolumes:\n  db_data:\n  hedgedoc_uploads:\n";
            })(),

            'freshrss' => "version: '3.8'\nservices:\n  freshrss:\n    image: freshrss/freshrss:latest\n    restart: unless-stopped\n    environment:\n      CRON_MIN: '*/15'\n      TZ: UTC\n    volumes:\n      - freshrss_data:/var/www/FreshRSS/data\n      - freshrss_extensions:/var/www/FreshRSS/extensions\n    labels:\n      - 'novacpx.domain={$domain}'\nvolumes:\n  freshrss_data:\n  freshrss_extensions:\n",

            'wallabag' => (function() use ($domain, $dbPass): string {
                $secret = bin2hex(random_bytes(16));
                return "version: '3.8'\nservices:\n  db:\n    image: mariadb:10.11\n    restart: unless-stopped\n    environment:\n      MYSQL_ROOT_PASSWORD: {$dbPass}\n      MYSQL_DATABASE: wallabag\n      MYSQL_USER: wallabag\n      MYSQL_PASSWORD: {$dbPass}\n    volumes:\n      - db_data:/var/lib/mysql\n  wallabag:\n    image: wallabag/wallabag:latest\n    restart: unless-stopped\n    depends_on: [db]\n    environment:\n      SYMFONY__ENV__DATABASE_DRIVER: pdo_mysql\n      SYMFONY__ENV__DATABASE_HOST: db\n      SYMFONY__ENV__DATABASE_NAME: wallabag\n      SYMFONY__ENV__DATABASE_USER: wallabag\n      SYMFONY__ENV__DATABASE_PASSWORD: {$dbPass}\n      SYMFONY__ENV__DOMAIN_NAME: https://{$domain}\n      SYMFONY__ENV__SECRET: {$secret}\n    volumes:\n      - wallabag_data:/var/www/wallabag/web/assets/images\n    labels:\n      - 'novacpx.domain={$domain}'\nvolumes:\n  db_data:\n  wallabag_data:\n";
            })(),

            'homepage' => "version: '3.8'\nservices:\n  homepage:\n    image: ghcr.io/gethomepage/homepage:latest\n    restart: unless-stopped\n    volumes:\n      - homepage_config:/app/config\n      - /var/run/docker.sock:/var/run/docker.sock:ro\n    labels:\n      - 'novacpx.domain={$domain}'\nvolumes:\n  homepage_config:\n",

            // ── Auth & Security ────────────────────────────────────────────────────

            'keycloak' => "version: '3.8'\nservices:\n  db:\n    image: postgres:16-alpine\n    restart: unless-stopped\n    environment:\n      POSTGRES_DB: keycloak\n      POSTGRES_USER: keycloak\n      POSTGRES_PASSWORD: {$dbPass}\n    volumes:\n      - db_data:/var/lib/postgresql/data\n  keycloak:\n    image: quay.io/keycloak/keycloak:latest\n    restart: unless-stopped\n    depends_on: [db]\n    command: start\n    environment:\n      KC_DB: postgres\n      KC_DB_URL: jdbc:postgresql://db:5432/keycloak\n      KC_DB_USERNAME: keycloak\n      KC_DB_PASSWORD: {$dbPass}\n      KC_HOSTNAME: {$domain}\n      KEYCLOAK_ADMIN: {$adminUser}\n      KEYCLOAK_ADMIN_PASSWORD: {$adminPass}\n      KC_PROXY: edge\n    labels:\n      - 'novacpx.domain={$domain}'\nvolumes:\n  db_data:\n",

            'authentik' => (function() use ($domain, $dbPass): string {
                $secret = bin2hex(random_bytes(24));
                $img    = 'ghcr.io/goauthentik/server:latest';
                $env    = "AUTHENTIK_REDIS__HOST: redis\n      AUTHENTIK_POSTGRESQL__HOST: db\n      AUTHENTIK_POSTGRESQL__NAME: authentik\n      AUTHENTIK_POSTGRESQL__USER: authentik\n      AUTHENTIK_POSTGRESQL__PASSWORD: {$dbPass}\n      AUTHENTIK_SECRET_KEY: {$secret}";
                return "version: '3.8'\nservices:\n  db:\n    image: postgres:16-alpine\n    restart: unless-stopped\n    environment:\n      POSTGRES_DB: authentik\n      POSTGRES_USER: authentik\n      POSTGRES_PASSWORD: {$dbPass}\n    volumes:\n      - db_data:/var/lib/postgresql/data\n  redis:\n    image: redis:7-alpine\n    restart: unless-stopped\n  server:\n    image: {$img}\n    restart: unless-stopped\n    command: server\n    depends_on: [db, redis]\n    environment:\n      {$env}\n    volumes:\n      - authentik_media:/media\n      - authentik_templates:/templates\n    labels:\n      - 'novacpx.domain={$domain}'\n  worker:\n    image: {$img}\n    restart: unless-stopped\n    command: worker\n    depends_on: [db, redis]\n    environment:\n      {$env}\n    volumes:\n      - authentik_media:/media\nvolumes:\n  db_data:\n  authentik_media:\n  authentik_templates:\n";
            })(),

            'passbolt' => "version: '3.8'\nservices:\n  db:\n    image: mariadb:10.11\n    restart: unless-stopped\n    environment:\n      MYSQL_ROOT_PASSWORD: {$dbPass}\n      MYSQL_DATABASE: passbolt\n      MYSQL_USER: passbolt\n      MYSQL_PASSWORD: {$dbPass}\n    volumes:\n      - db_data:/var/lib/mysql\n  passbolt:\n    image: passbolt/passbolt:latest-ce\n    restart: unless-stopped\n    depends_on: [db]\n    environment:\n      APP_FULL_BASE_URL: https://{$domain}\n      DATASOURCES_DEFAULT_HOST: db\n      DATASOURCES_DEFAULT_DATABASE: passbolt\n      DATASOURCES_DEFAULT_USERNAME: passbolt\n      DATASOURCES_DEFAULT_PASSWORD: {$dbPass}\n    volumes:\n      - passbolt_gpg:/etc/passbolt/gpg\n      - passbolt_jwt:/etc/passbolt/jwt\n    labels:\n      - 'novacpx.domain={$domain}'\nvolumes:\n  db_data:\n  passbolt_gpg:\n  passbolt_jwt:\n",

            // ── Analytics ─────────────────────────────────────────────────────────

            'plausible' => (function() use ($domain, $dbPass): string {
                $secret = bin2hex(random_bytes(24));
                return "version: '3.8'\nservices:\n  db:\n    image: postgres:16-alpine\n    restart: unless-stopped\n    environment:\n      POSTGRES_DB: plausible\n      POSTGRES_USER: plausible\n      POSTGRES_PASSWORD: {$dbPass}\n    volumes:\n      - db_data:/var/lib/postgresql/data\n  clickhouse:\n    image: clickhouse/clickhouse-server:latest\n    restart: unless-stopped\n    volumes:\n      - clickhouse_data:/var/lib/clickhouse\n  plausible:\n    image: ghcr.io/plausible/community-edition:v2\n    restart: unless-stopped\n    depends_on: [db, clickhouse]\n    command: sh -c \"sleep 10 && /entrypoint.sh db createdb && /entrypoint.sh db migrate && /entrypoint.sh run\"\n    environment:\n      BASE_URL: https://{$domain}\n      SECRET_KEY_BASE: {$secret}\n      DATABASE_URL: postgres://plausible:{$dbPass}@db:5432/plausible\n      CLICKHOUSE_DATABASE_URL: http://clickhouse:8123/plausible_events_db\n    labels:\n      - 'novacpx.domain={$domain}'\nvolumes:\n  db_data:\n  clickhouse_data:\n";
            })(),

            // ── Low-code & No-code ─────────────────────────────────────────────────

            'baserow' => "version: '3.8'\nservices:\n  db:\n    image: postgres:16-alpine\n    restart: unless-stopped\n    environment:\n      POSTGRES_DB: baserow\n      POSTGRES_USER: baserow\n      POSTGRES_PASSWORD: {$dbPass}\n    volumes:\n      - db_data:/var/lib/postgresql/data\n  baserow:\n    image: baserow/baserow:latest\n    restart: unless-stopped\n    depends_on: [db]\n    environment:\n      BASEROW_PUBLIC_URL: https://{$domain}\n      DATABASE_HOST: db\n      DATABASE_NAME: baserow\n      DATABASE_USER: baserow\n      DATABASE_PASSWORD: {$dbPass}\n    volumes:\n      - baserow_data:/baserow/data\n    labels:\n      - 'novacpx.domain={$domain}'\nvolumes:\n  db_data:\n  baserow_data:\n",

            'appsmith' => "version: '3.8'\nservices:\n  appsmith:\n    image: appsmith/appsmith-ce:latest\n    restart: unless-stopped\n    volumes:\n      - appsmith_data:/appsmith-stacks\n    labels:\n      - 'novacpx.domain={$domain}'\nvolumes:\n  appsmith_data:\n",

            'nocodb' => "version: '3.8'\nservices:\n  nocodb:\n    image: nocodb/nocodb:latest\n    restart: unless-stopped\n    volumes:\n      - nocodb_data:/usr/app/data\n    labels:\n      - 'novacpx.domain={$domain}'\nvolumes:\n  nocodb_data:\n",

            // ── Communication & Support ────────────────────────────────────────────

            'rocketchat' => "version: '3.8'\nservices:\n  mongodb:\n    image: mongo:7.0\n    restart: unless-stopped\n    command: ['--replSet', 'rs0', '--bind_ip_all']\n    volumes:\n      - mongo_data:/data/db\n  mongodb-init:\n    image: mongo:7.0\n    restart: on-failure\n    depends_on: [mongodb]\n    command: >-\n      mongosh --host mongodb:27017 --eval\n      \"rs.initiate({_id:'rs0',members:[{_id:0,host:'mongodb:27017'}]})\"\n  rocketchat:\n    image: registry.rocket.chat/rocketchat:latest\n    restart: unless-stopped\n    depends_on: [mongodb]\n    environment:\n      ROOT_URL: https://{$domain}\n      MONGO_URL: mongodb://mongodb:27017/rocketchat?replicaSet=rs0\n      MONGO_OPLOG_URL: mongodb://mongodb:27017/local?replicaSet=rs0\n    volumes:\n      - rocketchat_uploads:/app/uploads\n    labels:\n      - 'novacpx.domain={$domain}'\nvolumes:\n  mongo_data:\n  rocketchat_uploads:\n",

            'chatwoot' => (function() use ($domain, $dbPass): string {
                $secret = bin2hex(random_bytes(32));
                $envBlock = "SECRET_KEY_BASE: {$secret}\n      FRONTEND_URL: https://{$domain}\n      DATABASE_URL: postgres://chatwoot:{$dbPass}@db:5432/chatwoot\n      REDIS_URL: redis://redis:6379";
                return "version: '3.8'\nservices:\n  db:\n    image: postgres:16-alpine\n    restart: unless-stopped\n    environment:\n      POSTGRES_DB: chatwoot\n      POSTGRES_USER: chatwoot\n      POSTGRES_PASSWORD: {$dbPass}\n    volumes:\n      - db_data:/var/lib/postgresql/data\n  redis:\n    image: redis:7-alpine\n    restart: unless-stopped\n  chatwoot:\n    image: chatwoot/chatwoot:latest\n    restart: unless-stopped\n    depends_on: [db, redis]\n    command: bundle exec rails s\n    environment:\n      {$envBlock}\n    volumes:\n      - chatwoot_storage:/app/storage\n    labels:\n      - 'novacpx.domain={$domain}'\n  sidekiq:\n    image: chatwoot/chatwoot:latest\n    restart: unless-stopped\n    depends_on: [db, redis]\n    command: bundle exec sidekiq\n    environment:\n      {$envBlock}\n    volumes:\n      - chatwoot_storage:/app/storage\nvolumes:\n  db_data:\n  chatwoot_storage:\n";
            })(),

            'zammad' => "version: '3.8'\nservices:\n  db:\n    image: postgres:16-alpine\n    restart: unless-stopped\n    environment:\n      POSTGRES_DB: zammad\n      POSTGRES_USER: zammad\n      POSTGRES_PASSWORD: {$dbPass}\n    volumes:\n      - db_data:/var/lib/postgresql/data\n  zammad-railsserver:\n    image: zammad/zammad-docker-compose:latest\n    restart: unless-stopped\n    depends_on: [db]\n    command: zammad-railsserver\n    environment:\n      POSTGRESQL_HOST: db\n      POSTGRESQL_DB: zammad\n      POSTGRESQL_USER: zammad\n      POSTGRESQL_PASS: {$dbPass}\n    volumes:\n      - zammad_data:/opt/zammad\n    labels:\n      - 'novacpx.domain={$domain}'\nvolumes:\n  db_data:\n  zammad_data:\n",

            // ── Business & Productivity ────────────────────────────────────────────

            'invoiceninja' => (function() use ($domain, $dbPass): string {
                $key = bin2hex(random_bytes(16));
                return "version: '3.8'\nservices:\n  db:\n    image: mariadb:10.11\n    restart: unless-stopped\n    environment:\n      MYSQL_ROOT_PASSWORD: {$dbPass}\n      MYSQL_DATABASE: invoiceninja\n      MYSQL_USER: invoiceninja\n      MYSQL_PASSWORD: {$dbPass}\n    volumes:\n      - db_data:/var/lib/mysql\n  app:\n    image: invoiceninja/invoiceninja:latest\n    restart: unless-stopped\n    depends_on: [db]\n    environment:\n      APP_URL: https://{$domain}\n      APP_KEY: base64:{$key}\n      DB_HOST: db\n      DB_DATABASE: invoiceninja\n      DB_USERNAME: invoiceninja\n      DB_PASSWORD: {$dbPass}\n    volumes:\n      - invoiceninja_public:/var/www/app/public\n      - invoiceninja_storage:/var/www/app/storage\n    labels:\n      - 'novacpx.domain={$domain}'\nvolumes:\n  db_data:\n  invoiceninja_public:\n  invoiceninja_storage:\n";
            })(),

            'linkding' => "version: '3.8'\nservices:\n  linkding:\n    image: sissbruecker/linkding:latest\n    restart: unless-stopped\n    environment:\n      LD_SUPERUSER_NAME: {$adminUser}\n      LD_SUPERUSER_PASSWORD: {$adminPass}\n    volumes:\n      - linkding_data:/etc/linkding/data\n    labels:\n      - 'novacpx.domain={$domain}'\nvolumes:\n  linkding_data:\n",

            'mealie' => (function() use ($p, $domain, $adminPass): string {
                $email = preg_replace('/[^a-zA-Z0-9@._\-]/', '', $p['admin_email'] ?? 'admin@' . $domain);
                return "version: '3.8'\nservices:\n  mealie:\n    image: ghcr.io/mealie-recipes/mealie:latest\n    restart: unless-stopped\n    environment:\n      ALLOW_SIGNUP: 'true'\n      BASE_URL: https://{$domain}\n      DEFAULT_EMAIL: {$email}\n      DEFAULT_PASSWORD: {$adminPass}\n    volumes:\n      - mealie_data:/app/data\n    labels:\n      - 'novacpx.domain={$domain}'\nvolumes:\n  mealie_data:\n";
            })(),

            // ── Design & Collaboration ─────────────────────────────────────────────

            'penpot' => (function() use ($domain, $dbPass): string {
                $secret = bin2hex(random_bytes(16));
                return "version: '3.8'\nservices:\n  db:\n    image: postgres:16-alpine\n    restart: unless-stopped\n    environment:\n      POSTGRES_DB: penpot\n      POSTGRES_USER: penpot\n      POSTGRES_PASSWORD: {$dbPass}\n    volumes:\n      - db_data:/var/lib/postgresql/data\n  redis:\n    image: redis:7-alpine\n    restart: unless-stopped\n  penpot-frontend:\n    image: penpotapp/frontend:latest\n    restart: unless-stopped\n    volumes:\n      - penpot_assets:/opt/data/assets\n    labels:\n      - 'novacpx.domain={$domain}'\n  penpot-backend:\n    image: penpotapp/backend:latest\n    restart: unless-stopped\n    depends_on: [db, redis]\n    environment:\n      PENPOT_DATABASE_URI: postgresql://db:5432/penpot\n      PENPOT_DATABASE_USERNAME: penpot\n      PENPOT_DATABASE_PASSWORD: {$dbPass}\n      PENPOT_REDIS_URI: redis://redis:6379/0\n      PENPOT_PUBLIC_URI: https://{$domain}\n      PENPOT_SECRET_KEY: {$secret}\n      PENPOT_FLAGS: 'enable-registration enable-login'\n    volumes:\n      - penpot_assets:/opt/data/assets\n  penpot-exporter:\n    image: penpotapp/exporter:latest\n    restart: unless-stopped\n    environment:\n      PENPOT_PUBLIC_URI: http://penpot-frontend\n      PENPOT_SECRET_KEY: {$secret}\nvolumes:\n  db_data:\n  penpot_assets:\n";
            })(),

            'excalidraw' => "version: '3.8'\nservices:\n  excalidraw:\n    image: excalidraw/excalidraw:latest\n    restart: unless-stopped\n    labels:\n      - 'novacpx.domain={$domain}'\n",

            'stirlingpdf' => "version: '3.8'\nservices:\n  stirlingpdf:\n    image: frooodle/s-pdf:latest\n    restart: unless-stopped\n    environment:\n      DOCKER_ENABLE_SECURITY: 'false'\n    volumes:\n      - stirlingpdf_tessdata:/usr/share/tessdata\n      - stirlingpdf_config:/configs\n    labels:\n      - 'novacpx.domain={$domain}'\nvolumes:\n  stirlingpdf_tessdata:\n  stirlingpdf_config:\n",

            // ── AI / LLM ──────────────────────────────────────────────────────────

            'ollama' => "version: '3.8'\nservices:\n  ollama:\n    image: ollama/ollama:latest\n    restart: unless-stopped\n    volumes:\n      - ollama_data:/root/.ollama\n    labels:\n      - 'novacpx.domain={$domain}'\nvolumes:\n  ollama_data:\n",

            'open-webui' => (function() use ($p, $domain): string {
                $ollamaUrl = preg_replace('/[^a-zA-Z0-9.:\/\-_]/', '', $p['ollama_url'] ?? 'http://ollama:11434');
                return "version: '3.8'\nservices:\n  open-webui:\n    image: ghcr.io/open-webui/open-webui:main\n    restart: unless-stopped\n    environment:\n      OLLAMA_BASE_URL: {$ollamaUrl}\n    volumes:\n      - open_webui_data:/app/backend/data\n    labels:\n      - 'novacpx.domain={$domain}'\nvolumes:\n  open_webui_data:\n";
            })(),

            'flowise' => (function() use ($domain, $adminUser, $adminPass): string {
                return "version: '3.8'\nservices:\n  flowise:\n    image: flowiseai/flowise:latest\n    restart: unless-stopped\n    environment:\n      FLOWISE_USERNAME: {$adminUser}\n      FLOWISE_PASSWORD: {$adminPass}\n    volumes:\n      - flowise_data:/root/.flowise\n    labels:\n      - 'novacpx.domain={$domain}'\nvolumes:\n  flowise_data:\n";
            })(),

            'langfuse' => (function() use ($domain, $dbPass): string {
                $secret = bin2hex(random_bytes(16));
                $salt   = bin2hex(random_bytes(8));
                return "version: '3.8'\nservices:\n  db:\n    image: postgres:16-alpine\n    restart: unless-stopped\n    environment:\n      POSTGRES_DB: langfuse\n      POSTGRES_USER: langfuse\n      POSTGRES_PASSWORD: {$dbPass}\n    volumes:\n      - db_data:/var/lib/postgresql/data\n  langfuse:\n    image: langfuse/langfuse:latest\n    restart: unless-stopped\n    depends_on: [db]\n    environment:\n      DATABASE_URL: postgresql://langfuse:{$dbPass}@db:5432/langfuse\n      NEXTAUTH_URL: https://{$domain}\n      NEXTAUTH_SECRET: {$secret}\n      SALT: {$salt}\n    labels:\n      - 'novacpx.domain={$domain}'\nvolumes:\n  db_data:\n";
            })(),

            'anythingllm' => "version: '3.8'\nservices:\n  anythingllm:\n    image: mintplexlabs/anythingllm:latest\n    restart: unless-stopped\n    cap_add: [SYS_ADMIN]\n    volumes:\n      - anythingllm_data:/app/server/storage\n    labels:\n      - 'novacpx.domain={$domain}'\nvolumes:\n  anythingllm_data:\n",

            'localai' => "version: '3.8'\nservices:\n  localai:\n    image: localai/localai:latest-cpu\n    restart: unless-stopped\n    volumes:\n      - localai_models:/build/models\n    labels:\n      - 'novacpx.domain={$domain}'\nvolumes:\n  localai_models:\n",

            'comfyui' => "version: '3.8'\nservices:\n  comfyui:\n    image: yanwk/comfyui-boot:latest\n    restart: unless-stopped\n    volumes:\n      - comfyui_data:/root\n    labels:\n      - 'novacpx.domain={$domain}'\nvolumes:\n  comfyui_data:\n",

            // ── Developer Tools ───────────────────────────────────────────────────

            'code-server' => "version: '3.8'\nservices:\n  code-server:\n    image: codercom/code-server:latest\n    restart: unless-stopped\n    environment:\n      PASSWORD: {$adminPass}\n    volumes:\n      - code_server_data:/home/coder\n    labels:\n      - 'novacpx.domain={$domain}'\nvolumes:\n  code_server_data:\n",

            'jenkins' => "version: '3.8'\nservices:\n  jenkins:\n    image: jenkins/jenkins:lts-jdk21\n    restart: unless-stopped\n    user: root\n    volumes:\n      - jenkins_home:/var/jenkins_home\n      - /var/run/docker.sock:/var/run/docker.sock\n    labels:\n      - 'novacpx.domain={$domain}'\nvolumes:\n  jenkins_home:\n",

            'sonarqube' => (function() use ($domain, $dbPass): string {
                return "version: '3.8'\nservices:\n  db:\n    image: postgres:16-alpine\n    restart: unless-stopped\n    environment:\n      POSTGRES_DB: sonarqube\n      POSTGRES_USER: sonarqube\n      POSTGRES_PASSWORD: {$dbPass}\n    volumes:\n      - db_data:/var/lib/postgresql/data\n  sonarqube:\n    image: sonarqube:community\n    restart: unless-stopped\n    depends_on: [db]\n    environment:\n      SONAR_JDBC_URL: jdbc:postgresql://db:5432/sonarqube\n      SONAR_JDBC_USERNAME: sonarqube\n      SONAR_JDBC_PASSWORD: {$dbPass}\n    volumes:\n      - sonarqube_data:/opt/sonarqube/data\n      - sonarqube_extensions:/opt/sonarqube/extensions\n      - sonarqube_logs:/opt/sonarqube/logs\n    labels:\n      - 'novacpx.domain={$domain}'\nvolumes:\n  db_data:\n  sonarqube_data:\n  sonarqube_extensions:\n  sonarqube_logs:\n";
            })(),

            'vault' => "version: '3.8'\nservices:\n  vault:\n    image: hashicorp/vault:latest\n    restart: unless-stopped\n    cap_add: [IPC_LOCK]\n    environment:\n      VAULT_DEV_ROOT_TOKEN_ID: root\n      VAULT_DEV_LISTEN_ADDRESS: 0.0.0.0:8200\n    volumes:\n      - vault_data:/vault/data\n    labels:\n      - 'novacpx.domain={$domain}'\nvolumes:\n  vault_data:\n",

            'mailhog' => "version: '3.8'\nservices:\n  mailhog:\n    image: mailhog/mailhog:latest\n    restart: unless-stopped\n    labels:\n      - 'novacpx.domain={$domain}'\n",

            'dozzle' => "version: '3.8'\nservices:\n  dozzle:\n    image: amir20/dozzle:latest\n    restart: unless-stopped\n    volumes:\n      - /var/run/docker.sock:/var/run/docker.sock:ro\n    labels:\n      - 'novacpx.domain={$domain}'\n",

            'yacht' => (function() use ($p, $domain, $adminPass): string {
                $email = preg_replace('/[^a-zA-Z0-9@._\-]/', '', $p['admin_email'] ?? 'admin@' . $domain);
                return "version: '3.8'\nservices:\n  yacht:\n    image: selfhostedpro/yacht:latest\n    restart: unless-stopped\n    environment:\n      ADMIN_EMAIL: {$email}\n      ADMIN_PASSWORD: {$adminPass}\n    volumes:\n      - yacht_data:/config\n      - /var/run/docker.sock:/var/run/docker.sock\n    labels:\n      - 'novacpx.domain={$domain}'\nvolumes:\n  yacht_data:\n";
            })(),

            'semaphore' => (function() use ($domain, $adminUser, $adminPass, $dbPass): string {
                $key = bin2hex(random_bytes(16));
                return "version: '3.8'\nservices:\n  db:\n    image: postgres:16-alpine\n    restart: unless-stopped\n    environment:\n      POSTGRES_DB: semaphore\n      POSTGRES_USER: semaphore\n      POSTGRES_PASSWORD: {$dbPass}\n    volumes:\n      - db_data:/var/lib/postgresql/data\n  semaphore:\n    image: semaphoreui/semaphore:latest\n    restart: unless-stopped\n    depends_on: [db]\n    environment:\n      SEMAPHORE_DB_DIALECT: postgres\n      SEMAPHORE_DB_HOST: db\n      SEMAPHORE_DB_PORT: 5432\n      SEMAPHORE_DB_NAME: semaphore\n      SEMAPHORE_DB_USER: semaphore\n      SEMAPHORE_DB_PASS: {$dbPass}\n      SEMAPHORE_ADMIN: {$adminUser}\n      SEMAPHORE_ADMIN_PASSWORD: {$adminPass}\n      SEMAPHORE_ADMIN_EMAIL: admin@{$domain}\n      SEMAPHORE_ACCESS_KEY_ENCRYPTION: {$key}\n    volumes:\n      - semaphore_data:/home/semaphore\n    labels:\n      - 'novacpx.domain={$domain}'\nvolumes:\n  db_data:\n  semaphore_data:\n";
            })(),

            // ── Databases ─────────────────────────────────────────────────────────

            'redis-standalone' => "version: '3.8'\nservices:\n  redis:\n    image: redis:7-alpine\n    restart: unless-stopped\n    command: redis-server --requirepass {$adminPass}\n    volumes:\n      - redis_data:/data\n  redis-commander:\n    image: rediscommander/redis-commander:latest\n    restart: unless-stopped\n    environment:\n      REDIS_HOSTS: 'local:redis:6379:0:{$adminPass}'\n    labels:\n      - 'novacpx.domain={$domain}'\nvolumes:\n  redis_data:\n",

            'mongodb' => "version: '3.8'\nservices:\n  mongo:\n    image: mongo:7\n    restart: unless-stopped\n    environment:\n      MONGO_INITDB_ROOT_USERNAME: {$adminUser}\n      MONGO_INITDB_ROOT_PASSWORD: {$adminPass}\n    volumes:\n      - mongo_data:/data/db\n  mongo-express:\n    image: mongo-express:latest\n    restart: unless-stopped\n    depends_on: [mongo]\n    environment:\n      ME_CONFIG_MONGODB_ADMINUSERNAME: {$adminUser}\n      ME_CONFIG_MONGODB_ADMINPASSWORD: {$adminPass}\n      ME_CONFIG_MONGODB_URL: mongodb://{$adminUser}:{$adminPass}@mongo:27017/\n      ME_CONFIG_BASICAUTH: 'false'\n    labels:\n      - 'novacpx.domain={$domain}'\nvolumes:\n  mongo_data:\n",

            'postgresql' => (function() use ($p, $domain, $dbPass, $adminPass): string {
                $email = preg_replace('/[^a-zA-Z0-9@._\-]/', '', $p['admin_email'] ?? 'admin@' . $domain);
                return "version: '3.8'\nservices:\n  db:\n    image: postgres:16-alpine\n    restart: unless-stopped\n    environment:\n      POSTGRES_USER: postgres\n      POSTGRES_PASSWORD: {$dbPass}\n    volumes:\n      - db_data:/var/lib/postgresql/data\n  pgadmin:\n    image: dpage/pgadmin4:latest\n    restart: unless-stopped\n    depends_on: [db]\n    environment:\n      PGADMIN_DEFAULT_EMAIL: {$email}\n      PGADMIN_DEFAULT_PASSWORD: {$adminPass}\n    volumes:\n      - pgadmin_data:/var/lib/pgadmin\n    labels:\n      - 'novacpx.domain={$domain}'\nvolumes:\n  db_data:\n  pgadmin_data:\n";
            })(),

            'mariadb' => "version: '3.8'\nservices:\n  db:\n    image: mariadb:11\n    restart: unless-stopped\n    environment:\n      MARIADB_ROOT_PASSWORD: {$dbPass}\n    volumes:\n      - db_data:/var/lib/mysql\n  phpmyadmin:\n    image: phpmyadmin:latest\n    restart: unless-stopped\n    depends_on: [db]\n    environment:\n      PMA_HOST: db\n      MYSQL_ROOT_PASSWORD: {$dbPass}\n    labels:\n      - 'novacpx.domain={$domain}'\nvolumes:\n  db_data:\n",

            'elasticsearch' => "version: '3.8'\nservices:\n  elasticsearch:\n    image: elasticsearch:8.13.0\n    restart: unless-stopped\n    environment:\n      discovery.type: single-node\n      ELASTIC_PASSWORD: {$adminPass}\n      xpack.security.enabled: 'true'\n    volumes:\n      - es_data:/usr/share/elasticsearch/data\n  kibana:\n    image: kibana:8.13.0\n    restart: unless-stopped\n    depends_on: [elasticsearch]\n    environment:\n      ELASTICSEARCH_HOSTS: http://elasticsearch:9200\n      ELASTICSEARCH_USERNAME: elastic\n      ELASTICSEARCH_PASSWORD: {$adminPass}\n    labels:\n      - 'novacpx.domain={$domain}'\nvolumes:\n  es_data:\n",

            'influxdb' => (function() use ($p, $domain, $adminUser, $adminPass): string {
                $email = preg_replace('/[^a-zA-Z0-9@._\-]/', '', $p['admin_email'] ?? 'admin@' . $domain);
                $token = bin2hex(random_bytes(32));
                return "version: '3.8'\nservices:\n  influxdb:\n    image: influxdb:2.7\n    restart: unless-stopped\n    environment:\n      DOCKER_INFLUXDB_INIT_MODE: setup\n      DOCKER_INFLUXDB_INIT_USERNAME: {$adminUser}\n      DOCKER_INFLUXDB_INIT_PASSWORD: {$adminPass}\n      DOCKER_INFLUXDB_INIT_ORG: novacpx\n      DOCKER_INFLUXDB_INIT_BUCKET: default\n      DOCKER_INFLUXDB_INIT_ADMIN_TOKEN: {$token}\n    volumes:\n      - influxdb_data:/var/lib/influxdb2\n    labels:\n      - 'novacpx.domain={$domain}'\nvolumes:\n  influxdb_data:\n";
            })(),

            'neo4j' => "version: '3.8'\nservices:\n  neo4j:\n    image: neo4j:5\n    restart: unless-stopped\n    environment:\n      NEO4J_AUTH: neo4j/{$adminPass}\n    volumes:\n      - neo4j_data:/data\n      - neo4j_logs:/logs\n    labels:\n      - 'novacpx.domain={$domain}'\nvolumes:\n  neo4j_data:\n  neo4j_logs:\n",

            'qdrant' => "version: '3.8'\nservices:\n  qdrant:\n    image: qdrant/qdrant:latest\n    restart: unless-stopped\n    volumes:\n      - qdrant_data:/qdrant/storage\n    labels:\n      - 'novacpx.domain={$domain}'\nvolumes:\n  qdrant_data:\n",

            // ── Monitoring & Observability ────────────────────────────────────────

            'loki' => "version: '3.8'\nservices:\n  loki:\n    image: grafana/loki:latest\n    restart: unless-stopped\n    command: -config.file=/etc/loki/local-config.yaml\n    volumes:\n      - loki_data:/loki\n  promtail:\n    image: grafana/promtail:latest\n    restart: unless-stopped\n    volumes:\n      - /var/log:/var/log:ro\n  grafana:\n    image: grafana/grafana:latest\n    restart: unless-stopped\n    environment:\n      GF_SECURITY_ADMIN_PASSWORD: {$adminPass}\n    volumes:\n      - grafana_data:/var/lib/grafana\n    labels:\n      - 'novacpx.domain={$domain}'\nvolumes:\n  loki_data:\n  grafana_data:\n",

            'jaeger' => "version: '3.8'\nservices:\n  jaeger:\n    image: jaegertracing/all-in-one:latest\n    restart: unless-stopped\n    environment:\n      COLLECTOR_ZIPKIN_HOST_PORT: ':9411'\n    labels:\n      - 'novacpx.domain={$domain}'\n",

            'victoria-metrics' => "version: '3.8'\nservices:\n  victoria-metrics:\n    image: victoriametrics/victoria-metrics:latest\n    restart: unless-stopped\n    command: -storageDataPath=/victoria-metrics-data -retentionPeriod=6\n    volumes:\n      - vm_data:/victoria-metrics-data\n    labels:\n      - 'novacpx.domain={$domain}'\nvolumes:\n  vm_data:\n",

            'changedetection' => "version: '3.8'\nservices:\n  changedetection:\n    image: ghcr.io/dgtlmoon/changedetection.io:latest\n    restart: unless-stopped\n    environment:\n      BASE_URL: https://{$domain}\n      PASSWORD: {$adminPass}\n    volumes:\n      - changedetection_data:/datastore\n    labels:\n      - 'novacpx.domain={$domain}'\nvolumes:\n  changedetection_data:\n",

            'metabase' => (function() use ($p, $domain, $dbPass, $adminPass): string {
                $email = preg_replace('/[^a-zA-Z0-9@._\-]/', '', $p['admin_email'] ?? 'admin@' . $domain);
                return "version: '3.8'\nservices:\n  db:\n    image: postgres:16-alpine\n    restart: unless-stopped\n    environment:\n      POSTGRES_DB: metabase\n      POSTGRES_USER: metabase\n      POSTGRES_PASSWORD: {$dbPass}\n    volumes:\n      - db_data:/var/lib/postgresql/data\n  metabase:\n    image: metabase/metabase:latest\n    restart: unless-stopped\n    depends_on: [db]\n    environment:\n      MB_DB_TYPE: postgres\n      MB_DB_DBNAME: metabase\n      MB_DB_PORT: 5432\n      MB_DB_USER: metabase\n      MB_DB_PASS: {$dbPass}\n      MB_DB_HOST: db\n    labels:\n      - 'novacpx.domain={$domain}'\nvolumes:\n  db_data:\n";
            })(),

            // ── Networking & Security ─────────────────────────────────────────────

            'pihole' => "version: '3.8'\nservices:\n  pihole:\n    image: pihole/pihole:latest\n    restart: unless-stopped\n    environment:\n      WEBPASSWORD: {$adminPass}\n      TZ: UTC\n    volumes:\n      - pihole_etc:/etc/pihole\n      - pihole_dnsmasq:/etc/dnsmasq.d\n    labels:\n      - 'novacpx.domain={$domain}'\nvolumes:\n  pihole_etc:\n  pihole_dnsmasq:\n",

            'adguard-home' => "version: '3.8'\nservices:\n  adguard-home:\n    image: adguard/adguardhome:latest\n    restart: unless-stopped\n    volumes:\n      - adguard_work:/opt/adguardhome/work\n      - adguard_conf:/opt/adguardhome/conf\n    labels:\n      - 'novacpx.domain={$domain}'\nvolumes:\n  adguard_work:\n  adguard_conf:\n",

            'nginx-proxy-manager' => (function() use ($p, $domain, $dbPass, $adminPass): string {
                $email = preg_replace('/[^a-zA-Z0-9@._\-]/', '', $p['admin_email'] ?? 'admin@' . $domain);
                return "version: '3.8'\nservices:\n  db:\n    image: mariadb:11\n    restart: unless-stopped\n    environment:\n      MYSQL_ROOT_PASSWORD: {$dbPass}\n      MYSQL_DATABASE: npm\n      MYSQL_USER: npm\n      MYSQL_PASSWORD: {$dbPass}\n    volumes:\n      - db_data:/var/lib/mysql\n  npm:\n    image: jc21/nginx-proxy-manager:latest\n    restart: unless-stopped\n    depends_on: [db]\n    environment:\n      DB_MYSQL_HOST: db\n      DB_MYSQL_PORT: 3306\n      DB_MYSQL_USER: npm\n      DB_MYSQL_PASSWORD: {$dbPass}\n      DB_MYSQL_NAME: npm\n    volumes:\n      - npm_data:/data\n      - npm_letsencrypt:/etc/letsencrypt\n    labels:\n      - 'novacpx.domain={$domain}'\nvolumes:\n  db_data:\n  npm_data:\n  npm_letsencrypt:\n";
            })(),

            'traefik' => "version: '3.8'\nservices:\n  traefik:\n    image: traefik:v3\n    restart: unless-stopped\n    command:\n      - '--api.insecure=true'\n      - '--providers.docker=true'\n      - '--providers.docker.exposedbydefault=false'\n      - '--entrypoints.web.address=:80'\n    volumes:\n      - /var/run/docker.sock:/var/run/docker.sock:ro\n      - traefik_data:/etc/traefik\n    labels:\n      - 'novacpx.domain={$domain}'\nvolumes:\n  traefik_data:\n",

            'wg-easy' => "version: '3.8'\nservices:\n  wg-easy:\n    image: ghcr.io/wg-easy/wg-easy:latest\n    restart: unless-stopped\n    cap_add: [NET_ADMIN, SYS_MODULE]\n    sysctls:\n      - net.ipv4.conf.all.src_valid_mark=1\n      - net.ipv4.ip_forward=1\n    environment:\n      WG_HOST: {$domain}\n      PASSWORD_HASH: {$adminPass}\n    volumes:\n      - wg_data:/etc/wireguard\n    labels:\n      - 'novacpx.domain={$domain}'\nvolumes:\n  wg_data:\n",

            'cloudflared' => (function() use ($p, $domain): string {
                $token = preg_replace('/[^a-zA-Z0-9._\-]/', '', $p['token'] ?? '');
                return "version: '3.8'\nservices:\n  cloudflared:\n    image: cloudflare/cloudflared:latest\n    restart: unless-stopped\n    command: tunnel --no-autoupdate run --token {$token}\n    labels:\n      - 'novacpx.domain={$domain}'\n";
            })(),

            'crowdsec' => "version: '3.8'\nservices:\n  crowdsec:\n    image: crowdsecurity/crowdsec:latest\n    restart: unless-stopped\n    environment:\n      GID: '1000'\n    volumes:\n      - crowdsec_data:/var/lib/crowdsec/data\n      - crowdsec_config:/etc/crowdsec\n      - /var/log:/var/log:ro\n    labels:\n      - 'novacpx.domain={$domain}'\nvolumes:\n  crowdsec_data:\n  crowdsec_config:\n",

            'authelia' => (function() use ($domain, $adminPass): string {
                $session = bin2hex(random_bytes(16));
                $storage = bin2hex(random_bytes(16));
                return "version: '3.8'\nservices:\n  authelia:\n    image: authelia/authelia:latest\n    restart: unless-stopped\n    environment:\n      AUTHELIA_JWT_SECRET: {$adminPass}\n      AUTHELIA_SESSION_SECRET: {$session}\n      AUTHELIA_STORAGE_ENCRYPTION_KEY: {$storage}\n      AUTHELIA_STORAGE_LOCAL_PATH: /config/db.sqlite3\n    volumes:\n      - authelia_config:/config\n    labels:\n      - 'novacpx.domain={$domain}'\nvolumes:\n  authelia_config:\n";
            })(),

            // ── CMS & E-commerce ──────────────────────────────────────────────────

            'drupal' => (function() use ($domain, $dbPass): string {
                return "version: '3.8'\nservices:\n  db:\n    image: postgres:16-alpine\n    restart: unless-stopped\n    environment:\n      POSTGRES_DB: drupal\n      POSTGRES_USER: drupal\n      POSTGRES_PASSWORD: {$dbPass}\n    volumes:\n      - db_data:/var/lib/postgresql/data\n  drupal:\n    image: drupal:10-apache\n    restart: unless-stopped\n    depends_on: [db]\n    volumes:\n      - drupal_modules:/var/www/html/modules\n      - drupal_profiles:/var/www/html/profiles\n      - drupal_themes:/var/www/html/themes\n      - drupal_sites:/var/www/html/sites\n    labels:\n      - 'novacpx.domain={$domain}'\nvolumes:\n  db_data:\n  drupal_modules:\n  drupal_profiles:\n  drupal_themes:\n  drupal_sites:\n";
            })(),

            'joomla' => (function() use ($domain, $dbPass): string {
                return "version: '3.8'\nservices:\n  db:\n    image: mariadb:10.11\n    restart: unless-stopped\n    environment:\n      MYSQL_ROOT_PASSWORD: {$dbPass}\n      MYSQL_DATABASE: joomla\n      MYSQL_USER: joomla\n      MYSQL_PASSWORD: {$dbPass}\n    volumes:\n      - db_data:/var/lib/mysql\n  joomla:\n    image: joomla:php8.2-apache\n    restart: unless-stopped\n    depends_on: [db]\n    environment:\n      JOOMLA_DB_HOST: db\n      JOOMLA_DB_USER: joomla\n      JOOMLA_DB_PASSWORD: {$dbPass}\n      JOOMLA_DB_NAME: joomla\n    volumes:\n      - joomla_data:/var/www/html\n    labels:\n      - 'novacpx.domain={$domain}'\nvolumes:\n  db_data:\n  joomla_data:\n";
            })(),

            'grav' => "version: '3.8'\nservices:\n  grav:\n    image: linuxserver/grav:latest\n    restart: unless-stopped\n    environment:\n      PUID: '1000'\n      PGID: '1000'\n    volumes:\n      - grav_data:/config\n    labels:\n      - 'novacpx.domain={$domain}'\nvolumes:\n  grav_data:\n",

            'prestashop' => (function() use ($p, $domain, $dbPass, $adminPass): string {
                $email = preg_replace('/[^a-zA-Z0-9@._\-]/', '', $p['admin_email'] ?? 'admin@' . $domain);
                return "version: '3.8'\nservices:\n  db:\n    image: mariadb:10.11\n    restart: unless-stopped\n    environment:\n      MYSQL_ROOT_PASSWORD: {$dbPass}\n      MYSQL_DATABASE: prestashop\n      MYSQL_USER: prestashop\n      MYSQL_PASSWORD: {$dbPass}\n    volumes:\n      - db_data:/var/lib/mysql\n  prestashop:\n    image: prestashop/prestashop:latest\n    restart: unless-stopped\n    depends_on: [db]\n    environment:\n      PS_DOMAIN: {$domain}\n      DB_SERVER: db\n      DB_NAME: prestashop\n      DB_USER: prestashop\n      DB_PASSWD: {$dbPass}\n      ADMIN_MAIL: {$email}\n      ADMIN_PASSWD: {$adminPass}\n      PS_INSTALL_AUTO: '1'\n    volumes:\n      - prestashop_data:/var/www/html\n    labels:\n      - 'novacpx.domain={$domain}'\nvolumes:\n  db_data:\n  prestashop_data:\n";
            })(),

            'opencart' => (function() use ($domain, $dbPass): string {
                return "version: '3.8'\nservices:\n  db:\n    image: mariadb:10.11\n    restart: unless-stopped\n    environment:\n      MYSQL_ROOT_PASSWORD: {$dbPass}\n      MYSQL_DATABASE: opencart\n      MYSQL_USER: opencart\n      MYSQL_PASSWORD: {$dbPass}\n    volumes:\n      - db_data:/var/lib/mysql\n  opencart:\n    image: bitnami/opencart:latest\n    restart: unless-stopped\n    depends_on: [db]\n    environment:\n      OPENCART_DATABASE_HOST: db\n      OPENCART_DATABASE_NAME: opencart\n      OPENCART_DATABASE_USER: opencart\n      OPENCART_DATABASE_PASSWORD: {$dbPass}\n    volumes:\n      - opencart_data:/bitnami/opencart\n    labels:\n      - 'novacpx.domain={$domain}'\nvolumes:\n  db_data:\n  opencart_data:\n";
            })(),

            'medusa' => (function() use ($domain, $dbPass): string {
                $jwtSecret = bin2hex(random_bytes(16));
                return "version: '3.8'\nservices:\n  db:\n    image: postgres:16-alpine\n    restart: unless-stopped\n    environment:\n      POSTGRES_DB: medusa\n      POSTGRES_USER: medusa\n      POSTGRES_PASSWORD: {$dbPass}\n    volumes:\n      - db_data:/var/lib/postgresql/data\n  redis:\n    image: redis:7-alpine\n    restart: unless-stopped\n  medusa:\n    image: medusajs/medusa:latest\n    restart: unless-stopped\n    depends_on: [db, redis]\n    environment:\n      DATABASE_URL: postgresql://medusa:{$dbPass}@db:5432/medusa\n      REDIS_URL: redis://redis:6379\n      JWT_SECRET: {$jwtSecret}\n      COOKIE_SECRET: {$jwtSecret}\n    labels:\n      - 'novacpx.domain={$domain}'\nvolumes:\n  db_data:\n";
            })(),

            'answer' => (function() use ($domain, $dbPass): string {
                return "version: '3.8'\nservices:\n  db:\n    image: mariadb:10.11\n    restart: unless-stopped\n    environment:\n      MYSQL_ROOT_PASSWORD: {$dbPass}\n      MYSQL_DATABASE: answer\n      MYSQL_USER: answer\n      MYSQL_PASSWORD: {$dbPass}\n    volumes:\n      - db_data:/var/lib/mysql\n  answer:\n    image: answerdev/answer:latest\n    restart: unless-stopped\n    depends_on: [db]\n    environment:\n      DB_TYPE: mysql\n      DB_USERNAME: answer\n      DB_PASSWORD: {$dbPass}\n      DB_HOST: db:3306\n      DB_NAME: answer\n    volumes:\n      - answer_data:/data\n    labels:\n      - 'novacpx.domain={$domain}'\nvolumes:\n  db_data:\n  answer_data:\n";
            })(),

            // ── Project Management ────────────────────────────────────────────────

            'plane' => (function() use ($domain, $dbPass): string {
                $secret = bin2hex(random_bytes(16));
                return "version: '3.8'\nservices:\n  db:\n    image: postgres:16-alpine\n    restart: unless-stopped\n    environment:\n      POSTGRES_DB: plane\n      POSTGRES_USER: plane\n      POSTGRES_PASSWORD: {$dbPass}\n    volumes:\n      - db_data:/var/lib/postgresql/data\n  redis:\n    image: redis:7-alpine\n    restart: unless-stopped\n  plane-web:\n    image: makeplane/plane-frontend:latest\n    restart: unless-stopped\n    labels:\n      - 'novacpx.domain={$domain}'\n  plane-api:\n    image: makeplane/plane-backend:latest\n    restart: unless-stopped\n    depends_on: [db, redis]\n    environment:\n      DATABASE_URL: postgresql://plane:{$dbPass}@db:5432/plane\n      REDIS_URL: redis://redis:6379\n      SECRET_KEY: {$secret}\n      WEB_URL: https://{$domain}\nvolumes:\n  db_data:\n";
            })(),

            'openproject' => (function() use ($domain, $dbPass, $adminPass): string {
                return "version: '3.8'\nservices:\n  db:\n    image: postgres:16-alpine\n    restart: unless-stopped\n    environment:\n      POSTGRES_DB: openproject\n      POSTGRES_USER: openproject\n      POSTGRES_PASSWORD: {$dbPass}\n    volumes:\n      - db_data:/var/lib/postgresql/data\n  openproject:\n    image: openproject/openproject:14\n    restart: unless-stopped\n    depends_on: [db]\n    environment:\n      OPENPROJECT_HOST__NAME: {$domain}\n      DATABASE_URL: postgresql://openproject:{$dbPass}@db:5432/openproject\n      OPENPROJECT_ADMIN_USER_PASSWORD: {$adminPass}\n    volumes:\n      - op_data:/var/openproject\n    labels:\n      - 'novacpx.domain={$domain}'\nvolumes:\n  db_data:\n  op_data:\n";
            })(),

            'leantime' => (function() use ($p, $domain, $dbPass, $adminPass): string {
                $email = preg_replace('/[^a-zA-Z0-9@._\-]/', '', $p['admin_email'] ?? 'admin@' . $domain);
                return "version: '3.8'\nservices:\n  db:\n    image: mariadb:10.11\n    restart: unless-stopped\n    environment:\n      MYSQL_ROOT_PASSWORD: {$dbPass}\n      MYSQL_DATABASE: leantime\n      MYSQL_USER: leantime\n      MYSQL_PASSWORD: {$dbPass}\n    volumes:\n      - db_data:/var/lib/mysql\n  leantime:\n    image: leantime/leantime:latest\n    restart: unless-stopped\n    depends_on: [db]\n    environment:\n      LEAN_DB_HOST: db\n      LEAN_DB_DATABASE: leantime\n      LEAN_DB_USER: leantime\n      LEAN_DB_PASSWORD: {$dbPass}\n      LEAN_SITENAME: Leantime\n      LEAN_DEFAULT_TIMEZONE: UTC\n      LEAN_EMAIL_RETURN: {$email}\n      LEAN_ADMIN_EMAIL: {$email}\n      LEAN_ADMIN_PASSWORD: {$adminPass}\n    volumes:\n      - leantime_public:/var/www/html/public/userfiles\n      - leantime_private:/var/www/html/userfiles\n    labels:\n      - 'novacpx.domain={$domain}'\nvolumes:\n  db_data:\n  leantime_public:\n  leantime_private:\n";
            })(),

            'wekan' => (function() use ($p, $domain, $adminUser, $adminPass): string {
                $email = preg_replace('/[^a-zA-Z0-9@._\-]/', '', $p['admin_email'] ?? 'admin@' . $domain);
                return "version: '3.8'\nservices:\n  db:\n    image: mongo:7\n    restart: unless-stopped\n    volumes:\n      - db_data:/data/db\n  wekan:\n    image: wekanteam/wekan:latest\n    restart: unless-stopped\n    depends_on: [db]\n    environment:\n      MONGO_URL: mongodb://db:27017/wekan\n      ROOT_URL: https://{$domain}\n      WITH_API: 'true'\n      ADMIN_DEFAULT_EMAIL: {$email}\n    labels:\n      - 'novacpx.domain={$domain}'\nvolumes:\n  db_data:\n";
            })(),

            'focalboard' => (function() use ($domain, $dbPass): string {
                return "version: '3.8'\nservices:\n  db:\n    image: postgres:16-alpine\n    restart: unless-stopped\n    environment:\n      POSTGRES_DB: focalboard\n      POSTGRES_USER: focalboard\n      POSTGRES_PASSWORD: {$dbPass}\n    volumes:\n      - db_data:/var/lib/postgresql/data\n  focalboard:\n    image: mattermost/focalboard:latest\n    restart: unless-stopped\n    depends_on: [db]\n    environment:\n      FOCALBOARD_DBTYPE: postgres\n      FOCALBOARD_DBCONFIG: postgresql://focalboard:{$dbPass}@db:5432/focalboard\n    volumes:\n      - focalboard_data:/opt/focalboard/data\n    labels:\n      - 'novacpx.domain={$domain}'\nvolumes:\n  db_data:\n  focalboard_data:\n";
            })(),

            // ── Communication ─────────────────────────────────────────────────────

            'matrix-synapse' => (function() use ($domain, $dbPass, $adminPass): string {
                return "version: '3.8'\nservices:\n  db:\n    image: postgres:16-alpine\n    restart: unless-stopped\n    environment:\n      POSTGRES_DB: synapse\n      POSTGRES_USER: synapse\n      POSTGRES_PASSWORD: {$dbPass}\n      POSTGRES_INITDB_ARGS: --encoding=UTF-8 --lc-collate=C --lc-ctype=C\n    volumes:\n      - db_data:/var/lib/postgresql/data\n  synapse:\n    image: matrixdotorg/synapse:latest\n    restart: unless-stopped\n    depends_on: [db]\n    environment:\n      SYNAPSE_SERVER_NAME: {$domain}\n      SYNAPSE_REPORT_STATS: 'no'\n      SYNAPSE_MACAROON_SECRET_KEY: {$adminPass}\n      SYNAPSE_DATABASE_HOST: db\n      SYNAPSE_DATABASE_NAME: synapse\n      SYNAPSE_DATABASE_USER: synapse\n      SYNAPSE_DATABASE_PASSWORD: {$dbPass}\n    volumes:\n      - synapse_data:/data\n    labels:\n      - 'novacpx.domain={$domain}'\nvolumes:\n  db_data:\n  synapse_data:\n";
            })(),

            'gotify' => "version: '3.8'\nservices:\n  gotify:\n    image: gotify/server:latest\n    restart: unless-stopped\n    environment:\n      GOTIFY_DEFAULTUSER_PASS: {$adminPass}\n    volumes:\n      - gotify_data:/app/data\n    labels:\n      - 'novacpx.domain={$domain}'\nvolumes:\n  gotify_data:\n",

            'ntfy' => "version: '3.8'\nservices:\n  ntfy:\n    image: binwiederhier/ntfy:latest\n    restart: unless-stopped\n    command: serve\n    volumes:\n      - ntfy_cache:/var/cache/ntfy\n      - ntfy_data:/etc/ntfy\n    labels:\n      - 'novacpx.domain={$domain}'\nvolumes:\n  ntfy_cache:\n  ntfy_data:\n",

            'grist' => (function() use ($p, $domain, $adminPass): string {
                $email = preg_replace('/[^a-zA-Z0-9@._\-]/', '', $p['admin_email'] ?? 'admin@' . $domain);
                return "version: '3.8'\nservices:\n  grist:\n    image: gristlabs/grist:latest\n    restart: unless-stopped\n    environment:\n      APP_HOME_URL: https://{$domain}\n      GRIST_SESSION_SECRET: {$adminPass}\n      GRIST_SINGLE_ORG: myorg\n    volumes:\n      - grist_data:/persist\n    labels:\n      - 'novacpx.domain={$domain}'\nvolumes:\n  grist_data:\n";
            })(),

            'mailpit' => "version: '3.8'\nservices:\n  mailpit:\n    image: axllent/mailpit:latest\n    restart: unless-stopped\n    labels:\n      - 'novacpx.domain={$domain}'\n",

            // ── File Storage & Backup ─────────────────────────────────────────────

            'syncthing' => "version: '3.8'\nservices:\n  syncthing:\n    image: syncthing/syncthing:latest\n    restart: unless-stopped\n    hostname: syncthing-{$domain}\n    volumes:\n      - syncthing_data:/var/syncthing\n    labels:\n      - 'novacpx.domain={$domain}'\nvolumes:\n  syncthing_data:\n",

            'sftpgo' => "version: '3.8'\nservices:\n  sftpgo:\n    image: drakkan/sftpgo:latest\n    restart: unless-stopped\n    environment:\n      SFTPGO_DEFAULT_ADMIN_USERNAME: {$adminUser}\n      SFTPGO_DEFAULT_ADMIN_PASSWORD: {$adminPass}\n    volumes:\n      - sftpgo_data:/srv/sftpgo/data\n      - sftpgo_config:/var/lib/sftpgo\n    labels:\n      - 'novacpx.domain={$domain}'\nvolumes:\n  sftpgo_data:\n  sftpgo_config:\n",

            'owncloud' => (function() use ($domain, $dbPass, $adminPass): string {
                return "version: '3.8'\nservices:\n  db:\n    image: mariadb:10.11\n    restart: unless-stopped\n    environment:\n      MYSQL_ROOT_PASSWORD: {$dbPass}\n      MYSQL_DATABASE: owncloud\n      MYSQL_USER: owncloud\n      MYSQL_PASSWORD: {$dbPass}\n    volumes:\n      - db_data:/var/lib/mysql\n  owncloud:\n    image: owncloud/server:latest\n    restart: unless-stopped\n    depends_on: [db]\n    environment:\n      OWNCLOUD_DOMAIN: {$domain}\n      OWNCLOUD_TRUSTED_DOMAINS: {$domain}\n      OWNCLOUD_DB_TYPE: mysql\n      OWNCLOUD_DB_HOST: db\n      OWNCLOUD_DB_NAME: owncloud\n      OWNCLOUD_DB_USERNAME: owncloud\n      OWNCLOUD_DB_PASSWORD: {$dbPass}\n      OWNCLOUD_ADMIN_USERNAME: admin\n      OWNCLOUD_ADMIN_PASSWORD: {$adminPass}\n    volumes:\n      - owncloud_data:/mnt/data\n    labels:\n      - 'novacpx.domain={$domain}'\nvolumes:\n  db_data:\n  owncloud_data:\n";
            })(),

            'duplicati' => "version: '3.8'\nservices:\n  duplicati:\n    image: linuxserver/duplicati:latest\n    restart: unless-stopped\n    environment:\n      PUID: '0'\n      PGID: '0'\n    volumes:\n      - duplicati_config:/config\n      - duplicati_backups:/backups\n    labels:\n      - 'novacpx.domain={$domain}'\nvolumes:\n  duplicati_config:\n  duplicati_backups:\n",

            'calibre-web' => "version: '3.8'\nservices:\n  calibre-web:\n    image: linuxserver/calibre-web:latest\n    restart: unless-stopped\n    environment:\n      PUID: '1000'\n      PGID: '1000'\n      DOCKER_MODS: linuxserver/mods:universal-calibre\n    volumes:\n      - calibre_config:/config\n      - calibre_books:/books\n    labels:\n      - 'novacpx.domain={$domain}'\nvolumes:\n  calibre_config:\n  calibre_books:\n",

            // ── ERP & Business ────────────────────────────────────────────────────

            'odoo' => (function() use ($domain, $dbPass, $adminPass): string {
                return "version: '3.8'\nservices:\n  db:\n    image: postgres:16-alpine\n    restart: unless-stopped\n    environment:\n      POSTGRES_DB: postgres\n      POSTGRES_USER: odoo\n      POSTGRES_PASSWORD: {$dbPass}\n    volumes:\n      - db_data:/var/lib/postgresql/data\n  odoo:\n    image: odoo:17\n    restart: unless-stopped\n    depends_on: [db]\n    environment:\n      HOST: db\n      USER: odoo\n      PASSWORD: {$dbPass}\n    volumes:\n      - odoo_data:/var/lib/odoo\n      - odoo_config:/etc/odoo\n    labels:\n      - 'novacpx.domain={$domain}'\nvolumes:\n  db_data:\n  odoo_data:\n  odoo_config:\n";
            })(),

            'akaunting' => (function() use ($p, $domain, $dbPass, $adminPass): string {
                $email = preg_replace('/[^a-zA-Z0-9@._\-]/', '', $p['admin_email'] ?? 'admin@' . $domain);
                return "version: '3.8'\nservices:\n  db:\n    image: mariadb:10.11\n    restart: unless-stopped\n    environment:\n      MYSQL_ROOT_PASSWORD: {$dbPass}\n      MYSQL_DATABASE: akaunting\n      MYSQL_USER: akaunting\n      MYSQL_PASSWORD: {$dbPass}\n    volumes:\n      - db_data:/var/lib/mysql\n  akaunting:\n    image: akaunting/akaunting:latest\n    restart: unless-stopped\n    depends_on: [db]\n    environment:\n      COMPANY_NAME: My Company\n      COMPANY_EMAIL: {$email}\n      ADMIN_EMAIL: {$email}\n      ADMIN_PASSWORD: {$adminPass}\n      DB_HOST: db\n      DB_NAME: akaunting\n      DB_USERNAME: akaunting\n      DB_PASSWORD: {$dbPass}\n    volumes:\n      - akaunting_data:/var/www/html/storage\n    labels:\n      - 'novacpx.domain={$domain}'\nvolumes:\n  db_data:\n  akaunting_data:\n";
            })(),

            'monica' => (function() use ($p, $domain, $dbPass, $adminPass): string {
                $email = preg_replace('/[^a-zA-Z0-9@._\-]/', '', $p['admin_email'] ?? 'admin@' . $domain);
                $key   = bin2hex(random_bytes(16));
                return "version: '3.8'\nservices:\n  db:\n    image: mariadb:10.11\n    restart: unless-stopped\n    environment:\n      MYSQL_ROOT_PASSWORD: {$dbPass}\n      MYSQL_DATABASE: monica\n      MYSQL_USER: monica\n      MYSQL_PASSWORD: {$dbPass}\n    volumes:\n      - db_data:/var/lib/mysql\n  monica:\n    image: monica:latest\n    restart: unless-stopped\n    depends_on: [db]\n    environment:\n      APP_URL: https://{$domain}\n      APP_KEY: base64:{$key}\n      DB_HOST: db\n      DB_DATABASE: monica\n      DB_USERNAME: monica\n      DB_PASSWORD: {$dbPass}\n    volumes:\n      - monica_storage:/var/www/html/storage\n    labels:\n      - 'novacpx.domain={$domain}'\nvolumes:\n  db_data:\n  monica_storage:\n";
            })(),

            'twenty' => (function() use ($domain, $dbPass): string {
                $secret = bin2hex(random_bytes(16));
                return "version: '3.8'\nservices:\n  db:\n    image: postgres:16-alpine\n    restart: unless-stopped\n    environment:\n      POSTGRES_DB: twenty\n      POSTGRES_USER: twenty\n      POSTGRES_PASSWORD: {$dbPass}\n    volumes:\n      - db_data:/var/lib/postgresql/data\n  redis:\n    image: redis:7-alpine\n    restart: unless-stopped\n  twenty:\n    image: twentycrm/twenty:latest\n    restart: unless-stopped\n    depends_on: [db, redis]\n    environment:\n      FRONT_BASE_URL: https://{$domain}\n      PG_DATABASE_URL: postgresql://twenty:{$dbPass}@db:5432/twenty\n      REDIS_URL: redis://redis:6379\n      APP_SECRET: {$secret}\n    labels:\n      - 'novacpx.domain={$domain}'\nvolumes:\n  db_data:\n";
            })(),

            'dolibarr' => (function() use ($domain, $dbPass, $adminPass): string {
                return "version: '3.8'\nservices:\n  db:\n    image: mariadb:10.11\n    restart: unless-stopped\n    environment:\n      MYSQL_ROOT_PASSWORD: {$dbPass}\n      MYSQL_DATABASE: dolibarr\n      MYSQL_USER: dolibarr\n      MYSQL_PASSWORD: {$dbPass}\n    volumes:\n      - db_data:/var/lib/mysql\n  dolibarr:\n    image: dolibarr/dolibarr:latest\n    restart: unless-stopped\n    depends_on: [db]\n    environment:\n      DOLI_DB_HOST: db\n      DOLI_DB_NAME: dolibarr\n      DOLI_DB_USER: dolibarr\n      DOLI_DB_PASSWORD: {$dbPass}\n      DOLI_ADMIN_LOGIN: admin\n      DOLI_ADMIN_PASSWORD: {$adminPass}\n      DOLI_URL_ROOT: https://{$domain}\n    volumes:\n      - dolibarr_html:/var/www/html\n      - dolibarr_docs:/var/www/documents\n    labels:\n      - 'novacpx.domain={$domain}'\nvolumes:\n  db_data:\n  dolibarr_html:\n  dolibarr_docs:\n";
            })(),

            // ── Media ─────────────────────────────────────────────────────────────

            'audiobookshelf' => "version: '3.8'\nservices:\n  audiobookshelf:\n    image: ghcr.io/advplyr/audiobookshelf:latest\n    restart: unless-stopped\n    volumes:\n      - abs_config:/config\n      - abs_metadata:/metadata\n      - abs_audiobooks:/audiobooks\n    labels:\n      - 'novacpx.domain={$domain}'\nvolumes:\n  abs_config:\n  abs_metadata:\n  abs_audiobooks:\n",

            'komga' => (function() use ($p, $domain, $adminPass): string {
                $email = preg_replace('/[^a-zA-Z0-9@._\-]/', '', $p['admin_email'] ?? 'admin@' . $domain);
                return "version: '3.8'\nservices:\n  komga:\n    image: gotson/komga:latest\n    restart: unless-stopped\n    environment:\n      KOMGA_OAUTH2_ACCOUNT_CREATION: 'false'\n      KOMGA_INITIAL_EMAIL: {$email}\n      KOMGA_INITIAL_PASSWORD: {$adminPass}\n    volumes:\n      - komga_data:/config\n      - komga_books:/data\n    labels:\n      - 'novacpx.domain={$domain}'\nvolumes:\n  komga_data:\n  komga_books:\n";
            })(),

            'grocy' => "version: '3.8'\nservices:\n  grocy:\n    image: linuxserver/grocy:latest\n    restart: unless-stopped\n    environment:\n      PUID: '1000'\n      PGID: '1000'\n      TZ: UTC\n    volumes:\n      - grocy_config:/config\n    labels:\n      - 'novacpx.domain={$domain}'\nvolumes:\n  grocy_config:\n",

            'tube-archivist' => (function() use ($domain, $adminUser, $adminPass): string {
                $esPass = bin2hex(random_bytes(8));
                return "version: '3.8'\nservices:\n  elasticsearch:\n    image: elasticsearch:8.13.0\n    restart: unless-stopped\n    environment:\n      discovery.type: single-node\n      ELASTIC_PASSWORD: {$esPass}\n      xpack.security.enabled: 'true'\n      ES_JAVA_OPTS: -Xms512m -Xmx512m\n    volumes:\n      - es_data:/usr/share/elasticsearch/data\n  redis:\n    image: redis:7-alpine\n    restart: unless-stopped\n    volumes:\n      - redis_data:/data\n  tube-archivist:\n    image: bbilly1/tubearchivist:latest\n    restart: unless-stopped\n    depends_on: [elasticsearch, redis]\n    environment:\n      ES_URL: http://elasticsearch:9200\n      ELASTIC_PASSWORD: {$esPass}\n      TA_USERNAME: {$adminUser}\n      TA_PASSWORD: {$adminPass}\n      TA_HOST: {$domain}\n      REDIS_HOST: redis\n    volumes:\n      - ta_media:/youtube\n      - ta_cache:/cache\n    labels:\n      - 'novacpx.domain={$domain}'\nvolumes:\n  es_data:\n  redis_data:\n  ta_media:\n  ta_cache:\n";
            })(),

            'affine' => (function() use ($domain, $dbPass): string {
                return "version: '3.8'\nservices:\n  db:\n    image: postgres:16-alpine\n    restart: unless-stopped\n    environment:\n      POSTGRES_DB: affine\n      POSTGRES_USER: affine\n      POSTGRES_PASSWORD: {$dbPass}\n    volumes:\n      - db_data:/var/lib/postgresql/data\n  redis:\n    image: redis:7-alpine\n    restart: unless-stopped\n  affine:\n    image: ghcr.io/toeverything/affine-graphql:stable\n    restart: unless-stopped\n    depends_on: [db, redis]\n    environment:\n      DATABASE_URL: postgresql://affine:{$dbPass}@db:5432/affine\n      REDIS_SERVER_HOST: redis\n    volumes:\n      - affine_config:/root/.affine\n    labels:\n      - 'novacpx.domain={$domain}'\nvolumes:\n  db_data:\n  affine_config:\n";
            })(),

            // ── Smart Home ────────────────────────────────────────────────────────

            'home-assistant' => "version: '3.8'\nservices:\n  homeassistant:\n    image: homeassistant/home-assistant:stable\n    restart: unless-stopped\n    network_mode: host\n    volumes:\n      - ha_config:/config\n    labels:\n      - 'novacpx.domain={$domain}'\nvolumes:\n  ha_config:\n",

            'node-red' => "version: '3.8'\nservices:\n  node-red:\n    image: nodered/node-red:latest\n    restart: unless-stopped\n    volumes:\n      - node_red_data:/data\n    labels:\n      - 'novacpx.domain={$domain}'\nvolumes:\n  node_red_data:\n",

            'mosquitto' => "version: '3.8'\nservices:\n  mosquitto:\n    image: eclipse-mosquitto:latest\n    restart: unless-stopped\n    volumes:\n      - mosquitto_data:/mosquitto/data\n      - mosquitto_log:/mosquitto/log\n    labels:\n      - 'novacpx.domain={$domain}'\nvolumes:\n  mosquitto_data:\n  mosquitto_log:\n",

            'zigbee2mqtt' => (function() use ($p, $domain): string {
                $mqttHost = preg_replace('/[^a-zA-Z0-9.\-_]/', '', $p['mqtt_host'] ?? 'localhost');
                return "version: '3.8'\nservices:\n  zigbee2mqtt:\n    image: koenkk/zigbee2mqtt:latest\n    restart: unless-stopped\n    environment:\n      ZIGBEE2MQTT_CONFIG_MQTT_SERVER: mqtt://{$mqttHost}\n      ZIGBEE2MQTT_CONFIG_FRONTEND: 'true'\n    volumes:\n      - z2m_data:/app/data\n    devices:\n      - /dev/ttyACM0:/dev/ttyACM0\n    labels:\n      - 'novacpx.domain={$domain}'\nvolumes:\n  z2m_data:\n";
            })(),

            // ── Dashboards & Admin ────────────────────────────────────────────────

            'dashy' => "version: '3.8'\nservices:\n  dashy:\n    image: lissy93/dashy:latest\n    restart: unless-stopped\n    volumes:\n      - dashy_config:/app/user-data\n    labels:\n      - 'novacpx.domain={$domain}'\nvolumes:\n  dashy_config:\n",

            'homarr' => "version: '3.8'\nservices:\n  homarr:\n    image: ghcr.io/ajnart/homarr:latest\n    restart: unless-stopped\n    volumes:\n      - homarr_configs:/app/data/configs\n      - homarr_icons:/app/public/icons\n      - homarr_data:/data\n    labels:\n      - 'novacpx.domain={$domain}'\nvolumes:\n  homarr_configs:\n  homarr_icons:\n  homarr_data:\n",

            'homer' => "version: '3.8'\nservices:\n  homer:\n    image: b4bz/homer:latest\n    restart: unless-stopped\n    volumes:\n      - homer_data:/www/assets\n    labels:\n      - 'novacpx.domain={$domain}'\nvolumes:\n  homer_data:\n",

            'it-tools' => "version: '3.8'\nservices:\n  it-tools:\n    image: corentinth/it-tools:latest\n    restart: unless-stopped\n    labels:\n      - 'novacpx.domain={$domain}'\n",

            'cloudbeaver' => "version: '3.8'\nservices:\n  cloudbeaver:\n    image: dbeaver/cloudbeaver:latest\n    restart: unless-stopped\n    volumes:\n      - cloudbeaver_data:/opt/cloudbeaver/workspace\n    labels:\n      - 'novacpx.domain={$domain}'\nvolumes:\n  cloudbeaver_data:\n",

            'pgadmin' => (function() use ($p, $domain, $adminPass): string {
                $email = preg_replace('/[^a-zA-Z0-9@._\-]/', '', $p['admin_email'] ?? 'admin@' . $domain);
                return "version: '3.8'\nservices:\n  pgadmin:\n    image: dpage/pgadmin4:latest\n    restart: unless-stopped\n    environment:\n      PGADMIN_DEFAULT_EMAIL: {$email}\n      PGADMIN_DEFAULT_PASSWORD: {$adminPass}\n    volumes:\n      - pgadmin_data:/var/lib/pgadmin\n    labels:\n      - 'novacpx.domain={$domain}'\nvolumes:\n  pgadmin_data:\n";
            })(),

            'phpmyadmin' => (function() use ($p, $domain, $dbPass): string {
                $dbHost = preg_replace('/[^a-zA-Z0-9.\-_]/', '', $p['db_host'] ?? 'host.docker.internal');
                return "version: '3.8'\nservices:\n  phpmyadmin:\n    image: phpmyadmin:latest\n    restart: unless-stopped\n    environment:\n      PMA_HOST: {$dbHost}\n      MYSQL_ROOT_PASSWORD: {$dbPass}\n      PMA_ARBITRARY: '1'\n    labels:\n      - 'novacpx.domain={$domain}'\n";
            })(),

            'infisical' => (function() use ($domain, $dbPass): string {
                $secret = bin2hex(random_bytes(16));
                $encKey = bin2hex(random_bytes(16));
                return "version: '3.8'\nservices:\n  db:\n    image: postgres:16-alpine\n    restart: unless-stopped\n    environment:\n      POSTGRES_DB: infisical\n      POSTGRES_USER: infisical\n      POSTGRES_PASSWORD: {$dbPass}\n    volumes:\n      - db_data:/var/lib/postgresql/data\n  redis:\n    image: redis:7-alpine\n    restart: unless-stopped\n  infisical:\n    image: infisical/infisical:latest\n    restart: unless-stopped\n    depends_on: [db, redis]\n    environment:\n      DB_CONNECTION_URI: postgresql://infisical:{$dbPass}@db:5432/infisical\n      REDIS_URL: redis://redis:6379\n      ENCRYPTION_KEY: {$encKey}\n      AUTH_SECRET: {$secret}\n      SITE_URL: https://{$domain}\n    labels:\n      - 'novacpx.domain={$domain}'\nvolumes:\n  db_data:\n";
            })(),

            default => throw new RuntimeException("No compose template for: $appKey"),
        };
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function validateContainerId(string $id): void {
        if (!preg_match('/^[a-f0-9]{8,64}$/', $id)) throw new RuntimeException("Invalid container ID");
    }
}
