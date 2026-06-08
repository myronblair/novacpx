<?php
class BackupManager {
    private PDO    $db;
    private string $backupRoot = '/home/novacpx-backups';

    public function __construct() {
        $this->db = Database::getInstance()->getPDO();
        if (!is_dir($this->backupRoot)) mkdir($this->backupRoot, 0750, true);
    }

    // ── Create full backup ────────────────────────────────────────────────────
    public function create(int $accountId, string $type = 'full'): array {
        $account  = $this->getAccount($accountId);
        $dir      = $this->backupRoot . '/' . $account['username'];
        if (!is_dir($dir)) mkdir($dir, 0750, true);

        $ts       = date('Ymd_His');
        $filename = "{$account['username']}_{$type}_{$ts}.tar.gz";
        $filepath = "{$dir}/{$filename}";

        // Record as pending
        $stmt = $this->db->prepare("INSERT INTO backups (account_id, filename, type, status, storage) VALUES (?,?,?,'running','local')");
        $stmt->execute([$accountId, $filename, $type]);
        $backupId = $this->db->lastInsertId();

        try {
            if ($type === 'full' || $type === 'files') {
                $docRoot = escapeshellarg($account['document_root']);
                exec("tar -czf " . escapeshellarg($filepath) . " -C / " . ltrim($docRoot, '/') . " 2>&1", $out, $rc);
                if ($rc !== 0) throw new RuntimeException("tar failed: " . implode("\n", $out));
            }

            if ($type === 'full' || $type === 'database') {
                // Dump all databases belonging to this account
                $dbs = $this->db->prepare("SELECT db_name FROM account_databases WHERE account_id=?");
                $dbs->execute([$accountId]);
                foreach ($dbs->fetchAll(PDO::FETCH_COLUMN) as $dbName) {
                    $dumpFile = escapeshellarg("{$dir}/{$account['username']}_{$dbName}_{$ts}.sql.gz");
                    exec("mysqldump " . escapeshellarg($dbName) . " | gzip > {$dumpFile} 2>&1");
                }

                if ($type === 'database') {
                    // Pack all sql.gz files into the tar
                    exec("tar -czf " . escapeshellarg($filepath) . " -C {$dir} " .
                         escapeshellarg("{$account['username']}_{$ts}") . "*.sql.gz 2>/dev/null");
                }
            }

            $size = file_exists($filepath) ? filesize($filepath) : 0;
            $this->db->prepare("UPDATE backups SET status='complete', size=? WHERE id=?")->execute([$size, $backupId]);
            return ['id' => $backupId, 'filename' => $filename, 'size' => $size];

        } catch (RuntimeException $e) {
            $this->db->prepare("UPDATE backups SET status='failed' WHERE id=?")->execute([$backupId]);
            throw $e;
        }
    }

    // ── List ──────────────────────────────────────────────────────────────────
    public function list(int $accountId = 0): array {
        if ($accountId) {
            $stmt = $this->db->prepare("SELECT b.*, a.username FROM backups b JOIN accounts a ON b.account_id=a.id WHERE b.account_id=? ORDER BY b.created_at DESC");
            $stmt->execute([$accountId]);
        } else {
            $stmt = $this->db->query("SELECT b.*, a.username FROM backups b JOIN accounts a ON b.account_id=a.id ORDER BY b.created_at DESC");
        }
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // ── Download ──────────────────────────────────────────────────────────────
    public function getDownloadPath(int $backupId): string {
        $stmt = $this->db->prepare("SELECT b.*, a.username FROM backups b JOIN accounts a ON b.account_id=a.id WHERE b.id=?");
        $stmt->execute([$backupId]);
        $backup = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$backup) throw new RuntimeException("Backup not found");
        $path = $this->backupRoot . '/' . $backup['username'] . '/' . $backup['filename'];
        if (!file_exists($path)) throw new RuntimeException("Backup file missing from disk");
        return $path;
    }

