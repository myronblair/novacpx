<?php
// Copyright (c) 2026 TomTom Enterprises. Licensed under the MIT License (see LICENSE).
/**
 * Sweep: malware scanner for hosting accounts.
 *
 * Two layers: (1) a built-in pattern scan for the usual signs of web shells and backdoors in PHP files and .htaccess files,
 * (2) ClamAV on top when it is installed and switched on (settings.sweep_clamav = 1). Findings are stored per scan; the owner can
 * quarantine a file (moved out of the web root by the privileged helper, restorable) or ignore the finding.
 * The scan only ever reads files; it runs as the panel's web user.
 */
class Sweep {

    private const MAX_FILE_BYTES = 2097152;      // files above 2 MB are not pattern-scanned
    private const MAX_FINDINGS   = 500;          // per scan
    private const EXTENSIONS     = ['php', 'phtml', 'php3', 'php4', 'php5', 'php7', 'phar', 'inc'];
    private const SKIP_DIRS      = ['.git', 'node_modules', '.quarantine'];

    /** @return array<int,array{0:string,1:string,2:string}> [rule id, severity, regex] applied to the file contents */
    private static function contentRules(): array {
        return [
            ['decode-and-run',   'high',   '/\b(eval|assert)\s*\(\s*(base64_decode|gzinflate|gzuncompress|gzdecode|str_rot13|rawurldecode)\s*\(/i'],
            ['request-to-exec',  'high',   '/\b(eval|assert|system|shell_exec|passthru|exec|popen|proc_open|pcntl_exec)\s*\(\s*\$_(GET|POST|REQUEST|COOKIE|SERVER)\b/i'],
            ['request-callback', 'high',   '/\b(call_user_func(_array)?|array_map|usort|array_filter|create_function)\s*\(\s*\$_(GET|POST|REQUEST|COOKIE)\b/i'],
            ['regex-eval',       'high',   '/preg_replace\s*\(\s*["\'][^"\']*\/[a-zA-Z]*e[a-zA-Z]*["\']\s*,/'],
            ['known-shell',      'high',   '/(c99shell|r57shell|b374k|FilesMan|IndoXploit|weevely)/i'],
            ['variable-function', 'medium', '/\$[A-Za-z_]\w*\s*\(\s*\$_(GET|POST|REQUEST|COOKIE)\s*\[/'],
            ['encoded-blob',     'medium', '/[A-Za-z0-9+\/=]{4000,}/'],
            ['chr-chain',        'medium', '/(chr\s*\(\s*\d+\s*\)\s*\.\s*){8,}/i'],
        ];
    }

    /** @return array<int,array{0:string,1:string,2:string}> rules applied to .htaccess files */
    private static function htaccessRules(): array {
        return [
            ['php-handler-on-media', 'medium', '/(AddHandler|AddType|SetHandler)\s+[^\n#]*php[^\n]*\.(jpe?g|png|gif|ico|txt|css|js|html?)\b/i'],
            ['auto-prepend',         'medium', '/php_(admin_)?value\s+auto_(prepend|append)_file/i'],
        ];
    }

    public static function clamAvailable(): bool {
        return is_executable('/usr/bin/clamscan') || is_executable('/usr/local/bin/clamscan');
    }

    /** True when the ClamAV background daemon is up (much faster than starting clamscan for every scan). */
    public static function clamdActive(): bool {
        return is_executable('/usr/bin/clamdscan') && (@filetype('/run/clamav/clamd.ctl') === 'socket' || @filetype('/var/run/clamav/clamd.ctl') === 'socket');
    }

    /** May this panel user use Sweep? Their own switch, and their reseller's switch when they have one. */
    public static function allowedFor(int $userId): bool {
        $u = DB::getInstance()->fetchOne("SELECT u.sweep_enabled AS own, r.sweep_enabled AS res FROM users u LEFT JOIN users r ON r.id = u.reseller_id WHERE u.id = ?", [$userId]);
        if (!$u) return false;
        return (int)$u['own'] === 1 && ($u['res'] === null || (int)$u['res'] === 1);
    }

    public static function accountAllowed(int $accountId): bool {
        $uid = (int)(DB::getInstance()->fetchOne("SELECT user_id FROM accounts WHERE id = ?", [$accountId])['user_id'] ?? 0);
        return $uid > 0 && self::allowedFor($uid);
    }

