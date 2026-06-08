<?php
$db   = DB::getInstance();
$body = json_decode(file_get_contents('php://input'), true) ?? [];
require_once NOVACPX_LIB . '/EmailManager.php';

$user      = Auth::getInstance()->user();
$accountId = self_account_id($db, $user);

function self_account_id($db, $user): ?int {
    if ($user['role'] === 'user') {
        $a = $db->fetchOne("SELECT id FROM accounts WHERE user_id = ?", [$user['uid']]);
        return $a ? (int)$a['id'] : null;
    }
    return (int)(request_param('account_id') ?? 0) ?: null;
}
function request_param(string $k): mixed { return $_GET[$k] ?? (json_decode(file_get_contents('php://input'), true) ?? [])[$k] ?? null; }

match ($action) {

    'list' => (function() use ($db, $accountId) {
        if (!$accountId) Response::error("account_id required");
        $rows = $db->fetchAll("SELECT id, email, quota_mb, used_mb, status, created_at FROM email_accounts WHERE account_id = ? ORDER BY email", [$accountId]);
        Response::success($rows);
    })(),

    'create' => (function() use ($db, $body, $accountId) {
        $email    = strtolower(trim($body['email'] ?? ''));
        $password = $body['password'] ?? '';
        $quota    = (int)($body['quota_mb'] ?? 500);
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) Response::error("Invalid email address");
        if (strlen($password) < 6) Response::error("Password must be at least 6 characters");
        if (!$accountId) Response::error("account_id required");
        // Package limit check
        $acctPkg = $db->fetchOne("SELECT p.max_email FROM accounts a LEFT JOIN packages p ON p.id=a.package_id WHERE a.id=?", [$accountId]);
        if ($acctPkg && $acctPkg['max_email'] > 0) {
            $count = (int)$db->fetchOne("SELECT COUNT(*) c FROM email_accounts WHERE account_id=?", [$accountId])['c'];
            if ($count >= (int)$acctPkg['max_email']) Response::error("Email account limit ({$acctPkg['max_email']}) reached for this package", 403);
        }
        $id = EmailManager::createAccount($accountId, $email, $password, $quota);
        audit('email.create', $email);
        Response::success(['id' => $id], "Email account created: $email");
    })(),

    'delete' => (function() use ($db, $body) {
        $id = (int)($body['id'] ?? 0);
        EmailManager::deleteAccount($id);
        audit('email.delete', "email:$id");
        Response::success(null, 'Email account deleted');
    })(),

    'change-password' => (function() use ($body) {
        $id   = (int)($body['id'] ?? 0);
        $pass = $body['password'] ?? '';
        if (strlen($pass) < 6) Response::error("Password too short");
        EmailManager::changePassword($id, $pass);
        Response::success(null, 'Password updated');
    })(),

    'suspend' => (function() use ($body) {
        EmailManager::suspend((int)($body['id'] ?? 0));
        Response::success(null, 'Email account suspended');
    })(),

    // ── Forwarders ───────────────────────────────────────────────────────────
    'forwarders' => (function() use ($db, $accountId) {
        if (!$accountId) Response::error("account_id required");
        Response::success($db->fetchAll("SELECT * FROM email_forwarders WHERE account_id = ?", [$accountId]));
    })(),

    'add-forwarder' => (function() use ($db, $body, $accountId) {
        $source = strtolower(trim($body['source'] ?? ''));
        $dest   = strtolower(trim($body['destination'] ?? ''));
        if (!filter_var($dest, FILTER_VALIDATE_EMAIL)) Response::error("Invalid destination email");
        $id = EmailManager::addForwarder($accountId, $source, $dest);
        audit('email.add-forwarder', "{$source}→{$dest}");
        Response::success(['id' => $id], 'Forwarder added');
    })(),

    'delete-forwarder' => (function() use ($body) {
        EmailManager::removeForwarder((int)($body['id'] ?? 0));
        Response::success(null, 'Forwarder removed');
    })(),

    // ── Autoresponders ───────────────────────────────────────────────────────
    'autoresponders' => (function() use ($db, $accountId) {
        Response::success($db->fetchAll("SELECT * FROM email_autoresponders WHERE account_id = ?", [$accountId ?? 0]));
    })(),

    'add-autoresponder' => (function() use ($body, $accountId) {
        $id = EmailManager::addAutoresponder($accountId, $body['email'] ?? '', $body['subject'] ?? '', $body['body'] ?? '');
        Response::success(['id' => $id], 'Autoresponder created');
    })(),

    'delete-autoresponder' => (function() use ($db, $body) {
        $db->execute("DELETE FROM email_autoresponders WHERE id = ?", [(int)($body['id'] ?? 0)]);
        Response::success(null, 'Autoresponder deleted');
    })(),

    default => Response::error("Unknown email action: $action", 404),
};
