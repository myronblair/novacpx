<?php
/**
 * Files endpoint — file manager (list, read, write, rename, delete, chmod, chown, upload, download, archive)
 * Strictly confined to account home directory via realpath check.
 * Admins with no linked hosting account get baseDir = '/' (full filesystem, bounded by the
 * OS permissions of the PHP-FPM pool user — see safe_path()).
 *
 * Destructive actions (write/delete/rename/chmod/chown/extract/mkdir/upload) targeting a
 * path under a system/security-critical zone (see is_dangerous_path()) are rejected unless
 * the request includes confirm_dangerous=true. This is enforced here, not just in the UI,
 * so it can't be bypassed by calling the API directly.
 */
$db   = DB::getInstance();
$body = json_decode(file_get_contents('php://input'), true) ?? [];

$user = Auth::getInstance()->user();
$acct = $db->fetchOne("SELECT * FROM accounts WHERE user_id = ?", [$user['uid']]);
if (!$acct && $user['role'] !== 'admin') Response::error("No hosting account found", 404);

$baseDir = $acct ? realpath($acct['home_dir']) : '/';
// Normalize so the prefix check below doesn't break when baseDir is '/' (rtrim would otherwise
// leave a doubled leading slash — '/' . '/' — that no real path can ever start with).
$baseDirPrefix = rtrim($baseDir, '/');

// For existing paths only — throws if path doesn't exist or escapes baseDir
function safe_path(string $base, string $rel): string {
    global $baseDirPrefix;
    $full = realpath($base . '/' . ltrim($rel, '/'));
    if ($full === false || !str_starts_with($full . '/', $baseDirPrefix . '/')) {
        throw new RuntimeException("Path outside account directory");
    }
    return $full;
}

// For paths that may not exist yet (write/mkdir) — validates parent dir
function safe_path_new(string $base, string $rel): string {
    global $baseDirPrefix;
    $candidate = $base . '/' . ltrim(str_replace(['../', '..\\'], '', $rel), '/');
    $parent    = realpath(dirname($candidate));
    if ($parent === false || !str_starts_with($parent . '/', $baseDirPrefix . '/')) {
        throw new RuntimeException("Path outside account directory");
    }
    return $parent . '/' . basename($candidate);
}

// Only meaningful for true root access (baseDir === '/'); hosting accounts are always
// confined under a home directory and never reach these zones.
function is_dangerous_path(string $baseDir, string $fullPath): bool {
    if ($baseDir !== '/') return false;
    static $zones = ['/etc','/boot','/root','/sys','/proc','/dev','/run',
                      '/lib','/lib64','/usr/lib','/usr/lib64',
                      '/bin','/sbin','/usr/bin','/usr/sbin','/var/lib','/snap'];
    foreach ($zones as $z) {
        if ($fullPath === $z || str_starts_with($fullPath, $z . '/')) return true;
    }
    return false;
}

function require_dangerous_confirm(string $baseDir, string $path, array $body): void {
    if (is_dangerous_path($baseDir, $path) && !($body['confirm_dangerous'] ?? $_POST['confirm_dangerous'] ?? false)) {
        Response::error(
            "This path is in a system/security-critical location. Editing, deleting, or changing " .
            "permissions here can break services or compromise server security. Confirmation required.",
            409,
            ['dangerous_path' => true]
        );
    }
}

function fmt_size(int $bytes): string {
    if ($bytes >= 1073741824) return round($bytes/1073741824, 1) . 'GB';
    if ($bytes >= 1048576)    return round($bytes/1048576, 1)    . 'MB';
    if ($bytes >= 1024)       return round($bytes/1024, 1)       . 'KB';
    return $bytes . 'B';
}