    /** Switches for the people the caller manages: admin sees resellers and all end users, a reseller sees their own end users. */
    public static function access(?int $resellerId = null): array {
        $db = DB::getInstance();
        if ($resellerId) {
            return ['resellers' => [], 'users' => $db->fetchAll("SELECT id, username, email, sweep_enabled AS enabled FROM users WHERE role = 'user' AND reseller_id = ? ORDER BY username", [$resellerId])];
        }
        return [
            'resellers' => $db->fetchAll("SELECT id, username, email, sweep_enabled AS enabled FROM users WHERE role = 'reseller' ORDER BY username"),
            'users'     => $db->fetchAll("SELECT u.id, u.username, u.email, u.reseller_id, u.sweep_enabled AS enabled, r.sweep_enabled AS reseller_enabled FROM users u LEFT JOIN users r ON r.id = u.reseller_id WHERE u.role = 'user' ORDER BY u.username"),
        ];
    }

    private static function clamEnabled(): bool {
        $v = DB::getInstance()->fetchOne("SELECT value FROM settings WHERE key = 'sweep_clamav'")['value'] ?? '0';
        return $v === '1' && self::clamAvailable();
    }

    /** Scan one account and store the result. @return array{run_id:int,files:int,findings:int} */
    public static function scanAccount(int $accountId): array {
        $db   = DB::getInstance();
        $acct = $db->fetchOne("SELECT id, username, home_dir FROM accounts WHERE id = ?", [$accountId]);
        if (!$acct) throw new RuntimeException('Account not found');
        if (!self::accountAllowed($accountId)) throw new RuntimeException('Malware scanning is switched off for this account');
        $root = '/home/' . $acct['username'] . '/public_html';

        $engine = self::clamEnabled() ? 'patterns+clamav' : 'patterns';
        $runId  = (int)$db->insert("INSERT INTO sweep_runs (account_id, engine, status) VALUES (?,?,'running')", [$accountId, $engine]);
        $files = 0; $found = 0; $note = '';
        try {
            if (!is_dir($root)) throw new RuntimeException('no public_html folder');
            $prefix = '/home/' . $acct['username'] . '/';
            $add = function (string $path, string $rule, string $sev, string $snippet) use ($db, $runId, $accountId, $prefix, &$found) {
                if ($found >= self::MAX_FINDINGS) return;
                $rel = str_starts_with($path, $prefix) ? substr($path, strlen($prefix)) : $path;
                // a finding the owner already marked as harmless stays quiet
                if ($db->fetchOne("SELECT 1 AS x FROM sweep_findings WHERE account_id = ? AND path = ? AND rule = ? AND status = 'ignored' LIMIT 1", [$accountId, $rel, $rule])) return;
                $db->execute("INSERT INTO sweep_findings (run_id, account_id, path, rule, severity, snippet) VALUES (?,?,?,?,?,?)",
                    [$runId, $accountId, $rel, $rule, $sev, mb_substr(preg_replace('/\s+/', ' ', $snippet), 0, 160)]);
                $found++;
            };

            $it = new RecursiveIteratorIterator(new RecursiveCallbackFilterIterator(
                new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
                function (SplFileInfo $f) { return !$f->isLink() && !($f->isDir() && in_array($f->getFilename(), self::SKIP_DIRS, true)); }
            ));
            foreach ($it as $f) {
                if (!$f->isFile()) continue;
                $name = $f->getFilename();
                $ext  = strtolower($f->getExtension());
                $isHt = $name === '.htaccess';
                $isPhp = in_array($ext, self::EXTENSIONS, true);
                if (!$isPhp && !$isHt) continue;
                $files++;
                $path = $f->getPathname();

                // place-based signals
                if ($isPhp && str_contains($path, '/uploads/')) $add($path, 'script-in-uploads', 'medium', 'PHP file inside an uploads folder');
                if ($isPhp && $name[0] === '.') $add($path, 'hidden-script', 'low', 'hidden PHP file');

                if ($f->getSize() > self::MAX_FILE_BYTES || $f->getSize() === 0) continue;
                $data = @file_get_contents($path);
                if ($data === false) continue;
                $hits = 0;
                foreach (($isHt ? self::htaccessRules() : self::contentRules()) as [$rule, $sev, $re]) {
                    if (preg_match($re, $data, $m, PREG_OFFSET_CAPTURE)) {
                        $add($path, $rule, $sev, substr($data, max(0, $m[0][1] - 20), 120));
                        if (++$hits >= 3) break;
                    }
                }
                if ($found >= self::MAX_FINDINGS) { $note = 'stopped at ' . self::MAX_FINDINGS . ' findings'; break; }
            }

            if ($engine === 'patterns+clamav') {
                $cmd = self::clamdActive()
                    ? 'timeout 900 /usr/bin/clamdscan --fdpass --infected --no-summary ' . escapeshellarg($root) . ' 2>&1'
                    : 'timeout 900 ' . (is_executable('/usr/bin/clamscan') ? '/usr/bin/clamscan' : '/usr/local/bin/clamscan')
                      . ' -r --infected --no-summary --max-filesize=20M ' . escapeshellarg($root) . ' 2>&1';
                foreach (explode("\n", (string)shell_exec($cmd)) as $line) {
                    if (preg_match('/^(.+): (.+) FOUND$/', trim($line), $mm)) $add($mm[1], 'clamav:' . $mm[2], 'high', 'ClamAV signature ' . $mm[2]);
                }
            }
            $db->execute("UPDATE sweep_runs SET status='done', finished_at=datetime('now'), files=?, findings=?, note=? WHERE id=?", [$files, $found, $note, $runId]);
        } catch (Throwable $e) {
            $db->execute("UPDATE sweep_runs SET status='failed', finished_at=datetime('now'), files=?, findings=?, note=? WHERE id=?", [$files, $found, substr($e->getMessage(), 0, 200), $runId]);
        }
        // a finding that is no longer there (cleaned up by hand) should not stay open from an older scan
        $db->execute("UPDATE sweep_findings SET status = 'resolved' WHERE account_id = ? AND status = 'open' AND run_id < ?", [$accountId, $runId]);
        return ['run_id' => $runId, 'files' => $files, 'findings' => $found];
    }

