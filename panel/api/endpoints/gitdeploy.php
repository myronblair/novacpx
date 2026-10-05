<?php
// Copyright (c) 2026 TomTom Enterprises. Licensed under the MIT License (see LICENSE).
/**
 * Git Deploy API.
 *   GET  /api/gitdeploy/get[?account_id=N]
 *   POST /api/gitdeploy/save   {repo_url, branch?, subdir?, token?, clear_token?, overwrite?}  connect (or change) and deploy once
 *   POST /api/gitdeploy/deploy {}                                                              pull the latest now
 *   POST /api/gitdeploy/secret {}                                                              new webhook secret
 *   POST /api/gitdeploy/disconnect {}                                                          forget the connection (files stay)
 * The webhook that deploys on every push is /api/githook/run?account=ID (see githook.php).
 */
require_once NOVACPX_LIB . '/Root.php';
require_once NOVACPX_LIB . '/GitDeploy.php';

$db   = DB::getInstance();
$body = json_decode(file_get_contents('php://input'), true) ?? [];
$cu   = Auth::getInstance()->user();
$accountId = $cu['role'] === 'user'
    ? (int)($db->fetchOne("SELECT id FROM accounts WHERE user_id = ?", [$cu['uid']])['id'] ?? 0)
    : (int)($body['account_id'] ?? $_GET['account_id'] ?? 0);
$acct = assert_account_access($accountId);

$publicRow = function (?array $r) use ($accountId): ?array {
    if (!$r) return null;
    $host = $_SERVER['HTTP_HOST'] ?? 'your-panel';
    return [
        'repo_url' => $r['repo_url'], 'branch' => $r['branch'], 'subdir' => $r['subdir'], 'has_token' => (bool)$r['has_token'],
        'last_deploy' => $r['last_deploy'], 'last_status' => $r['last_status'], 'last_commit' => $r['last_commit'], 'last_output' => $r['last_output'],
        'webhook_url' => "https://{$host}/api/githook/run?account={$accountId}", 'secret' => $r['secret'],
    ];
};

match ($action) {
    'get' => Response::success($publicRow(GitDeploy::get($accountId))),

    'save' => (function() use ($db, $body, $acct, $accountId, $publicRow) {
        $url    = trim((string)($body['repo_url'] ?? ''));
        $branch = trim((string)($body['branch'] ?? '')) ?: 'main';
        $subdir = trim((string)($body['subdir'] ?? ''), " /");
        if (!preg_match('#^https://#', $url)) Response::error('The repository address must start with https://');
        $token  = isset($body['token']) ? trim((string)$body['token']) : null;
        $existing = GitDeploy::get($accountId);
        $r = GitDeploy::run($acct, ['repo_url' => $url, 'branch' => $branch, 'subdir' => $subdir], !empty($body['overwrite']), $token, !empty($body['clear_token']));
        if (!$r['ok']) Response::error(trim($r['output']) !== '' ? $r['output'] : 'The deploy failed');
        $secret = $existing['secret'] ?? bin2hex(random_bytes(20));
        $db->execute(
            "INSERT INTO git_deploy (account_id, repo_url, branch, subdir, secret, has_token) VALUES (?,?,?,?,?,?)
             ON CONFLICT(account_id) DO UPDATE SET repo_url = excluded.repo_url, branch = excluded.branch, subdir = excluded.subdir, updated_at = datetime('now')",
            [$accountId, $url, $branch, $subdir, $secret, $r['has_token'] ? 1 : 0]);
        GitDeploy::record($accountId, $r);
        audit('gitdeploy.save', $acct['username'], ['repo' => $url, 'branch' => $branch]);
        Response::success($publicRow(GitDeploy::get($accountId)), 'Connected and deployed ' . $r['commit']);
    })(),

    'deploy' => (function() use ($acct, $accountId, $publicRow) {
        $cfg = GitDeploy::get($accountId);
        if (!$cfg) Response::error('No repository is connected');
        $r = GitDeploy::run($acct, $cfg);
        GitDeploy::record($accountId, $r);
        audit('gitdeploy.deploy', $acct['username'], ['ok' => $r['ok']]);
        if (!$r['ok']) Response::error(trim($r['output']) !== '' ? $r['output'] : 'The deploy failed');
        Response::success($publicRow(GitDeploy::get($accountId)), 'Deployed ' . $r['commit']);
    })(),

    'secret' => (function() use ($db, $accountId, $publicRow) {
        if (!GitDeploy::get($accountId)) Response::error('No repository is connected');
        $db->execute("UPDATE git_deploy SET secret = ? WHERE account_id = ?", [bin2hex(random_bytes(20)), $accountId]);
        Response::success($publicRow(GitDeploy::get($accountId)), 'New webhook secret created - update it in your repository settings');
    })(),

    'disconnect' => (function() use ($db, $acct, $accountId) {
        try { Root::ok('git.forget', ['username' => $acct['username']]); } catch (RuntimeException $e) { /* token file may not exist */ }
        $db->execute("DELETE FROM git_deploy WHERE account_id = ?", [$accountId]);
        audit('gitdeploy.disconnect', $acct['username']);
        Response::success(null, 'Disconnected');
    })(),

    default => Response::error("Unknown gitdeploy action: $action", 404),
};