    // ── Restore ───────────────────────────────────────────────────────────────
    public function restore(int $backupId): bool {
        $stmt = $this->db->prepare("SELECT b.*, a.document_root, a.username FROM backups b JOIN accounts a ON b.account_id=a.id WHERE b.id=?");
        $stmt->execute([$backupId]);
        $backup = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$backup) throw new RuntimeException("Backup not found");

        $path = $this->backupRoot . '/' . $backup['username'] . '/' . $backup['filename'];
        if (!file_exists($path)) throw new RuntimeException("Backup file not found on disk");

        exec("tar -xzf " . escapeshellarg($path) . " -C / 2>&1", $out, $rc);
        if ($rc !== 0) throw new RuntimeException("Restore failed: " . implode("\n", $out));
        return true;
    }

    // ── Delete ────────────────────────────────────────────────────────────────
    public function delete(int $backupId): bool {
        $stmt = $this->db->prepare("SELECT b.*, a.username FROM backups b JOIN accounts a ON b.account_id=a.id WHERE b.id=?");
        $stmt->execute([$backupId]);
        $backup = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($backup) {
            $path = $this->backupRoot . '/' . $backup['username'] . '/' . $backup['filename'];
            if (file_exists($path)) unlink($path);
            $this->db->prepare("DELETE FROM backups WHERE id=?")->execute([$backupId]);
        }
        return true;
    }

    // ── Schedule ──────────────────────────────────────────────────────────────
    public function setSchedule(int $accountId, string $frequency, string $type = 'full', int $retain = 7): bool {
        $stmt = $this->db->prepare("INSERT INTO backup_schedules (account_id, frequency, type, retain_count)
            VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE frequency=VALUES(frequency), type=VALUES(type), retain_count=VALUES(retain_count)");
        $stmt->execute([$accountId, $frequency, $type, $retain]);
        return true;
    }

    public function getSchedule(int $accountId): ?array {
        $stmt = $this->db->prepare("SELECT * FROM backup_schedules WHERE account_id=?");
        $stmt->execute([$accountId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    // ── Prune old backups per retention policy ────────────────────────────────
    public function prune(int $accountId): int {
        $schedule = $this->getSchedule($accountId);
        if (!$schedule) return 0;
        $retain   = (int)$schedule['retain_count'];
        $stmt     = $this->db->prepare("SELECT * FROM backups WHERE account_id=? AND status='complete' ORDER BY created_at DESC");
        $stmt->execute([$accountId]);
        $all = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $pruned = 0;
        foreach (array_slice($all, $retain) as $old) {
            $this->delete($old['id']);
            $pruned++;
        }
        return $pruned;
    }

    // ── rclone remote upload ──────────────────────────────────────────────────
    public function uploadRemote(int $backupId, string $remote): string {
        $path = $this->getDownloadPath($backupId);
        $out  = []; exec("rclone copy " . escapeshellarg($path) . " " . escapeshellarg($remote) . " 2>&1", $out, $rc);
        if ($rc === 0) {
            $this->db->prepare("UPDATE backups SET storage='remote', remote_path=? WHERE id=?")->execute([$remote, $backupId]);
        }
        return implode("\n", $out);
    }

    // ── Disk usage ────────────────────────────────────────────────────────────
    public function diskUsage(int $accountId = 0): int {
        if ($accountId) {
            $stmt = $this->db->prepare("SELECT COALESCE(SUM(size),0) FROM backups WHERE account_id=? AND status='complete'");
            $stmt->execute([$accountId]);
        } else {
            $stmt = $this->db->query("SELECT COALESCE(SUM(size),0) FROM backups WHERE status='complete'");
        }
        return (int)$stmt->fetchColumn();
    }

    private function getAccount(int $id): array {
        $stmt = $this->db->prepare("SELECT * FROM accounts WHERE id=?");
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: throw new RuntimeException("Account not found");
    }
}
