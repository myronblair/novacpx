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

            default => throw new RuntimeException("No compose template for: $appKey"),
        };
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function validateContainerId(string $id): void {
        if (!preg_match('/^[a-f0-9]{8,64}$/', $id)) throw new RuntimeException("Invalid container ID");
    }
}
