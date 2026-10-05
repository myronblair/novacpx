<?php
// Copyright (c) 2026 TomTom Enterprises. Licensed under the MIT License (see LICENSE).
/**
 * Git Deploy: keep a site's files in step with a Git repository (https only). The work is done by the privileged helper
 * (git.deploy), which runs git as the account's own user, with hooks disabled, inside the account's public_html only.
 */
require_once __DIR__ . '/Root.php';

class GitDeploy {

    public static function get(int $accountId): ?array {
        return DB::getInstance()->fetchOne("SELECT * FROM git_deploy WHERE account_id = ?", [$accountId]) ?: null;
    }

    /** Run a deploy for the stored connection (or the given new settings) and record the result. @return array{ok:bool,commit:string,output:string} */
    public static function run(array $acct, array $cfg, bool $overwrite = false, ?string $token = null, bool $clearToken = false): array {
        $params = ['username' => $acct['username'], 'repo_url' => $cfg['repo_url'], 'branch' => $cfg['branch'], 'subdir' => $cfg['subdir'] ?? '', 'overwrite' => $overwrite];
        if ($token !== null && $token !== '') $params['token'] = $token;
        if ($clearToken) $params['clear_token'] = true;
        try {
            $r = Root::json('git.deploy', $params);
        } catch (RuntimeException $e) {
            $r = ['ok' => false, 'output' => $e->getMessage()];
        }
        return ['ok' => !empty($r['ok']), 'commit' => (string)($r['commit'] ?? ''), 'output' => (string)($r['output'] ?? ''), 'has_token' => !empty($r['has_token'])];
    }

    public static function record(int $accountId, array $r): void {
        DB::getInstance()->execute(
            "UPDATE git_deploy SET last_deploy = datetime('now'), last_status = ?, last_commit = ?, last_output = ?, has_token = ? WHERE account_id = ?",
            [$r['ok'] ? 'ok' : 'failed', $r['commit'], mb_substr($r['output'], -2000), !empty($r['has_token']) ? 1 : 0, $accountId]);
    }
}
