<?php
class DB {
    private static ?DB $instance = null;
    private PDO $pdo;

    private function __construct() {
        $path = defined('DB_PATH') ? DB_PATH : '/var/lib/novacpx/panel.db';
        $dir  = dirname($path);
        if (!is_dir($dir)) mkdir($dir, 0750, true);

        $this->pdo = new PDO(
            "sqlite:{$path}",
            null, null,
            [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]
        );
        $this->pdo->exec("PRAGMA journal_mode = WAL");
        $this->pdo->exec("PRAGMA foreign_keys = ON");
        $this->pdo->exec("PRAGMA busy_timeout = 5000");
    }

    public static function getInstance(): self {
        if (!self::$instance) self::$instance = new self();
        return self::$instance;
    }

    // Translate MySQL-isms to SQLite equivalents
    private function translate(string $sql): string {
        // ON DUPLICATE KEY UPDATE col=VALUES(col) → ON CONFLICT DO UPDATE SET col=excluded.col
        $sql = preg_replace_callback(
            '/ON DUPLICATE KEY UPDATE\s+(.+?)(?=\s*(?:;|$))/is',
            function (array $m): string {
                $pairs = preg_split('/,\s*/', trim($m[1]));
                $sets  = array_map(function (string $pair): string {
                    // Match plain or backtick-quoted column names: `col`=VALUES(`col`) or col=VALUES(col)
                    if (preg_match('/`?(\w+)`?\s*=\s*VALUES\s*\(\s*`?(\w+)`?\s*\)/i', $pair, $pm)) {
                        return "\"{$pm[1]}\"=excluded.\"{$pm[2]}\"";
                    }
                    // col=? or col=expr — strip backticks, keep as-is
                    return preg_replace('/`(\w+)`/', '"$1"', $pair);
                }, $pairs);
                return 'ON CONFLICT DO UPDATE SET ' . implode(', ', $sets);
            },
            $sql
        );

        // NOW() → datetime('now')
        $sql = preg_replace('/\bNOW\(\)/i', "datetime('now')", $sql);

        // UNIX_TIMESTAMP() → strftime('%s','now')
        $sql = preg_replace('/\bUNIX_TIMESTAMP\(\)/i', "strftime('%s','now')", $sql);

        // DATE_ADD(expr, INTERVAL n UNIT) → datetime(expr, '+n unit')
        $sql = preg_replace_callback(
            "/DATE_ADD\s*\(\s*(NOW\(\)|datetime\('now'\))\s*,\s*INTERVAL\s+(\d+)\s+(\w+)\s*\)/i",
            function (array $m): string {
                $n    = $m[2];
                $unit = strtolower($m[3]);
                // Map MySQL interval units to SQLite modifier strings
                $map  = [
                    'second' => 'second', 'seconds' => 'second',
                    'minute' => 'minute', 'minutes' => 'minute',
                    'hour'   => 'hour',   'hours'   => 'hour',
                    'day'    => 'day',    'days'    => 'day',
                    'month'  => 'month',  'months'  => 'month',
                    'year'   => 'year',   'years'   => 'year',
                ];
                $mod = $map[$unit] ?? $unit;
                return "datetime('now', '+{$n} {$mod}')";
            },
            $sql
        );

        // DATE_SUB(expr, INTERVAL n UNIT) → datetime(expr, '-n unit')
        $sql = preg_replace_callback(
            "/DATE_SUB\s*\(\s*(NOW\(\)|datetime\('now'\))\s*,\s*INTERVAL\s+(\d+)\s+(\w+)\s*\)/i",
            function (array $m): string {
                $n    = $m[2];
                $unit = strtolower($m[3]);
                $map  = [
                    'second' => 'second', 'seconds' => 'second',
                    'minute' => 'minute', 'minutes' => 'minute',
                    'hour'   => 'hour',   'hours'   => 'hour',
                    'day'    => 'day',    'days'    => 'day',
                    'month'  => 'month',  'months'  => 'month',
                    'year'   => 'year',   'years'   => 'year',
                ];
                $mod = $map[$unit] ?? $unit;
                return "datetime('now', '-{$n} {$mod}')";
            },
            $sql
        );

        // IFNULL → COALESCE (SQLite supports both but be safe)
        $sql = preg_replace('/\bIFNULL\s*\(/i', 'COALESCE(', $sql);

        // Backtick identifier quoting → double-quote (SQLite standard)
        $sql = preg_replace('/`(\w+)`/', '"$1"', $sql);

        return $sql;
    }

    public function execute(string $sql, array $params = []): PDOStatement {
        $stmt = $this->pdo->prepare($this->translate($sql));
        $stmt->execute($params);
        return $stmt;
    }

    public function fetchOne(string $sql, array $params = []): ?array {
        return $this->execute($sql, $params)->fetch() ?: null;
    }

    public function fetchAll(string $sql, array $params = []): array {
        return $this->execute($sql, $params)->fetchAll();
    }

    public function insert(string $sql, array $params = []): string {
        $this->execute($sql, $params);
        return $this->pdo->lastInsertId();
    }

    public function beginTransaction(): void  { $this->pdo->beginTransaction(); }
    public function commit(): void            { $this->pdo->commit(); }
    public function rollback(): void          { if ($this->pdo->inTransaction()) $this->pdo->rollBack(); }

    public function pdo(): PDO { return $this->pdo; }
}
