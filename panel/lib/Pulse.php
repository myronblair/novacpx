<?php
// Copyright (c) 2026 TomTom Enterprises. Licensed under the MIT License (see LICENSE).
/**
 * Pulse: site uptime monitor.
 *
 * run() requests the home page of every active site over HTTPS (all sites in parallel, short timeouts), records the result in
 * pulse_checks and keeps the current state per site in pulse_state. A site counts as down after pulse_fail_count failed checks
 * in a row (default 2, so one dropped packet does not page anybody); the owner and the admin get one email when it goes down
 * and one when it recovers. Any answer below HTTP 500 counts as "up" (a login wall or a 404 still proves the server is there).
 */
class Pulse {

    private const BATCH = 25;

    /** @return array{checked:int,down:int} */
    public static function run(): array {
        $db = DB::getInstance();
        if (($db->fetchOne("SELECT value FROM settings WHERE key = 'pulse_enabled'")['value'] ?? '1') === '0') return ['checked' => 0, 'down' => 0];
        $needFails = max(1, (int)($db->fetchOne("SELECT value FROM settings WHERE key = 'pulse_fail_count'")['value'] ?? 2));

        $sites = [];
        $rows = $db->fetchAll(
            "SELECT d.domain, a.id AS account_id, a.username, u.email
               FROM domains d JOIN accounts a ON a.id = d.account_id JOIN users u ON u.id = a.user_id
              WHERE a.status = 'active' AND d.type IN ('main','addon','subdomain')
             UNION
             SELECT a.domain, a.id, a.username, u.email FROM accounts a JOIN users u ON u.id = a.user_id WHERE a.status = 'active' AND a.domain != ''");
        foreach ($rows as $r) $sites[strtolower($r['domain'])] = $r;

        $checked = 0; $down = 0;
        foreach (array_chunk($sites, self::BATCH, true) as $batch) {
            foreach (self::probe(array_keys($batch)) as $domain => $res) {
                $checked++;
                if (!self::record($batch[$domain], $res, $needFails)) $down++;
            }
        }
        // forget sites that no longer exist, keep the history short
        $db->execute("DELETE FROM pulse_state WHERE account_id NOT IN (SELECT id FROM accounts WHERE status = 'active')");
        if ((int)date('i') < 5) $db->execute("DELETE FROM pulse_checks WHERE checked_at < datetime('now','-30 day')");
        return ['checked' => $checked, 'down' => $down];
    }

    /** @param string[] $domains @return array<string,array{ok:bool,code:int,ms:int,error:string}> */
    private static function probe(array $domains): array {
        $mh = curl_multi_init(); $handles = [];
        foreach ($domains as $d) {
            $ch = curl_init('https://' . $d . '/');
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 3,
                CURLOPT_CONNECTTIMEOUT => 6, CURLOPT_TIMEOUT => 12, CURLOPT_USERAGENT => 'NovaCPX-Pulse/1.0',
                CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_WRITEFUNCTION => fn($c, $data) => -1,   // stop after the first chunk: only the status line matters
            ]);
            curl_multi_add_handle($mh, $ch);
            $handles[$d] = $ch;
        }
        do {
            $status = curl_multi_exec($mh, $running);
            if ($running) curl_multi_select($mh, 1.0);
        } while ($running && $status === CURLM_OK);

