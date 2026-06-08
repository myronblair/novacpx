<?php
/**
 * DatabaseManager — MySQL 8 + PostgreSQL database/user provisioning
 */
class DatabaseManager {

    public static function createMySQL(int $accountId, string $dbName, string $dbUser, string $dbPass): int {
        self::validateName($dbName); self::validateName($dbUser);
        $db  = DB::getInstance();
        $pdo = $db->pdo();

        $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $pdo->exec("CREATE USER IF NOT EXISTS '{$dbUser}'@'localhost' IDENTIFIED BY " . $pdo->quote($dbPass));
        $pdo->exec("GRANT ALL PRIVILEGES ON `{$dbName}`.* TO '{$dbUser}'@'localhost'");
        $pdo->exec("FLUSH PRIVILEGES");

        return (int)$db->insert(
            "INSERT INTO `databases`(account_id, db_name, db_user, db_pass, db_type) VALUES (?,?,?,?,?)",
            [$accountId, $dbName, $dbUser, encrypt($dbPass), 'mysql']
        );
    }

    public static function createPostgres(int $accountId, string $dbName, string $dbUser, string $dbPass): int {
        self::validateName($dbName); self::validateName($dbUser);
        $db  = DB::getInstance();
        $safe = escapeshellarg($dbPass);
        shell_exec("sudo -u postgres psql -c \"CREATE USER {$dbUser} WITH PASSWORD {$safe}\" 2>/dev/null");
        shell_exec("sudo -u postgres createdb -O {$dbUser} {$dbName} 2>/dev/null");

        return (int)$db->insert(
            "INSERT INTO `databases`(account_id, db_name, db_user, db_pass, db_type) VALUES (?,?,?,?,?)",
            [$accountId, $dbName, $dbUser, encrypt($dbPass), 'postgresql']
        );
    }

    public static function drop(string $dbName, string $dbUser, string $type = 'mysql'): void {
        if ($type === 'mysql') {
            $pdo = DB::getInstance()->pdo();
            $pdo->exec("DROP DATABASE IF EXISTS `{$dbName}`");
            $pdo->exec("DROP USER IF EXISTS '{$dbUser}'@'localhost'");
            $pdo->exec("FLUSH PRIVILEGES");
        } else {
            shell_exec("sudo -u postgres dropdb --if-exists " . escapeshellarg($dbName) . " 2>/dev/null");
            shell_exec("sudo -u postgres dropuser --if-exists " . escapeshellarg($dbUser) . " 2>/dev/null");
        }
        DB::getInstance()->execute("DELETE FROM `databases` WHERE db_name = ? AND db_type = ?", [$dbName, $type]);
    }

    public static function changePassword(int $id, string $newPass): void {
        $db  = DB::getInstance();
        $dbe = $db->fetchOne("SELECT * FROM `databases` WHERE id = ?", [$id]);
        if (!$dbe) throw new RuntimeException("Database not found");
        if ($dbe['db_type'] === 'mysql') {
            $pdo = $db->pdo();
            $pdo->exec("ALTER USER '{$dbe['db_user']}'@'localhost' IDENTIFIED BY " . $pdo->quote($newPass));
        } else {
            shell_exec("sudo -u postgres psql -c \"ALTER USER {$dbe['db_user']} WITH PASSWORD " . escapeshellarg($newPass) . "\" 2>/dev/null");
        }
        $db->execute("UPDATE `databases` SET db_pass = ? WHERE id = ?", [encrypt($newPass), $id]);
    }

    public static function getSize(string $dbName, string $type = 'mysql'): float {
        if ($type === 'mysql') {
            $row = DB::getInstance()->fetchOne(
                "SELECT ROUND(SUM(data_length + index_length) / 1024 / 1024, 2) AS size
                 FROM information_schema.tables WHERE table_schema = ?",
                [$dbName]
            );
            return (float)($row['size'] ?? 0);
        }
        $out = shell_exec("sudo -u postgres psql -t -c \"SELECT pg_size_pretty(pg_database_size('{$dbName}'))\" 2>/dev/null");
        return (float)$out;
    }

    private static function validateName(string $name): void {
        if (!preg_match('/^[a-zA-Z0-9_]{1,64}$/', $name)) {
            throw new RuntimeException("Invalid database/user name: must be alphanumeric+underscore, max 64 chars");
        }
    }
}

function encrypt(string $val): string {
    $key   = SECRET_KEY;
    $iv    = random_bytes(16);
    $enc   = openssl_encrypt($val, 'aes-256-cbc', substr(hash('sha256', $key, true), 0, 32), OPENSSL_RAW_DATA, $iv);
    return base64_encode($iv . $enc);
}

function decrypt(string $val): string {
    $key  = SECRET_KEY;
    $data = base64_decode($val);
    $iv   = substr($data, 0, 16);
    $enc  = substr($data, 16);
    return openssl_decrypt($enc, 'aes-256-cbc', substr(hash('sha256', $key, true), 0, 32), OPENSSL_RAW_DATA, $iv) ?: '';
}
