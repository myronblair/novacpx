<?php
// Copyright (c) 2026 TomTom Enterprises. Licensed under the MIT License (see LICENSE).
/**
 * VhostManager — creates/removes Apache2 and nginx virtual host configs
 *
 * The panel no longer writes web-server configuration itself: it asks the privileged helper (deploy/novacpx-root) which
 * generates the vhost from validated parameters, so no configuration text ever comes from this (www-data) side.
 */
require_once __DIR__ . '/Root.php';

class VhostManager {

    public static function create(string $username, string $domain, string $docRoot, string $phpVer, bool $suspended = false): void {
        Root::ok('web.vhost.write', [
            'username'    => $username,
            'domain'      => $domain,
            'php_version' => $phpVer,
            'apache_port' => self::getApachePort(),
            'suspended'   => $suspended,
        ]);
    }

    public static function createSubdomain(string $username, string $subdomain, string $docRoot, string $phpVer): void {
        self::create($username, $subdomain, $docRoot, $phpVer);
    }

    public static function suspend(string $username, string $domain): void {
        $acct = DB::getInstance()->fetchOne("SELECT php_version FROM accounts WHERE username = ?", [$username]);
        if (!$acct) throw new RuntimeException("Account not found");
        self::create($username, $domain, "/home/{$username}/public_html", $acct['php_version'], true);
    }

    public static function unsuspend(string $username, string $domain): void {
        // Re-create from DB
        $db   = DB::getInstance();
        $acct = $db->fetchOne("SELECT * FROM accounts WHERE username = ?", [$username]);
        if ($acct) {
            self::create($username, $domain, $acct['home_dir'] . '/public_html', $acct['php_version']);
        }
    }

    public static function remove(string $username, string $domain): void {
        Root::run('web.vhost.remove', ['username' => $username]);
    }

    public static function enableSSL(string $username, string $domain, string $cert, string $key, string $chain = ''): void {
        $acct = DB::getInstance()->fetchOne("SELECT php_version FROM accounts WHERE username = ?", [$username]);
        if (!$acct) throw new RuntimeException("Account not found");
        Root::ok('web.ssl.store', ['username' => $username, 'cert' => $cert, 'key' => $key, 'chain' => $chain]);
        self::create($username, $domain, "/home/{$username}/public_html", $acct['php_version']);
    }

    // Returns the port Apache listens on for customer vhosts.
    // 80 normally; changes to an internal port (e.g. 8090) in local proxy mode.
    public static function getApachePort(): int {
        $db = DB::getInstance();
        return (int)($db->fetchOne("SELECT value FROM settings WHERE `key`='proxy_apache_port'")['value'] ?? 80);
    }

    // Re-write all existing novacpx-*.conf vhosts to use $to instead of $from,
    // and update /etc/apache2/ports.conf accordingly.  Returns count of files changed.
    public static function migrateApachePort(int $from, int $to): int {
        return (int)(Root::json('web.apache.port', ['from' => $from, 'to' => $to])['changed'] ?? 0);
    }

    // Reverse migration: move Apache back from proxy port to standard 80/443.
    public static function restoreApachePort(int $from, int $to = 80): int {
        return (int)(Root::json('web.apache.port', ['from' => $from, 'to' => $to])['changed'] ?? 0);
    }
}