        $out = [];
        foreach ($handles as $d => $ch) {
            $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $err  = curl_error($ch);
            $ms   = (int)round(curl_getinfo($ch, CURLINFO_TOTAL_TIME) * 1000);
            // the write callback aborts the transfer on purpose, which curl reports as a write error: not a failure
            if ($code > 0 && stripos($err, 'write') !== false) $err = '';
            $out[$d] = ['ok' => $code >= 200 && $code < 500 && $err === '', 'code' => $code, 'ms' => $ms, 'error' => $err];
            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);
        }
        curl_multi_close($mh);
        return $out;
    }

    /** Store one result and fire the down/recovered mail on state changes. Returns whether the site is considered up. */
    private static function record(array $site, array $res, int $needFails): bool {
        $db = DB::getInstance();
        $domain = strtolower($site['domain']);
        $db->execute("INSERT INTO pulse_checks (domain, ok, code, ms) VALUES (?,?,?,?)", [$domain, $res['ok'] ? 1 : 0, $res['code'], $res['ms']]);
        $st = $db->fetchOne("SELECT * FROM pulse_state WHERE domain = ?", [$domain]);
        if (!$st) {
            $db->execute("INSERT INTO pulse_state (domain, account_id, up, fails, since, last_code, last_ms, last_checked) VALUES (?,?,1,0,datetime('now'),?,?,datetime('now'))",
                [$domain, $site['account_id'], $res['code'], $res['ms']]);
            $st = ['up' => 1, 'fails' => 0, 'since' => date('Y-m-d H:i:s'), 'first_fail' => null];
        }
        $up = (int)$st['up']; $fails = (int)$st['fails'];
        $detail = $res['error'] !== '' ? $res['error'] : ($res['code'] ? 'HTTP ' . $res['code'] : 'no answer');

        if ($res['ok']) {
            if (!$up) {
                $since = date('Y-m-d H:i:s');
                $db->execute("UPDATE pulse_state SET up = 1, fails = 0, since = datetime('now'), first_fail = NULL WHERE domain = ?", [$domain]);
                require_once __DIR__ . '/Notifier.php';
                Notifier::siteStatus($domain, $site, false, '', $since);
            } elseif ($fails) {
                $db->execute("UPDATE pulse_state SET fails = 0, first_fail = NULL WHERE domain = ?", [$domain]);
            }
        } else {
            $fails++;
            $first = $st['first_fail'] ?? null;
            if ($fails === 1 || !$first) $first = date('Y-m-d H:i:s');
            if ($up && $fails >= $needFails) {
                $db->execute("UPDATE pulse_state SET up = 0, fails = ?, since = ?, first_fail = ? WHERE domain = ?", [$fails, $first, $first, $domain]);
                require_once __DIR__ . '/Notifier.php';
                Notifier::siteStatus($domain, $site, true, $detail, $first);
            } else {
                $db->execute("UPDATE pulse_state SET fails = ?, first_fail = ? WHERE domain = ?", [$fails, $first, $domain]);
            }
        }
        $db->execute("UPDATE pulse_state SET last_code = ?, last_ms = ?, last_checked = datetime('now'), account_id = ? WHERE domain = ?",
            [$res['code'], $res['ms'], $site['account_id'], $domain]);
        return $res['ok'] || ($up && $fails < $needFails);
    }

    /** Current state + 24h uptime per site for one account. */
    public static function forAccount(int $accountId): array {
        $db = DB::getInstance();
        $out = [];
        foreach ($db->fetchAll("SELECT * FROM pulse_state WHERE account_id = ? ORDER BY domain", [$accountId]) as $s) {
            $u = $db->fetchOne("SELECT COUNT(*) AS n, COALESCE(SUM(ok),0) AS good, COALESCE(AVG(ms),0) AS avg_ms FROM pulse_checks WHERE domain = ? AND checked_at >= datetime('now','-1 day')", [$s['domain']]);
            $recent = $db->fetchAll("SELECT ok, ms, checked_at FROM pulse_checks WHERE domain = ? ORDER BY id DESC LIMIT 48", [$s['domain']]);
            $out[] = [
                'domain' => $s['domain'], 'up' => (bool)$s['up'], 'since' => $s['since'], 'last_code' => $s['last_code'], 'last_ms' => $s['last_ms'],
                'last_checked' => $s['last_checked'],
                'uptime_24h' => (int)$u['n'] > 0 ? round($u['good'] / $u['n'] * 100, 2) : null,
                'avg_ms_24h' => (int)round((float)$u['avg_ms']),
                'recent' => array_reverse(array_map(fn($r) => ['ok' => (bool)$r['ok'], 'ms' => (int)$r['ms'], 'at' => $r['checked_at']], $recent)),
            ];
        }
        return $out;
    }

    /** Every monitored site the caller may see, worst first. */
    public static function overview(?int $resellerId = null): array {
        $db = DB::getInstance();
        $sql = "SELECT s.*, a.username FROM pulse_state s JOIN accounts a ON a.id = s.account_id JOIN users u ON u.id = a.user_id"
             . ($resellerId ? " WHERE u.reseller_id = " . (int)$resellerId : '') . " ORDER BY s.up ASC, s.domain";
        $out = [];
        foreach ($db->fetchAll($sql) as $s) {
            $u = $db->fetchOne("SELECT COUNT(*) AS n, COALESCE(SUM(ok),0) AS good FROM pulse_checks WHERE domain = ? AND checked_at >= datetime('now','-1 day')", [$s['domain']]);
            $out[] = ['domain' => $s['domain'], 'username' => $s['username'], 'up' => (bool)$s['up'], 'since' => $s['since'], 'last_code' => $s['last_code'],
                      'last_ms' => $s['last_ms'], 'last_checked' => $s['last_checked'],
                      'uptime_24h' => (int)$u['n'] > 0 ? round($u['good'] / $u['n'] * 100, 2) : null];
        }
        return $out;
    }
}
