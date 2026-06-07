<?php
$db   = DB::getInstance();
$body = json_decode(file_get_contents('php://input'), true) ?? [];

$user      = Auth::getInstance()->user();
$accountId = $user['role'] === 'user'
    ? (int)($db->fetchOne("SELECT id FROM accounts WHERE user_id = ?", [$user['uid']])['id'] ?? 0)
    : (int)($body['account_id'] ?? $_GET['account_id'] ?? 0);

function writeCrontab(int $accountId, $db): void {
    $acct = $db->fetchOne("SELECT username FROM accounts WHERE id = ?", [$accountId]);
    if (!$acct) return;
    $jobs = $db->fetchAll("SELECT * FROM cron_jobs WHERE account_id = ? AND is_active = 1", [$accountId]);
    $cron = "# NovaCPX cron jobs for {$acct['username']}\n";
    foreach ($jobs as $j) {
        $cron .= "{$j['minute']} {$j['hour']} {$j['day']} {$j['month']} {$j['weekday']} {$acct['username']} {$j['command']}\n";
    }
    file_put_contents("/etc/cron.d/novacpx-{$acct['username']}", $cron);
}

match ($action) {
    'list' => (function() use ($db, $accountId) {
        Response::success($db->fetchAll("SELECT * FROM cron_jobs WHERE account_id = ? ORDER BY id", [$accountId]));
    })(),

    'create' => (function() use ($db, $body, $accountId) {
        $cmd = trim($body['command'] ?? '');
        if (!$cmd) Response::error("command required");
        // Validate cron schedule fields
        $fields = ['minute','hour','day','month','weekday'];
        foreach ($fields as $f) { if (empty($body[$f])) $body[$f] = '*'; }

        $id = (int)$db->insert(
            "INSERT INTO cron_jobs (account_id, command, minute, hour, day, month, weekday) VALUES (?,?,?,?,?,?,?)",
            [$accountId, $cmd, $body['minute'], $body['hour'], $body['day'], $body['month'], $body['weekday']]
        );
        writeCrontab($accountId, $db);
        audit('cron.create', $cmd);
        Response::success(['id' => $id], 'Cron job created');
    })(),

    'update' => (function() use ($db, $body, $accountId) {
        $id = (int)($body['id'] ?? 0);
        $db->execute(
            "UPDATE cron_jobs SET command=?, minute=?, hour=?, day=?, month=?, weekday=?, is_active=? WHERE id=? AND account_id=?",
            [$body['command'], $body['minute'] ?? '*', $body['hour'] ?? '*', $body['day'] ?? '*',
             $body['month'] ?? '*', $body['weekday'] ?? '*', (int)($body['is_active'] ?? 1), $id, $accountId]
        );
        writeCrontab($accountId, $db);
        Response::success(null, 'Cron job updated');
    })(),

    'delete' => (function() use ($db, $body, $accountId) {
        $id = (int)($body['id'] ?? 0);
        $db->execute("DELETE FROM cron_jobs WHERE id = ? AND account_id = ?", [$id, $accountId]);
        writeCrontab($accountId, $db);
        audit('cron.delete', "job:$id");
        Response::success(null, 'Cron job deleted');
    })(),

    'toggle' => (function() use ($db, $body, $accountId) {
        $id = (int)($body['id'] ?? 0);
        $db->execute("UPDATE cron_jobs SET is_active = NOT is_active WHERE id = ? AND account_id = ?", [$id, $accountId]);
        writeCrontab($accountId, $db);
        Response::success(null, 'Cron job toggled');
    })(),

    default => Response::error("Unknown cron action: $action", 404),
};
