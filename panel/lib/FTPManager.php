<?php
/**
 * FTPManager — ProFTPD virtual user management via MySQL
 */
class FTPManager {

    public static function createAccount(int $accountId, string $username, string $password, string $homeDir, int $quotaMb = 0): int {
        $db     = DB::getInstance();
        $hashed = password_hash($password, PASSWORD_BCRYPT);

        // Create system user for FTP (no shell, no home dir creation)
        $acct = $db->fetchOne("SELECT username as owner FROM accounts WHERE id = ?", [$accountId]);
        $ftpUser = strtolower(preg_replace('/[^a-z0-9_]/', '', $username));

        $id = (int)$db->insert(
            "INSERT INTO ftp_accounts (account_id, username, password, home_dir, quota_mb) VALUES (?,?,?,?,?)",
            [$accountId, $ftpUser, $hashed, $homeDir, $quotaMb]
        );

        self::syncProftpd();
        novacpx_log('info', "FTP account created: $ftpUser");
        return $id;
    }

    public static function deleteAccount(int $id): void {
        DB::getInstance()->execute("DELETE FROM ftp_accounts WHERE id = ?", [$id]);
        self::syncProftpd();
    }

    public static function changePassword(int $id, string $newPassword): void {
        DB::getInstance()->execute(
            "UPDATE ftp_accounts SET password = ? WHERE id = ?",
            [password_hash($newPassword, PASSWORD_BCRYPT), $id]
        );
        self::syncProftpd();
    }

    public static function suspend(int $id): void {
        DB::getInstance()->execute("UPDATE ftp_accounts SET status = 'suspended' WHERE id = ?", [$id]);
        self::syncProftpd();
    }

    private static function syncProftpd(): void {
        // Write ProFTPD virtual users file (passwd format)
        $db       = DB::getInstance();
        $accounts = $db->fetchAll("SELECT f.*, a.username as owner FROM ftp_accounts f JOIN accounts a ON a.id = f.account_id WHERE f.status = 'active'");
        $passwd   = '';
        foreach ($accounts as $a) {
            $uid   = self::getUid($a['owner']);
            $gid   = self::getGid('www-data');
            $passwd .= "{$a['username']}:{$a['password']}:{$uid}:{$gid}:NovaCPX FTP:{$a['home_dir']}:/sbin/nologin\n";
        }
        file_put_contents('/etc/proftpd/novacpx-users.passwd', $passwd);
        shell_exec('systemctl reload proftpd 2>/dev/null || true');
    }

    private static function getUid(string $username): int {
        $out = trim(shell_exec("id -u " . escapeshellarg($username) . " 2>/dev/null") ?: '33');
        return (int)$out;
    }

    private static function getGid(string $group): int {
        $out = trim(shell_exec("getent group " . escapeshellarg($group) . " | cut -d: -f3 2>/dev/null") ?: '33');
        return (int)$out;
    }
}