match ($action) {
    'list' => (function() use ($baseDir, $body, $user) {
        $rel  = $body['path'] ?? $_GET['path'] ?? ($user['role'] === 'admin' ? '/' : '/public_html');
        $path = safe_path($baseDir, $rel);
        if (!is_dir($path)) Response::error("Not a directory");
        if (!is_readable($path)) Response::error("Permission denied reading this directory");

        $hasPosix = function_exists('posix_getpwuid');
        $items = [];
        foreach (new DirectoryIterator($path) as $f) {
            if ($f->isDot()) continue;
            $uid = fileowner($f->getPathname());
            $gid = filegroup($f->getPathname());
            $itemPath = (($p = substr($path . '/' . $f->getFilename(), strlen(rtrim($baseDir, '/')))) === '' ? '/' : $p);
            $items[] = [
                'name'      => $f->getFilename(),
                'type'      => $f->isDir() ? 'dir' : 'file',
                'link'      => $f->isLink(),
                'size'      => $f->isFile() ? fmt_size($f->getSize()) : null,
                'size_raw'  => $f->isFile() ? $f->getSize() : 0,
                'perms'     => substr(sprintf('%o', $f->getPerms()), -4),
                'owner'     => $hasPosix ? (@posix_getpwuid($uid)['name'] ?? (string)$uid) : (string)$uid,
                'group'     => $hasPosix ? (@posix_getgrgid($gid)['name'] ?? (string)$gid) : (string)$gid,
                'modified'  => date('Y-m-d H:i', $f->getMTime()),
                'path'      => $itemPath,
                'writable'  => $f->isWritable(),
                'dangerous' => is_dangerous_path($baseDir, $path . '/' . $f->getFilename()),
            ];
        }
        usort($items, fn($a,$b) => ($a['type'] === 'dir' ? -1 : 1) - ($b['type'] === 'dir' ? -1 : 1) ?: strcmp($a['name'], $b['name']));
        Response::success(['path' => $rel, 'items' => $items, 'base_writable' => is_writable($path)]);
    })(),

    'read' => (function() use ($baseDir, $body) {
        $path = safe_path($baseDir, $body['path'] ?? $_GET['path'] ?? '');
        if (!is_file($path)) Response::error("Not a file");
        if (!is_readable($path)) Response::error("Permission denied reading this file");
        if (filesize($path) > 2 * 1024 * 1024) Response::error("File too large to edit (>2MB) — use Download instead");
        Response::success(['content' => file_get_contents($path), 'path' => $body['path'] ?? '']);
    })(),

    'download' => (function() use ($baseDir, $body) {
        $path = safe_path($baseDir, $body['path'] ?? $_GET['path'] ?? '');
        if (!is_file($path)) Response::error("Not a file");
        if (!is_readable($path)) Response::error("Permission denied reading this file");
        audit('files.download', $body['path'] ?? $_GET['path'] ?? '');
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . basename($path) . '"');
        header('Content-Length: ' . filesize($path));
        header('X-Content-Type-Options: nosniff');
        readfile($path);
        exit;
    })(),

    'write' => (function() use ($baseDir, $body) {
        $rel     = $body['path'] ?? '';
        $content = $body['content'] ?? '';
        // Use safe_path for existing files, safe_path_new for new ones
        try {
            $path = safe_path($baseDir, $rel);
        } catch (RuntimeException) {
            $path = safe_path_new($baseDir, $rel);
        }
        require_dangerous_confirm($baseDir, $path, $body);
        if (file_exists($path) && !is_writable($path)) Response::error("Permission denied writing this file");
        // PHP syntax check for .php files
        if (str_ends_with($path, '.php')) {
            $tmp = tempnam(sys_get_temp_dir(), 'ncpx_');
            file_put_contents($tmp, $content);
            $result = shell_exec("php8.3 -l " . escapeshellarg($tmp) . " 2>&1");
            unlink($tmp);
            if (!str_contains($result ?? '', 'No syntax errors')) Response::error("PHP syntax error: $result");
        }
        if (file_put_contents($path, $content) === false) Response::error("Write failed (permission denied)");
        audit('files.write', $rel);
        Response::success(null, 'File saved');
    })(),

    'mkdir' => (function() use ($baseDir, $body) {
        $path = safe_path_new($baseDir, $body['path'] ?? '');
        require_dangerous_confirm($baseDir, $path, $body);
        if (is_dir($path)) Response::error("Directory already exists");
        if (!mkdir($path, 0755, true)) Response::error("Could not create directory (permission denied)");
        audit('files.mkdir', $body['path'] ?? '');
        Response::success(null, 'Directory created');
    })(),

    'rename' => (function() use ($baseDir, $body) {
        $from = safe_path($baseDir, $body['from'] ?? '');
        $to   = safe_path_new($baseDir, $body['to'] ?? '');
        require_dangerous_confirm($baseDir, $from, $body);
        require_dangerous_confirm($baseDir, $to, $body);
        if (file_exists($to)) Response::error("Destination already exists");
        if (!rename($from, $to)) Response::error("Rename failed (permission denied)");
        audit('files.rename', ($body['from'] ?? '') . ' -> ' . ($body['to'] ?? ''));
        Response::success(null, 'Renamed');
    })(),

    'delete' => (function() use ($baseDir, $body) {
        $path = safe_path($baseDir, $body['path'] ?? '');
        // Prevent deleting the root directory itself or its direct children
        // (for accounts: public_html/logs/tmp; for admin baseDir='/': /etc, /home, /var, /root, etc.)
        if ($path === $baseDir || dirname($path) === $baseDir) Response::error("Cannot delete a top-level directory");
        require_dangerous_confirm($baseDir, $path, $body);
        if (is_dir($path)) {
            shell_exec("rm -rf " . escapeshellarg($path) . " 2>&1");
        } else {
            if (!unlink($path)) Response::error("Delete failed (permission denied)");
        }
        audit('files.delete', $body['path'] ?? '');
        Response::success(null, 'Deleted');
    })(),

    'chmod' => (function() use ($baseDir, $body) {
        $path  = safe_path($baseDir, $body['path'] ?? '');
        require_dangerous_confirm($baseDir, $path, $body);
        $perms = octdec((string)($body['perms'] ?? '755'));
        // Clamp to sane range: no setuid/setgid/sticky, no world-write on dirs
        $perms = $perms & 0777;
        if (!chmod($path, $perms)) Response::error("chmod failed (permission denied)");
        audit('files.chmod', $body['path'] ?? '', ['perms' => decoct($perms)]);
        Response::success(null, 'Permissions updated');
    })(),

    'chown' => (function() use ($baseDir, $body, $user) {
        if ($user['role'] !== 'admin') Response::error("Forbidden", 403);
        if (!function_exists('posix_getpwnam')) Response::error("posix extension not available on this server");
        $path  = safe_path($baseDir, $body['path'] ?? '');
        require_dangerous_confirm($baseDir, $path, $body);
        $owner = trim((string)($body['owner'] ?? ''));
        $group = trim((string)($body['group'] ?? ''));
        if ($owner !== '') {
            $pw = @posix_getpwnam($owner);
            if (!$pw) Response::error("Unknown user: $owner");
            if (!@chown($path, $pw['uid'])) Response::error("chown failed — PHP-FPM ({$user['username']}) does not have OS permission to change ownership to '$owner'");
        }
        if ($group !== '') {
            $gr = @posix_getgrnam($group);
            if (!$gr) Response::error("Unknown group: $group");
            if (!@chgrp($path, $gr['gid'])) Response::error("chgrp failed — PHP-FPM does not have OS permission to change group to '$group'");
        }
        audit('files.chown', $body['path'] ?? '', ['owner' => $owner, 'group' => $group]);
        Response::success(null, 'Ownership updated');
    })(),

    'upload' => (function() use ($baseDir, $body) {
        $dir = safe_path($baseDir, $body['path'] ?? $_POST['path'] ?? $_GET['path'] ?? '/public_html');
        require_dangerous_confirm($baseDir, $dir, $body);
        if (!is_dir($dir)) Response::error("Target directory not found");
        if (!is_writable($dir)) Response::error("Permission denied writing to this directory");
        if (empty($_FILES['file'])) Response::error("No file uploaded");
        $filename = basename($_FILES['file']['name']);
        if ($filename === '' || $filename === '.') Response::error("Invalid filename");
        $dest = $dir . '/' . $filename;
        if (!str_starts_with($dest, rtrim($baseDir, '/') . '/')) Response::error("Invalid destination");
        if (!move_uploaded_file($_FILES['file']['tmp_name'], $dest)) Response::error("Upload failed (permission denied)");
        audit('files.upload', ($body['path'] ?? '') . '/' . $filename);
        Response::success(['name' => $filename], 'File uploaded');
    })(),

    'compress' => (function() use ($baseDir, $body) {
        $paths = array_map(fn($p) => safe_path($baseDir, $p), (array)($body['paths'] ?? []));
        $dest  = safe_path_new($baseDir, $body['dest'] ?? 'archive.zip');
        require_dangerous_confirm($baseDir, $dest, $body);
        $files = implode(' ', array_map('escapeshellarg', $paths));
        shell_exec("cd " . escapeshellarg($baseDir) . " && zip -r " . escapeshellarg($dest) . " $files 2>&1");
        audit('files.compress', $body['dest'] ?? '', ['paths' => $body['paths'] ?? []]);
        Response::success(null, 'Archive created');
    })(),

    'extract' => (function() use ($baseDir, $body) {
        $file = safe_path($baseDir, $body['path'] ?? '');
        $dest = safe_path($baseDir, $body['dest'] ?? dirname($body['path'] ?? '/'));
        require_dangerous_confirm($baseDir, $dest, $body);
        $ext  = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        match($ext) {
            'zip'  => shell_exec("unzip -o " . escapeshellarg($file) . " -d " . escapeshellarg($dest) . " 2>&1"),
            'gz','tgz','tar' => shell_exec("tar xf " . escapeshellarg($file) . " -C " . escapeshellarg($dest) . " 2>&1"),
            default => Response::error("Unsupported archive type"),
        };
        audit('files.extract', $body['path'] ?? '');
        Response::success(null, 'Extracted');
    })(),

    default => Response::error("Unknown files action: $action", 404),
};
