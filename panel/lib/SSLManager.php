<?php
/**
 * SSLManager — Let's Encrypt (Certbot) + custom certificate management
 */
class SSLManager {

    public static function issueLetsEncrypt(int $accountId, string $domain, string $email = ''): array {
        $db      = DB::getInstance();
        $webRoot = $db->fetchOne("SELECT d.document_root FROM domains d WHERE d.domain = ? AND d.account_id = ?", [$domain, $accountId]);
        if (!$webRoot) throw new RuntimeException("Domain not found for this account");

        $docRoot = $webRoot['document_root'];
        $email   = $email ?: "ssl@{$domain}";
        $cmd     = "certbot certonly --webroot -w {$docRoot} -d {$domain} -d www.{$domain}"
                 . " --email " . escapeshellarg($email)
                 . " --agree-tos --non-interactive 2>&1";
        $out = shell_exec($cmd);

        $certPath  = "/etc/letsencrypt/live/{$domain}/fullchain.pem";
        $keyPath   = "/etc/letsencrypt/live/{$domain}/privkey.pem";
        $chainPath = "/etc/letsencrypt/live/{$domain}/chain.pem";

        if (!file_exists($certPath)) {
            novacpx_log('error', "Certbot failed for $domain: $out");
            throw new RuntimeException("Certbot failed. Check DNS propagation and try again.");
        }

        $cert  = file_get_contents($certPath);
        $key   = file_get_contents($keyPath);
        $chain = file_get_contents($chainPath);

        // Parse expiry
        $expiryRaw = shell_exec("openssl x509 -enddate -noout -in " . escapeshellarg($certPath));
        preg_match('/notAfter=(.+)/', $expiryRaw ?: '', $m);
        $expires = isset($m[1]) ? date('Y-m-d', strtotime($m[1])) : null;

        // Store in DB
        $certId = self::storeCert($accountId, $domain, 'lets_encrypt', $cert, $key, $chain, $expires);

        // Install on vhost
        $acct = $db->fetchOne("SELECT username FROM accounts WHERE id = ?", [$accountId]);
        VhostManager::enableSSL($acct['username'], $domain, $cert, $key, $chain);

        return ['cert_id' => $certId, 'expires' => $expires, 'output' => $out];
    }

    public static function installCustom(int $accountId, string $domain, string $cert, string $key, string $chain = ''): int {
        $db      = DB::getInstance();
        $expiryRaw = shell_exec("echo " . escapeshellarg($cert) . " | openssl x509 -enddate -noout 2>/dev/null");
        preg_match('/notAfter=(.+)/', $expiryRaw ?: '', $m);
        $expires = isset($m[1]) ? date('Y-m-d', strtotime($m[1])) : null;

        $certId = self::storeCert($accountId, $domain, 'custom', $cert, $key, $chain, $expires);

        $acct = $db->fetchOne("SELECT username FROM accounts WHERE id = ?", [$accountId]);
        VhostManager::enableSSL($acct['username'], $domain, $cert, $key, $chain);
        return $certId;
    }

    public static function renewAll(): void {
        $db   = DB::getInstance();
        $soon = $db->fetchAll(
            "SELECT * FROM ssl_certs WHERE auto_renew = 1 AND type = 'lets_encrypt'
             AND expires_at <= DATE_ADD(NOW(), INTERVAL 30 DAY) AND status = 'active'"
        );
        foreach ($soon as $cert) {
            try {
                self::issueLetsEncrypt($cert['account_id'], $cert['domain']);
                novacpx_log('info', "SSL auto-renewed: {$cert['domain']}");
            } catch (Throwable $e) {
                novacpx_log('error', "SSL renewal failed for {$cert['domain']}: " . $e->getMessage());
                $db->execute("UPDATE ssl_certs SET status = 'failed' WHERE id = ?", [$cert['id']]);
            }
        }
    }

    private static function storeCert(int $accountId, string $domain, string $type, string $cert, string $key, string $chain, ?string $expires): int {
        $db = DB::getInstance();
        $db->execute("DELETE FROM ssl_certs WHERE account_id = ? AND domain = ?", [$accountId, $domain]);
        return (int)$db->insert(
            "INSERT INTO ssl_certs (account_id, domain, type, cert, private_key, chain, issued_at, expires_at, status)
             VALUES (?,?,?,?,?,?,NOW(),?,?)",
            [$accountId, $domain, $type, $cert, $key, $chain, $expires, 'active']
        );
    }
}
