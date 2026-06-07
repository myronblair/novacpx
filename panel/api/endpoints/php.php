<?php
$db   = DB::getInstance();
$body = json_decode(file_get_contents('php://input'), true) ?? [];
require_once NOVACPX_LIB . '/PHPManager.php';

$user      = Auth::getInstance()->user();
$accountId = $user['role'] === 'user'
    ? (int)($db->fetchOne("SELECT id FROM accounts WHERE user_id = ?", [$user['uid']])['id'] ?? 0)
    : (int)($body['account_id'] ?? $_GET['account_id'] ?? 0);

match ($action) {
    'config' => (function() use ($db, $accountId) {
        $cfg  = $db->fetchOne("SELECT * FROM php_configs WHERE account_id = ?", [$accountId]);
        $acct = $db->fetchOne("SELECT php_version FROM accounts WHERE id = ?", [$accountId]);
        Response::success([
            'php_version'        => $acct['php_version'] ?? PHP_DEFAULT,
            'memory_limit'       => $cfg['memory_limit']        ?? '256M',
            'max_execution_time' => $cfg['max_execution_time']  ?? 30,
            'upload_max_filesize'=> $cfg['upload_max_filesize'] ?? '64M',
            'post_max_size'      => $cfg['post_max_size']       ?? '64M',
            'display_errors'     => (bool)($cfg['display_errors'] ?? false),
            'extensions'         => json_decode($cfg['extensions'] ?? '[]', true) ?: [],
        ]);
    })(),

    'versions' => (function() {
        $versions = [];
        foreach (['7.4','8.1','8.2','8.3'] as $v) {
            $installed = file_exists("/usr/bin/php{$v}");
            $versions[] = ['version' => $v, 'installed' => $installed, 'is_default' => $v === PHP_DEFAULT];
        }
        Response::success($versions);
    })(),

    'switch-version' => (function() use ($body, $accountId) {
        $ver = $body['version'] ?? '';
        if (!in_array($ver, ['7.4','8.1','8.2','8.3'])) Response::error("Invalid PHP version");
        PHPManager::switchVersion($accountId, $ver);
        audit('php.switch-version', "account:{$accountId} → php{$ver}");
        Response::success(null, "PHP version switched to $ver");
    })(),

    'update-config' => (function() use ($body, $accountId) {
        PHPManager::updateConfig($accountId, $body);
        audit('php.update-config', "account:$accountId");
        Response::success(null, 'PHP configuration updated');
    })(),

    'extensions' => (function() use ($db, $accountId) {
        $acct = $db->fetchOne("SELECT php_version FROM accounts WHERE id = ?", [$accountId]);
        $ver  = $acct['php_version'] ?? PHP_DEFAULT;
        Response::success(['version' => $ver, 'extensions' => PHPManager::listExtensions($ver)]);
    })(),

    default => Response::error("Unknown php action: $action", 404),
};
