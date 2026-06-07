<?php
/**
 * Domains endpoint — addon domains, subdomains, parked/aliased domains
 */
$db   = DB::getInstance();
$body = json_decode(file_get_contents('php://input'), true) ?? [];
require_once NOVACPX_LIB . '/VhostManager.php';
require_once NOVACPX_LIB . '/DNSManager.php';

$user      = Auth::getInstance()->user();
$accountId = $user['role'] === 'user'
    ? (int)($db->fetchOne("SELECT id FROM accounts WHERE user_id = ?", [$user['uid']])['id'] ?? 0)
    : (int)($body['account_id'] ?? $_GET['account_id'] ?? 0);

if (!$accountId) Response::error("account_id required");
$acct = $db->fetchOne("SELECT * FROM accounts WHERE id = ?", [$accountId]);
if (!$acct) Response::error("Account not found", 404);

match ($action) {
    'list' => (function() use ($db, $accountId) {
        $rows = $db->fetchAll("SELECT * FROM domains WHERE account_id = ? ORDER BY is_primary DESC, domain", [$accountId]);
        Response::success($rows);
    })(),

    'add-addon' => (function() use ($db, $body, $accountId, $acct) {
        $domain = strtolower(trim($body['domain'] ?? ''));
        if (!$domain) Response::error("domain required");
        if (!preg_match('/^[a-z0-9][a-z0-9\-\.]+\.[a-z]{2,}$/', $domain)) Response::error("Invalid domain name");

        $exists = $db->fetchOne("SELECT id FROM domains WHERE domain = ?", [$domain]);
        if ($exists) Response::error("Domain already exists");

        $db->execute(
            "INSERT INTO domains (account_id, domain, type, doc_root, created_at) VALUES (?,?,?,?,NOW())",
            [$accountId, $domain, 'addon', $acct['home_dir'] . '/public_html/' . $domain]
        );
        $docRoot = $acct['home_dir'] . '/public_html/' . $domain;
        @mkdir($docRoot, 0755, true);
        file_put_contents("$docRoot/index.html", "<html><body><h1>$domain is ready!</h1><p>Upload your website files.</p></body></html>");

        VhostManager::create([
            'domain'   => $domain,
            'username' => $acct['username'],
            'home_dir' => $acct['home_dir'],
            'doc_root' => $docRoot,
            'php_ver'  => $acct['php_version'] ?? PHP_DEFAULT,
        ]);
        DNSManager::createZone($domain, gethostbyname(gethostname()));
        audit('domains.add-addon', $domain);
        Response::success(null, "Addon domain $domain added");
    })(),

    'add-subdomain' => (function() use ($db, $body, $accountId, $acct) {
        $sub    = strtolower(trim($body['subdomain'] ?? ''));
        $parent = strtolower(trim($body['parent'] ?? $acct['domain']));
        $full   = "$sub.$parent";
        if (!$sub) Response::error("subdomain required");

        $docRoot = $acct['home_dir'] . '/public_html/' . $full;
        @mkdir($docRoot, 0755, true);
        file_put_contents("$docRoot/index.html", "<html><body><h1>$full</h1></body></html>");

        $db->execute(
            "INSERT INTO domains (account_id, domain, type, doc_root, created_at) VALUES (?,?,?,?,NOW())",
            [$accountId, $full, 'subdomain', $docRoot]
        );
        VhostManager::createSubdomain($full, $acct['username'], $docRoot, $acct['php_version'] ?? PHP_DEFAULT);
        DNSManager::addRecord($parent, $sub, 'A', gethostbyname(gethostname()));
        audit('domains.add-subdomain', $full);
        Response::success(null, "Subdomain $full created");
    })(),

    'add-alias' => (function() use ($db, $body, $accountId, $acct) {
        $alias  = strtolower(trim($body['domain'] ?? ''));
        $target = strtolower(trim($body['target'] ?? $acct['domain']));
        if (!$alias) Response::error("domain required");

        $db->execute(
            "INSERT INTO domains (account_id, domain, type, doc_root, created_at) VALUES (?,?,?,?,NOW())",
            [$accountId, $alias, 'alias', $acct['home_dir'] . '/public_html']
        );
        // Create vhost that serves same doc root as primary
        VhostManager::create([
            'domain'   => $alias,
            'username' => $acct['username'],
            'home_dir' => $acct['home_dir'],
            'doc_root' => $acct['home_dir'] . '/public_html',
            'php_ver'  => $acct['php_version'] ?? PHP_DEFAULT,
        ]);
        DNSManager::createZone($alias, gethostbyname(gethostname()));
        audit('domains.add-alias', $alias);
        Response::success(null, "Domain alias $alias added");
    })(),

    'redirect' => (function() use ($db, $body, $accountId) {
        $id   = (int)($body['id'] ?? 0);
        $url  = trim($body['redirect_url'] ?? '');
        $code = in_array((int)($body['code'] ?? 301), [301, 302]) ? (int)$body['code'] : 301;
        if (!$url) Response::error("redirect_url required");
        $db->execute("UPDATE domains SET redirect_url=?, redirect_code=? WHERE id=? AND account_id=?", [$url, $code, $id, $accountId]);
        // Update vhost to add Redirect directive
        $dom = $db->fetchOne("SELECT * FROM domains WHERE id = ?", [$id]);
        if ($dom) {
            VhostManager::setRedirect($dom['domain'], $url, $code);
        }
        Response::success(null, 'Redirect configured');
    })(),

    'remove' => (function() use ($db, $body, $accountId) {
        $id  = (int)($body['id'] ?? 0);
        $dom = $db->fetchOne("SELECT * FROM domains WHERE id = ? AND account_id = ?", [$id, $accountId]);
        if (!$dom) Response::error("Domain not found", 404);
        if ($dom['is_primary']) Response::error("Cannot remove primary domain");

        VhostManager::remove($dom['domain']);
        if ($dom['type'] !== 'subdomain') DNSManager::removeZone($dom['domain']);
        $db->execute("DELETE FROM domains WHERE id = ?", [$id]);
        audit('domains.remove', $dom['domain']);
        Response::success(null, "Domain {$dom['domain']} removed");
    })(),

    default => Response::error("Unknown domains action: $action", 404),
};