    /** @return array{run:?array,findings:array,clamav:bool} */
    public static function status(int $accountId): array {
        $db = DB::getInstance();
        $run = $db->fetchOne("SELECT * FROM sweep_runs WHERE account_id = ? ORDER BY id DESC LIMIT 1", [$accountId]);
        if ($run && $run['status'] === 'running' && strtotime($run['started_at'] . ' UTC') < time() - 3600) {
            $db->execute("UPDATE sweep_runs SET status = 'failed', note = 'scan did not finish' WHERE id = ?", [$run['id']]);
            $run['status'] = 'failed';
        }
        $findings = $db->fetchAll("SELECT id, path, rule, severity, snippet, status, created_at FROM sweep_findings
                                   WHERE account_id = ? AND status IN ('open','quarantined')
                                   ORDER BY CASE severity WHEN 'high' THEN 0 WHEN 'medium' THEN 1 ELSE 2 END, id LIMIT 300", [$accountId]);
        return ['run' => $run ?: null, 'findings' => $findings, 'clamav' => self::clamAvailable(), 'enabled' => self::accountAllowed($accountId)];
    }

    /** Last scan and open-finding counts per account the caller may see. */
    public static function overview(?int $resellerId = null): array {
        $db = DB::getInstance();
        $sql = "SELECT a.id, a.username, a.domain, u.id AS user_id, u.sweep_enabled AS enabled,
                       (SELECT MAX(started_at) FROM sweep_runs r WHERE r.account_id = a.id AND r.status = 'done') AS last_scan,
                       (SELECT COUNT(*) FROM sweep_findings f WHERE f.account_id = a.id AND f.status = 'open') AS open_findings,
                       (SELECT COUNT(*) FROM sweep_findings f WHERE f.account_id = a.id AND f.status = 'open' AND f.severity = 'high') AS high_findings
                  FROM accounts a JOIN users u ON u.id = a.user_id WHERE a.status != 'terminated'"
             . ($resellerId ? " AND u.reseller_id = " . (int)$resellerId : '') . " ORDER BY high_findings DESC, open_findings DESC, a.username";
        return $db->fetchAll($sql);
    }
}
