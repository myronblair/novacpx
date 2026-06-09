<?php
/**
 * EmailManager — Postfix virtual mailbox + Dovecot user management
 * Uses MySQL backend for both Postfix and Dovecot
 */
class EmailManager {

    public static function createAccount(int $accountId, string $email, string $password, int $quotaMb = 500): int {
        $db     = DB::getInstance();
        $hashed = self::hashPassword($password);
        $enc    = self::encryptPassword($password);
        $id = (int)$db->insert(
            "INSERT INTO email_accounts (account_id, email, password, enc_password, quota_mb) VALUES (?,?,?,?,?)",
            [$accountId, $email, $hashed, $enc, $quotaMb]
        );
        self::syncPostfix();
        novacpx_log('info', "Email account created: $email");
        return $id;
    }

    public static function deleteAccount(int $id): void {
        $db  = DB::getInstance();
        $acc = $db->fetchOne("SELECT email FROM email_accounts WHERE id = ?", [$id]);
        if (!$acc) throw new RuntimeException("Email account not found");
        $db->execute("DELETE FROM email_accounts WHERE id = ?", [$id]);
        self::syncPostfix();
    }

    public static function changePassword(int $id, string $newPassword): void {
        $db = DB::getInstance();
        $db->execute(
            "UPDATE email_accounts SET password = ?, enc_password = ? WHERE id = ?",
            [self::hashPassword($newPassword), self::encryptPassword($newPassword), $id]
        );
    }

    public static function suspend(int $id): void {
        DB::getInstance()->execute("UPDATE email_accounts SET status = 'suspended' WHERE id = ?", [$id]);
        self::syncPostfix();
    }

    public static function addForwarder(int $accountId, string $source, string $destination): int {
        $db = DB::getInstance();
        return (int)$db->insert(
            "INSERT INTO email_forwarders (account_id, source, destination) VALUES (?,?,?)",
            [$accountId, $source, $destination]
        );
    }

    public static function removeForwarder(int $id): void {
        DB::getInstance()->execute("DELETE FROM email_forwarders WHERE id = ?", [$id]);
        self::syncPostfix();
    }

    public static function addAutoresponder(int $accountId, string $email, string $subject, string $body): int {
        $db = DB::getInstance();
        return (int)$db->insert(
            "INSERT INTO email_autoresponders (account_id, email, subject, body, is_active) VALUES (?,?,?,?,1)",
            [$accountId, $email, $subject, $body]
        );
    }

    /**
     * Decrypt stored IMAP password for SSO use only.
     */
    public static function decryptPassword(string $enc): ?string {
        $key  = substr(hash('sha256', SECRET_KEY, true), 0, 32);
        $data = base64_decode($enc);
        if (strlen($data) <= 16) return null;
        $iv        = substr($data, 0, 16);
        $encrypted = substr($data, 16);
        $plain = openssl_decrypt($encrypted, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
        return $plain !== false ? $plain : null;
    }

    /**
     * Sync Postfix virtual_mailbox_maps + virtual_alias_maps files from DB
     */
    private static function syncPostfix(): void {
        $db = DB::getInstance();

        // Virtual mailbox map
        $accounts = $db->fetchAll("SELECT ea.email, a.username FROM email_accounts ea JOIN accounts a ON a.id = ea.account_id WHERE ea.status = 'active'");
        $mailboxes = '';
        foreach ($accounts as $a) {
            $domain = substr(strrchr($a['email'], '@'), 1);
            $user   = strstr($a['email'], '@', true);
            $mailboxes .= "{$a['email']}   {$a['username']}/{$domain}/{$user}/\n";
        }
        self::writePostfixFile('/etc/postfix/novacpx_mailboxes', $mailboxes);
        shell_exec('sudo postmap /etc/postfix/novacpx_mailboxes 2>/dev/null');

        // Virtual alias map (forwarders)
        $forwarders = $db->fetchAll("SELECT source, destination FROM email_forwarders");
        $aliases = '';
        foreach ($forwarders as $f) {
            $aliases .= "{$f['source']}   {$f['destination']}\n";
        }
        self::writePostfixFile('/etc/postfix/novacpx_aliases', $aliases);
        shell_exec('sudo postmap /etc/postfix/novacpx_aliases 2>/dev/null');

        // Virtual domains map — SQLite-compatible (no SUBSTRING_INDEX)
        $domains = $db->fetchAll("SELECT DISTINCT SUBSTR(email, INSTR(email,'@') + 1) AS domain FROM email_accounts WHERE status='active'");
        $vdomains = '';
        foreach ($domains as $d) { $vdomains .= "{$d['domain']}   novacpx\n"; }
        self::writePostfixFile('/etc/postfix/novacpx_domains', $vdomains);
        shell_exec('sudo postmap /etc/postfix/novacpx_domains 2>/dev/null');
        shell_exec('sudo systemctl reload postfix 2>/dev/null || true');
    }

    private static function writePostfixFile(string $path, string $content): void {
        $tmp = tempnam('/tmp', 'ncpx_pf_');
        file_put_contents($tmp, $content);
        shell_exec('sudo tee ' . escapeshellarg($path) . ' > /dev/null < ' . escapeshellarg($tmp));
        @unlink($tmp);
    }

    private static function hashPassword(string $password): string {
        return '{SHA512-CRYPT}' . crypt($password, '$6$' . bin2hex(random_bytes(8)) . '$');
    }

    private static function encryptPassword(string $password): string {
        $key = substr(hash('sha256', SECRET_KEY, true), 0, 32);
        $iv  = random_bytes(16);
        $enc = openssl_encrypt($password, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
        return base64_encode($iv . $enc);
    }
}
