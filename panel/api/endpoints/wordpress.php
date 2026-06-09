<?php
require_once NOVACPX_LIB . '/WordPressManager.php';
if (!in_array($currentUser['role'], ['admin','reseller','user'])) Response::error('Forbidden', 403);

$wp    = new WordPressManager();
$body  = json_decode(file_get_contents('php://input'), true) ?? [];
$isAdmin = $currentUser['role'] === 'admin';

// Scope account_id: users can only manage their own
$accountId = (int)($body['account_id'] ?? $_GET['account_id'] ?? 0);
if ($currentUser['role'] === 'user') {
    $accountId = $currentUser['account_id'] ?? 0;
}

match ($action) {
    'list' => (function() use ($wp, $accountId, $isAdmin) {
        Response::success($wp->list($isAdmin ? $accountId : $accountId));
    })(),

    'install' => (function() use ($wp, $body, $accountId, $currentUser) {
        $required = ['domain','path','admin_user','admin_email','admin_pass','site_title'];
        foreach ($required as $f) if (empty($body[$f])) Response::error("Missing: {$f}");
        if (!$accountId) Response::error('account_id required');
        $result = $wp->install($accountId, $body['domain'], $body['path'],
            $body['admin_user'], $body['admin_email'], $body['admin_pass'], $body['site_title']);
        audit('wordpress_install', 'wordpress', ['domain' => $body['domain']]);
        Response::success($result, 'WordPress installed successfully');
    })(),

    'install-stream' => (function() use ($wp, $body, $accountId) {
        $required = ['domain','path','admin_user','admin_email','admin_pass','site_title'];
        foreach ($required as $f) if (empty($body[$f])) {
            header('Content-Type: text/event-stream');
            echo 'data: ' . json_encode(['error' => "Missing: {$f}"]) . "\n\n";
            flush(); exit;
        }
        if (!$accountId) {
            header('Content-Type: text/event-stream');
            echo 'data: ' . json_encode(['error' => 'account_id required']) . "\n\n";
            flush(); exit;
        }
        header('Content-Type: text/event-stream');
        header('Cache-Control: no-cache');
        header('X-Accel-Buffering: no');
        ob_implicit_flush(true);
        while (ob_get_level() > 0) ob_end_flush();
        try {
            foreach ($wp->installStream($accountId, $body['domain'], $body['path'],
                        $body['admin_user'], $body['admin_email'], $body['admin_pass'],
                        $body['site_title']) as $line) {
                echo 'data: ' . json_encode(['line' => $line]) . "\n\n";
                flush();
            }
        } catch (Throwable $e) {
            echo 'data: ' . json_encode(['error' => $e->getMessage()]) . "\n\n";
            flush();
        }
        echo 'data: ' . json_encode(['done' => true]) . "\n\n";
        flush();
        exit;
    })(),

    'update-core' => (function() use ($wp, $body) {
        $id = (int)($body['id'] ?? 0);
        if (!$id) Response::error('id required');
        Response::success(['output' => $wp->updateCore($id)], 'Core updated');
    })(),

    'update-plugins' => (function() use ($wp, $body) {
        $id = (int)($body['id'] ?? 0);
        if (!$id) Response::error('id required');
        Response::success(['output' => $wp->updatePlugins($id)], 'Plugins updated');
    })(),

    'update-themes' => (function() use ($wp, $body) {
        $id = (int)($body['id'] ?? 0);
        if (!$id) Response::error('id required');
        Response::success(['output' => $wp->updateThemes($id)], 'Themes updated');
    })(),

    'clone-staging' => (function() use ($wp, $body) {
        $id = (int)($body['id'] ?? 0);
        if (!$id) Response::error('id required');
        $result = $wp->cloneStaging($id);
        audit('wordpress_staging', 'wordpress', ['id' => $id]);
        Response::success($result, 'Staging clone created');
    })(),

    'info' => (function() use ($wp, $body) {
        $id = (int)($body['id'] ?? $_GET['id'] ?? 0);
        if (!$id) Response::error('id required');
        Response::success($wp->info($id));
    })(),

    'delete' => (function() use ($wp, $body, $isAdmin) {
        if (!$isAdmin) Response::error('Admin only', 403);
        $id = (int)($body['id'] ?? 0);
        if (!$id) Response::error('id required');
        $wp->delete($id);
        audit('wordpress_delete', 'wordpress', ['id' => $id]);
        Response::success(null, 'WordPress installation deleted');
    })(),

    default => Response::error('Unknown action', 404),
};
