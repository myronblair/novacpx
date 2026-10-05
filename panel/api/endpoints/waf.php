<?php
// Copyright (c) 2026 TomTom Enterprises. Licensed under the MIT License (see LICENSE).
/**
 * Request firewall API (per account): blocks common attack patterns in the web address, headers and request method before they
 * reach the site. This is a first line of defence, not a replacement for keeping the site's software up to date.
 *
 *   GET  /api/waf/get[?account_id=N]
 *   POST /api/waf/save {account_id?, mode: off|block, rules:[slug], exempt:[path]}
 */
require_once NOVACPX_LIB . '/Root.php';

const WAF_RULES = [
    'sqli'      => ['SQL injection',        'Database commands smuggled into a web address (UNION SELECT, sleep(), quote-or-1=1 tricks).'],
    'xss'       => ['Script injection',     'Script or iframe tags and event handlers placed in a web address.'],
    'traversal' => ['Path traversal',       'Attempts to climb out of the site folder (../) or read system files.'],
    'inclusion' => ['Code inclusion',       'php:// and data:// wrappers, remote include switches, eval() and base64_decode() in the address.'],
    'scanners'  => ['Attack scanners',      'Well-known vulnerability scanners identified by their browser string.'],
    'sensitive' => ['Sensitive files',      'Requests for .git, .env, .htaccess, backups and database dumps, composer files.'],
    'methods'   => ['Odd request methods',  'TRACE, TRACK and DEBUG requests.'],
    'xmlrpc'    => ['WordPress XML-RPC',    'Blocks /xmlrpc.php, a favourite target for password-guessing.'],
];

$db   = DB::getInstance();
$body = json_decode(file_get_contents('php://input'), true) ?? [];
$cu   = Auth::getInstance()->user();
$accountId = $cu['role'] === 'user'
    ? (int)($db->fetchOne("SELECT id FROM accounts WHERE user_id = ?", [$cu['uid']])['id'] ?? 0)
    : (int)($body['account_id'] ?? $_GET['account_id'] ?? 0);
$acct = assert_account_access($accountId);

match ($action) {
    'get' => (function() use ($db, $accountId) {
        $row = $db->fetchOne("SELECT mode, rules, exempt FROM waf_rules WHERE account_id = ?", [$accountId]);
        $catalog = [];
        foreach (WAF_RULES as $slug => [$name, $desc]) $catalog[] = ['slug' => $slug, 'name' => $name, 'description' => $desc];
        Response::success([
            'mode'    => $row['mode'] ?? 'off',
            'rules'   => $row ? (json_decode($row['rules'], true) ?: []) : array_keys(WAF_RULES),
            'exempt'  => $row ? (json_decode($row['exempt'], true) ?: []) : [],
            'catalog' => $catalog,
            'web_server' => $db->fetchOne("SELECT value FROM settings WHERE key = 'web_server'")['value'] ?? 'nginx',
        ]);
    })(),

    'save' => (function() use ($db, $body, $accountId, $acct) {
        $mode = (string)($body['mode'] ?? 'off');
        if (!in_array($mode, ['off', 'block'], true)) Response::error('Invalid mode');
        $rules = [];
        foreach ((array)($body['rules'] ?? []) as $r) {
            if (!isset(WAF_RULES[$r])) Response::error('Unknown rule');
            $rules[] = $r;
        }
        $rules = array_values(array_unique($rules));
        $exempt = [];
        foreach ((array)($body['exempt'] ?? []) as $x) {
            $x = trim((string)$x);
            if ($x === '') continue;
            if ($x[0] !== '/') $x = '/' . $x;
            $exempt[] = $x;
        }
        try { Root::ok('waf.write', ['username' => $acct['username'], 'mode' => $mode, 'rules' => $rules, 'exempt' => $exempt]); }
        catch (RuntimeException $e) { Response::error($e->getMessage()); }
        $db->execute(
            "INSERT INTO waf_rules (account_id, mode, rules, exempt, updated_at) VALUES (?,?,?,?,datetime('now'))
             ON CONFLICT(account_id) DO UPDATE SET mode = excluded.mode, rules = excluded.rules, exempt = excluded.exempt, updated_at = excluded.updated_at",
            [$accountId, $mode, json_encode($rules), json_encode($exempt)]);
        audit('waf.save', $acct['username'], ['mode' => $mode, 'rules' => count($rules)]);
        Response::success(null, $mode === 'block' ? 'Firewall is on' : 'Firewall is off');
    })(),

    default => Response::error("Unknown waf action: $action", 404),
};
