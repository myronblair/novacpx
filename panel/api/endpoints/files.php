<?php
/**
 * Files endpoint — file manager (list, read, write, rename, delete, chmod, upload, archive)
 * Strictly confined to account home directory via realpath check
 */
$db   = DB::getInstance();
$body = json_decode(file_get_contents('php://input'), true) ?? [];

$user = Auth::getInstance()->user();
$acct = $db->fetchOne("SELECT * FROM accounts WHERE user_id = ?", [$user['uid']]);
if (!$acct && $user['role'] !== 'admin') Response::error("No hosting account found", 404);

$baseDir = $acct ? realpath($acct['home_dir']) : '/';

function safe_path(string $base, string $rel): string {
    $full = realpath($base . '/' . ltrim($rel, '/'));
    if (!$full || !str_starts_with($full, $base)) {
        throw new RuntimeException("Path outside account directory");
    }
    return $full;
}

function fmt_size(int $bytes): string {
    if ($bytes >= 1073741824) return round($bytes/1073741824, 1) . 'GB';
    if ($bytes >= 1048576)    return round($bytes/1048576, 1)    . 'MB';
    if ($bytes >= 1024)       return round($bytes/1024, 1)       . 'KB';
    return $bytes . 'B';
}

match ($action) {
    'list' => (function() use ($baseDir, $body) {
        $rel  = $body['path'] ?? $_GET['path'] ?? '/public_html';
        $path = safe_path($baseDir, $rel);
        if (!is_dir($path)) Response::error("Not a directory");

        $items = [];
        foreach (new DirectoryIterator($path) as $f) {
            if ($f->isDot()) continue;
            $items[] = [
                'name'     => $f->getFilename(),
                'type'     => $f->isDir() ? 'dir' : 'file',
                'size'     => $f->isFile() ? fmt_size($f->getSize()) : null,
                'size_raw' => $f->isFile() ? $f->getSize() : 0,
                'perms'    => substr(sprintf('%o', $f->getPerms()), -4),
                'modified' => date('Y-m-d H:i', $f->getMTime()),
                'path'     => str_replace($baseDir, '', $path . '/' . $f->getFilename()),
            ];
        }
        usort($items, fn($a,$b) => ($a['type'] === 'dir' ? -1 : 1) - ($b['type'] === 'dir' ? -1 : 1) ?: strcmp($a['name'], $b['name']));
        Response::success(['path' => $rel, 'items' => $items]);
    })(),

    'read' => (function() use ($baseDir, $body) {
        $path = safe_path($baseDir, $body['path'] ?? $_GET['path'] ?? '');
        if (!is_file($path)) Response::error("Not a file");
        if (filesize($path) > 2 * 1024 * 1024) Response::error("File too large to edit (>2MB)");
        Response::success(['content' => file_get_contents($path), 'path' => $body['path'] ?? '']);
    })(),

    'write' => (function() use ($baseDir, $body) {
        $path    = safe_path($baseDir, $body['path'] ?? '');
        $content = $body['content'] ?? '';
        // PHP syntax check for .php files
        if (str_ends_with($path, '.php')) {
            $tmp = tempnam(sys_get_temp_dir(), 'ncpx_');
            file_put_contents($tmp, $content);
            $result = shell_exec("php8.3 -l " . escapeshellarg($tmp) . " 2>&1");
            unlink($tmp);
            if (!str_contains($result, 'No syntax errors')) Response::error("PHP syntax error: $result");
        }
        file_put_contents($path, $content);
        audit('files.write', $body['path'] ?? '');
        Response::success(null, 'File saved');
    })(),

    'mkdir' => (function() use ($baseDir, $body) {
        $path = $baseDir . '/' . ltrim($body['path'] ?? '', '/');
        // Don't use safe_path since dir may not exist yet
        if (!str_starts_with(realpath(dirname($path)) ?: '', $baseDir)) Response::error("Invalid path");
        if (!mkdir($path, 0755, true)) Response::error("Could not create directory");
        Response::success(null, 'Directory created');
    })(),

    'rename' => (function() use ($baseDir, $body) {
        $from = safe_path($baseDir, $body['from'] ?? '');
        $to   = $baseDir . '/' . ltrim($body['to'] ?? '', '/');
        if (!str_starts_with(realpath(dirname($to)) ?: $baseDir, $baseDir)) Response::error("Invalid destination");
        rename($from, $to);
        Response::success(null, 'Renamed');
    })(),

    'delete' => (function() use ($baseDir, $body) {
        $path = safe_path($baseDir, $body['path'] ?? '');
        if (is_dir($path)) {
            shell_exec("rm -rf " . escapeshellarg($path));
        } else {
            unlink($path);
        }
        audit('files.delete', $body['path'] ?? '');
        Response::success(null, 'Deleted');
    })(),

    'chmod' => (function() use ($baseDir, $body) {
        $path  = safe_path($baseDir, $body['path'] ?? '');
        $perms = octdec((string)($body['perms'] ?? '755'));
        chmod($path, $perms);
        Response::success(null, 'Permissions updated');
    })(),

    'upload' => (function() use ($baseDir, $body) {
        $dir = safe_path($baseDir, $body['path'] ?? '/public_html');
        if (empty($_FILES['file'])) Response::error("No file uploaded");
        $dest = $dir . '/' . basename($_FILES['file']['name']);
        if (!str_starts_with(realpath(dirname($dest)) ?: $baseDir, $baseDir)) Response::error("Invalid destination");
        move_uploaded_file($_FILES['file']['tmp_name'], $dest);
        Response::success(['name' => basename($dest)], 'File uploaded');
    })(),

    'compress' => (function() use ($baseDir, $body) {
        $paths  = array_map(fn($p) => safe_path($baseDir, $p), (array)($body['paths'] ?? []));
        $dest   = $baseDir . '/' . ltrim($body['dest'] ?? 'archive.zip', '/');
        $files  = implode(' ', array_map('escapeshellarg', $paths));
        shell_exec("cd " . escapeshellarg($baseDir) . " && zip -r " . escapeshellarg($dest) . " $files 2>/dev/null");
        Response::success(null, 'Archive created');
    })(),

    'extract' => (function() use ($baseDir, $body) {
        $file = safe_path($baseDir, $body['path'] ?? '');
        $dest = safe_path($baseDir, $body['dest'] ?? dirname($body['path'] ?? '/'));
        $ext  = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        match($ext) {
            'zip'  => shell_exec("unzip -o " . escapeshellarg($file) . " -d " . escapeshellarg($dest)),
            'gz','tgz','tar' => shell_exec("tar xf " . escapeshellarg($file) . " -C " . escapeshellarg($dest)),
            default => Response::error("Unsupported archive type"),
        };
        Response::success(null, 'Extracted');
    })(),

    default => Response::error("Unknown files action: $action", 404),
};
