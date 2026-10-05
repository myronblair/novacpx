<?php
/**
 * ScheduleRunner: executes the backup schedules saved in backup_schedules (they used to be stored but never run).
 * A schedule is due when it has never run or its interval has passed (with a 5 minute tolerance so a cron tick that lands a
 * few seconds early does not skip a slot). last_run is stamped BEFORE the backup starts, so an overlapping run cannot start the
 * same backup twice; after a successful backup the account's retention count is applied.
 */
class ScheduleRunner {

    private const INTERVAL = ['hourly' => 3600, 'daily' => 86400, 'weekly' => 604800, 'monthly' => 2592000];

    /** @return array<int,array> schedules that should run now */
    public static function due(): array {
        $out = [];
        foreach (DB::getInstance()->fetchAll(
            "SELECT s.*, a.username FROM backup_schedules s JOIN accounts a ON a.id = s.account_id WHERE a.status = 'active'") as $s) {
            $every = self::INTERVAL[$s['frequency']] ?? 86400;
            $last  = $s['last_run'] ? strtotime($s['last_run'] . ' UTC') : 0;
            if (time() - $last >= $every - 300) $out[] = $s;
        }
        return $out;
    }

    /** @return array{ran:int,failed:int} */
    public static function runDue(): array {
        require_once __DIR__ . '/BackupManager.php';
        $db = DB::getInstance();
        // a backup whose process died (reboot, killed) would stay "running" forever: mark anything older than 12 hours as failed
        $db->execute("UPDATE backups SET status = 'failed' WHERE status = 'running' AND created_at < datetime('now','-12 hours')");
        $ran = 0; $failed = 0;
        foreach (self::due() as $s) {
            $db->execute("UPDATE backup_schedules SET last_run = datetime('now') WHERE id = ?", [$s['id']]);
            try {
                $bm = new BackupManager();
                $bm->create((int)$s['account_id'], $s['type']);
                $bm->prune((int)$s['account_id']);
                $ran++;
            } catch (Throwable $e) {
                $failed++;
                error_log('[ScheduleRunner] ' . $s['username'] . ': ' . $e->getMessage());
            }
        }
        return ['ran' => $ran, 'failed' => $failed];
    }
}
