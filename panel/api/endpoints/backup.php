<?php
require_once NOVACPX_LIB . '/BackupManager.php';
if (!in_array($currentUser['role'], ['admin','reseller','user'])) Response::error('Forbidden', 403);

$bm      = new BackupManager();
$body    = json_decode(file_get_contents('php://input'), true) ?? [];
$isAdmin = $currentUser['role'] === 'admin';

$accountId = (int)($body['account_id'] ?? $_GET['account_id'] ?? 0);
if ($currentUser['role'] === 'user') $accountId = $currentUser['account_id'] ?? 0;

match ($action) {
    'list' => (function() use ($bm, $accountId, $isAdmin) {
        $list = $bm->list($isAdmin ? $accountId : $accountId);
        Response::success(['backups' => $list, 'disk_used' => $bm->diskUsage($accountId)]);
    })(),

    'create' => (function() use ($bm, $body, $accountId) {
        if (!$accountId) Response::error('account_id required');
        $type   = in_array($body['type'] ?? 'full', ['full','files','database']) ? $body['type'] : 'full';
        $result = $bm->create($accountId, $type);
        audit('backup_create', 'backup', ['account_id' => $accountId, 'type' => $type]);
        Response::success($result, 'Backup created successfully');
    })(),

    'restore' => (function() use ($bm, $body, $isAdmin) {
        if (!$isAdmin) Response::error('Admin only', 403);
        $id = (int)($body['id'] ?? 0);
        if (!$id) Response::error('id required');
        $bm->restore($id);
        audit('backup_restore', 'backup', ['id' => $id]);
        Response::success(null, 'Backup restored successfully');
    })(),

    'download' => (function() use ($bm, $body) {
        $id   = (int)($body['id'] ?? $_GET['id'] ?? 0);
        if (!$id) Response::error('id required');
        $path = $bm->getDownloadPath($id);
        $name = basename($path);
        header('Content-Type: application/gzip');
        header("Content-Disposition: attachment; filename=\"{$name}\"");
        header('Content-Length: ' . filesize($path));
        ob_end_clean();
        readfile($path);
        exit;
    })(),

    'delete' => (function() use ($bm, $body, $isAdmin) {
        if (!$isAdmin) Response::error('Admin only', 403);
        $id = (int)($body['id'] ?? 0);
        if (!$id) Response::error('id required');
        $bm->delete($id);
        audit('backup_delete', 'backup', ['id' => $id]);
        Response::success(null, 'Backup deleted');
    })(),

    'schedule' => (function() use ($bm, $body, $accountId, $isAdmin) {
        if (!$isAdmin) Response::error('Admin only', 403);
        if (!$accountId) Response::error('account_id required');
        $freq   = $body['frequency'] ?? 'daily';
        $type   = $body['type']      ?? 'full';
        $retain = (int)($body['retain'] ?? 7);
        $bm->setSchedule($accountId, $freq, $type, $retain);
        Response::success(null, 'Backup schedule saved');
    })(),

    'get-schedule' => (function() use ($bm, $accountId) {
        if (!$accountId) Response::error('account_id required');
        Response::success($bm->getSchedule($accountId));
    })(),

    'upload-remote' => (function() use ($bm, $body, $isAdmin) {
        if (!$isAdmin) Response::error('Admin only', 403);
        $id     = (int)($body['id'] ?? 0);
        $remote = trim($body['remote'] ?? '');
        if (!$id || !$remote) Response::error('id and remote required');
        $out = $bm->uploadRemote($id, $remote);
        Response::success(['output' => $out], 'Upload complete');
    })(),

    default => Response::error('Unknown action', 404),
};
