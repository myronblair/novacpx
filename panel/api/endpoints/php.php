<?php
$db   = DB::getInstance();
$body = json_decode(file_get_contents('php://input'), true) ?? [];
require_once NOVACPX_LIB . '/PHPManager.php';

$user      = Auth::getInstance()->user();
$accountId = $user['role'] === 'user'
    ? (int)($db->fetchOne("SELECT id FROM accounts WHERE user_id = ?", [$user['uid']])['id'] ?? 0)
    : (int)($body['account_id'] ?? $_GET['account_id'] ?? 0);

match ($action) {
    'config' => (function() use ($db, $accountId) {
        $cfg  = $db->fetchOne("SELECT * FROM php_configs WHERE account_id = ?", [$accountId]);
        $acct = $db->fetchOne("SELECT php_version FROM accounts WHERE id = ?", [$accountId]);
        Response::success([
            'php_version'        => $acct['php_version'] ?? PHP_DEFAULT,
            'memory_limit'       => $cfg['memory_limit']        ?? '256M',
            'max_execution_time' => $cfg['max_execution_time']  ?? 30,
            'upload_max_filesize'=> $cfg['upload_max_filesize'] ?? '64M',
            'post_max_size'      => $cfg['post_max_size']       ?? '64M',
            'display_errors'     => (bool)($cfg['display_errors'] ?? false),
            'extensions'         => json_decode($cfg['extensions'] ?? '[]', true) ?: [],
        ]);
    })(),

    'versions' => (function() {
        $versions = [];
        foreach (['7.4','8.1','8.2','8.3'] as $v) {
            $installed  = file_exists("/usr/bin/php{$v}");
            $fpmActive  = $installed ? trim(shell_exec("systemctl is-active php{$v}-fpm 2>/dev/null") ?: '') : '';
            $versions[] = [
                'version'    => $v,
                'installed'  => $installed,
                'fpm_active' => $fpmActive === 'active',
                'is_default' => $v === PHP_DEFAULT,
            ];
        }
        // Detect which version the panel itself runs on (highest installed)
        $panelVer = PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
        Response::success(['versions' => $versions, 'panel_php' => $panelVer]);
    })(),

    'install-version' => (function() use ($body) {
        Auth::getInstance()->require('admin');
        $ver = $body['version'] ?? '';
        if (!preg_match('/^[78]\.\d$/', $ver)) Response::error("Invalid version");
        $pkgs = "php{$ver} php{$ver}-fpm php{$ver}-cli php{$ver}-common php{$ver}-mysql php{$ver}-curl php{$ver}-gd php{$ver}-xml php{$ver}-mbstring php{$ver}-zip php{$ver}-intl php{$ver}-bcmath php{$ver}-soap php{$ver}-opcache";
        $out  = shell_exec("apt-get install -y $pkgs 2>&1");
        shell_exec("systemctl enable php{$ver}-fpm && systemctl start php{$ver}-fpm 2>/dev/null");
        audit('php.install-version', $ver);
        Response::success(['output' => substr($out ?: '', -1000)], "PHP $ver installed");
    })(),

    'remove-version' => (function() use ($body) {
        Auth::getInstance()->require('admin');
        $ver = $body['version'] ?? '';
        if (!preg_match('/^[78]\.\d$/', $ver)) Response::error("Invalid version");
        if ($ver === PHP_DEFAULT) Response::error("Cannot remove the panel's default PHP version");
        shell_exec("systemctl stop php{$ver}-fpm 2>/dev/null || true");
        $out = shell_exec("apt-get remove -y php{$ver}* 2>&1");
        audit('php.remove-version', $ver);
        Response::success(['output' => substr($out ?: '', -1000)], "PHP $ver removed");
    })(),

    'version-extensions' => (function() use ($body) {
        Auth::getInstance()->require('admin');
        $ver = $body['version'] ?? $_GET['version'] ?? '';
        if (!preg_match('/^[78]\.\d$/', $ver)) Response::error("Invalid version");
        if (!file_exists("/usr/bin/php{$ver}")) Response::error("PHP $ver not installed");
        $out = shell_exec("php{$ver} -m 2>/dev/null") ?: '';
        $exts = array_values(array_filter(explode("\n", trim($out)), fn($l) => $l && !str_starts_with($l, '[')));

        // Available apt packages for this version (common ones)
        $common = ['bcmath','bz2','curl','gd','gmp','igbinary','imagick','imap','intl','ldap','mbstring',
                   'memcached','mongodb','msgpack','mysql','odbc','opcache','pdo','pdo-mysql','pdo-pgsql',
                   'pgsql','redis','soap','sqlite3','tidy','tokenizer','uuid','xml','xmlrpc','xsl','zip'];
        $available = array_map(fn($e) => "php{$ver}-{$e}", $common);
        Response::success(['version' => $ver, 'installed' => $exts, 'available' => $available]);
    })(),

    'install-extension' => (function() use ($body) {
        Auth::getInstance()->require('admin');
        $ver = $body['version'] ?? '';
        $ext = preg_replace('/[^a-z0-9\-]/', '', strtolower($body['extension'] ?? ''));
        if (!preg_match('/^[78]\.\d$/', $ver) || !$ext) Response::error("Invalid input");

        header('Content-Type: text/event-stream');
        header('Cache-Control: no-cache');
        header('X-Accel-Buffering: no');
        while (ob_get_level()) ob_end_clean();
        $sse = function(string $line) { echo 'data: ' . json_encode(['line' => $line]) . "\n\n"; flush(); };
        $run = function(string $cmd) use ($sse): int {
            $proc = proc_open($cmd, [1 => ['pipe','w'], 2 => ['pipe','w']], $pipes);
            if (!$proc) { $sse("  [failed to start]\n"); return 1; }
            while (!feof($pipes[1])) { $l = fgets($pipes[1]); if ($l !== false && $l !== '') $sse($l); }
            while (!feof($pipes[2])) { $l = fgets($pipes[2]); if ($l !== false && $l !== '') $sse("  " . $l); }
            fclose($pipes[1]); fclose($pipes[2]);
            return proc_close($proc);
        };

        $pkg = "php{$ver}-{$ext}";
        $sse("▶ Installing {$pkg}…\n");
        $rc = $run("sudo apt-get install -y $pkg 2>&1");

        if ($rc !== 0) {
            // Try PECL fallback
            $sse("\n  apt package not found — trying PECL…\n");
            $rc2 = $run("sudo php{$ver} /usr/bin/pecl install {$ext} 2>&1");
            if ($rc2 !== 0) {
                $sse("  ✗ Could not install {$ext} via apt or PECL\n");
                echo 'data: ' . json_encode(['done' => true, 'success' => false]) . "\n\n"; flush(); exit;
            }
        }

        $sse("\n▶ Reloading PHP {$ver} FPM…\n");
        $run("sudo systemctl reload php{$ver}-fpm 2>&1 || sudo systemctl restart php{$ver}-fpm 2>&1");
        $sse("  ✓ php{$ver}-{$ext} installed\n");
        audit('php.install-extension', "$ver/$ext");
        echo 'data: ' . json_encode(['done' => true, 'success' => true]) . "\n\n"; flush(); exit;
    })(),

    'remove-extension' => (function() use ($body) {
        Auth::getInstance()->require('admin');
        $ver = $body['version'] ?? '';
        $ext = preg_replace('/[^a-z0-9\-]/', '', strtolower($body['extension'] ?? ''));
        if (!preg_match('/^[78]\.\d$/', $ver) || !$ext) Response::error("Invalid input");

        header('Content-Type: text/event-stream');
        header('Cache-Control: no-cache');
        header('X-Accel-Buffering: no');
        while (ob_get_level()) ob_end_clean();
        $sse = function(string $line) { echo 'data: ' . json_encode(['line' => $line]) . "\n\n"; flush(); };
        $run = function(string $cmd) use ($sse): int {
            $proc = proc_open($cmd, [1 => ['pipe','w'], 2 => ['pipe','w']], $pipes);
            if (!$proc) { $sse("  [failed to start]\n"); return 1; }
            while (!feof($pipes[1])) { $l = fgets($pipes[1]); if ($l !== false && $l !== '') $sse($l); }
            while (!feof($pipes[2])) { $l = fgets($pipes[2]); if ($l !== false && $l !== '') $sse("  " . $l); }
            fclose($pipes[1]); fclose($pipes[2]);
            return proc_close($proc);
        };

        $pkg = "php{$ver}-{$ext}";
        $sse("▶ Removing {$pkg}…\n");
        $run("sudo apt-get remove -y $pkg 2>&1");
        $sse("\n▶ Reloading PHP {$ver} FPM…\n");
        $run("sudo systemctl reload php{$ver}-fpm 2>&1 || sudo systemctl restart php{$ver}-fpm 2>&1");
        $sse("  ✓ php{$ver}-{$ext} removed\n");
        audit('php.remove-extension', "$ver/$ext");
        echo 'data: ' . json_encode(['done' => true, 'success' => true]) . "\n\n"; flush(); exit;
    })(),

    'fpm-action' => (function() use ($body) {
        Auth::getInstance()->require('admin');
        $ver = $body['version'] ?? '';
        $cmd = $body['command'] ?? 'restart';
        if (!preg_match('/^[78]\.\d$/', $ver)) Response::error("Invalid version");
        if (!in_array($cmd, ['start','stop','restart','reload'])) Response::error("Invalid command");
        shell_exec("systemctl $cmd php{$ver}-fpm 2>/dev/null");
        audit('php.fpm-action', "$cmd php{$ver}-fpm");
        Response::success(null, "php{$ver}-fpm {$cmd}ed");
    })(),

    'switch-version' => (function() use ($body, $accountId) {
        $ver = $body['version'] ?? '';
        if (!in_array($ver, ['7.4','8.1','8.2','8.3'])) Response::error("Invalid PHP version");
        PHPManager::switchVersion($accountId, $ver);
        audit('php.switch-version', "account:{$accountId} → php{$ver}");
        Response::success(null, "PHP version switched to $ver");
    })(),

    'update-config' => (function() use ($body, $accountId) {
        PHPManager::updateConfig($accountId, $body);
        audit('php.update-config', "account:$accountId");
        Response::success(null, 'PHP configuration updated');
    })(),

    'extensions' => (function() use ($db, $accountId) {
        $acct = $db->fetchOne("SELECT php_version FROM accounts WHERE id = ?", [$accountId]);
        $ver  = $acct['php_version'] ?? PHP_DEFAULT;
        Response::success(['version' => $ver, 'extensions' => PHPManager::listExtensions($ver)]);
    })(),

    default => Response::error("Unknown php action: $action", 404),
};
