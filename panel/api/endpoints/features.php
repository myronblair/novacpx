<?php
/**
 * NovaCPX Feature Registry & Toggle API
 * Features can be enabled/disabled/installed on the fly
 * Covers: Docker, IPs, WordPress, Node.js, Python, Ruby, Git deploy,
 *         Cloudflare, Redis, Memcached, Varnish, ModSecurity, ImunifyAV,
 *         Webmail, phpMyAdmin, phpPgAdmin, and more
 */

Auth::getInstance()->require('admin');
$db   = DB::getInstance();
$body = json_decode(file_get_contents('php://input'), true) ?? [];

match ($action) {

    'list' => (function() use ($db) {
        $rows = $db->fetchAll("SELECT * FROM features ORDER BY category, name");
        $grouped = [];
        foreach ($rows as $r) {
            $grouped[$r['category']][] = $r;
        }
        Response::success($grouped);
    })(),

    'toggle' => (function() use ($db, $body) {
        $id     = (int)($body['id'] ?? 0);
        $enable = (bool)($body['enable'] ?? false);
        $feat   = $db->fetchOne("SELECT * FROM features WHERE id = ?", [$id]);
        if (!$feat) Response::error("Feature not found");

        $db->execute("UPDATE features SET enabled = ?, updated_at = NOW() WHERE id = ?", [(int)$enable, $id]);
        audit('feature.' . ($enable ? 'enable' : 'disable'), $feat['slug']);

        // Run hook if defined
        if ($enable && $feat['install_cmd'] && !$feat['installed']) {
            Response::success(['action' => 'install_required', 'feature' => $feat]);
        }
        Response::success(null, 'Feature ' . ($enable ? 'enabled' : 'disabled'));
    })(),

    'install' => (function() use ($db, $body) {
        $id   = (int)($body['id'] ?? 0);
        $feat = $db->fetchOne("SELECT * FROM features WHERE id = ?", [$id]);
        if (!$feat) Response::error("Feature not found");
        if ($feat['installed']) Response::error("Already installed");
        if (!$feat['install_cmd']) Response::error("No install command defined");

        // Run install in background, return job ID
        $jobId = uniqid('install_');
        $logFile = "/var/log/novacpx/feature_{$jobId}.log";
        $cmd = "nohup bash -c " . escapeshellarg($feat['install_cmd']) . " > " . escapeshellarg($logFile) . " 2>&1 & echo $!";
        $pid = trim(shell_exec($cmd) ?: '');

        $db->execute("UPDATE features SET install_pid = ?, install_log = ?, updated_at = NOW() WHERE id = ?", [$pid, $logFile, $id]);
        audit('feature.install', $feat['slug']);
        Response::success(['job_id' => $jobId, 'pid' => $pid, 'log' => $logFile]);
    })(),

    'install-log' => (function() use ($db) {
        $id   = (int)($_GET['id'] ?? 0);
        $feat = $db->fetchOne("SELECT install_log, install_pid, installed FROM features WHERE id = ?", [$id]);
        if (!$feat) Response::error("Feature not found");

        $logContent = '';
        if ($feat['install_log'] && file_exists($feat['install_log'])) {
            $logContent = file_get_contents($feat['install_log']);
        }
        // Check if process finished
        $running = false;
        if ($feat['install_pid']) {
            $running = file_exists("/proc/{$feat['install_pid']}");
            if (!$running && !$feat['installed']) {
                $db->execute("UPDATE features SET installed = 1, enabled = 1, updated_at = NOW() WHERE id = ?", [$id]);
            }
        }
        Response::success(['log' => $logContent, 'running' => $running, 'installed' => (bool)$feat['installed']]);
    })(),

    default => Response::error("Unknown features action: $action", 404),
};
