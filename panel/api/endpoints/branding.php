<?php
/**
 * Branding endpoint — reseller white-label settings
 */
$db   = DB::getInstance();
$body = json_decode(file_get_contents('php://input'), true) ?? [];
$user = Auth::getInstance()->user();

// Resolve which reseller's branding we're working with
if ($user['role'] === 'admin') {
    $resellerId = (int)($body['reseller_id'] ?? $_GET['reseller_id'] ?? 0);
} elseif ($user['role'] === 'reseller') {
    $resellerId = $user['uid'];
} else {
    Response::error('Forbidden', 403);
}

match ($action) {

    'get' => (function() use ($db, $resellerId) {
        if (!$resellerId) Response::error('reseller_id required');
        $row = $db->fetchOne("SELECT * FROM reseller_branding WHERE user_id = ?", [$resellerId]);
        Response::success($row ?: ['user_id' => $resellerId]);
    })(),

    'save' => (function() use ($db, $body, $resellerId, $user) {
        if ($user['role'] !== 'admin' && $user['role'] !== 'reseller') Response::error('Forbidden', 403);
        if (!$resellerId) Response::error('reseller_id required');

        $allowed = ['panel_name','logo_url','favicon_url','primary_color','accent_color',
                    'support_email','support_url','hide_powered_by','custom_css'];
        $fields  = [];
        $vals    = [];
        foreach ($allowed as $k) {
            if (array_key_exists($k, $body)) {
                $fields[] = "`$k`";
                $vals[]   = $body[$k];
            }
        }
        if (!$fields) Response::error('No fields to update');

        // Validate colors
        foreach (['primary_color','accent_color'] as $c) {
            if (isset($body[$c]) && !preg_match('/^#[0-9a-fA-F]{3,6}$/', $body[$c])) {
                Response::error("Invalid color value for $c");
            }
        }

        $placeholders = implode(',', array_fill(0, count($fields), '?'));
        $setClauses   = implode(', ', array_map(fn($f) => "$f = ?", $fields));
        $db->execute(
            "INSERT INTO reseller_branding (user_id, " . implode(', ', $fields) . ")
             VALUES (?, $placeholders)
             ON DUPLICATE KEY UPDATE $setClauses",
            array_merge([$resellerId], $vals, $vals)
        );
        audit('branding.save', "reseller:$resellerId");
        Response::success(null, 'Branding saved');
    })(),

    'upload-logo' => (function() use ($resellerId, $user) {
        if ($user['role'] !== 'admin' && $user['role'] !== 'reseller') Response::error('Forbidden', 403);
        if (!$resellerId) Response::error('reseller_id required');

        $file = $_FILES['logo'] ?? null;
        if (!$file || $file['error'] !== UPLOAD_ERR_OK) Response::error('File upload failed');

        $allowed = ['image/png','image/jpeg','image/gif','image/svg+xml','image/webp'];
        $finfo   = new finfo(FILEINFO_MIME_TYPE);
        $mime    = $finfo->file($file['tmp_name']);
        if (!in_array($mime, $allowed)) Response::error('Invalid file type. Allowed: PNG, JPG, GIF, SVG, WebP');
        if ($file['size'] > 512 * 1024) Response::error('Logo must be under 512 KB');

        $ext  = ['image/png'=>'png','image/jpeg'=>'jpg','image/gif'=>'gif',
                 'image/svg+xml'=>'svg','image/webp'=>'webp'][$mime] ?? 'png';
        $dir  = '/srv/novacpx/public/uploads/branding/' . $resellerId;
        if (!is_dir($dir)) mkdir($dir, 0755, true);

        $path = "$dir/logo.$ext";
        // Remove old logo files
        foreach (glob("$dir/logo.*") as $old) @unlink($old);
        if (!move_uploaded_file($file['tmp_name'], $path)) Response::error('Failed to save logo');

        $url = "/uploads/branding/{$resellerId}/logo.$ext";
        DB::getInstance()->execute(
            "INSERT INTO reseller_branding (user_id, logo_url) VALUES (?,?)
             ON DUPLICATE KEY UPDATE logo_url = VALUES(logo_url)",
            [$resellerId, $url]
        );
        audit('branding.upload-logo', "reseller:$resellerId");
        Response::success(['url' => $url], 'Logo uploaded');
    })(),

    'delete-logo' => (function() use ($db, $resellerId, $user) {
        if ($user['role'] !== 'admin' && $user['role'] !== 'reseller') Response::error('Forbidden', 403);
        $dir = '/srv/novacpx/public/uploads/branding/' . $resellerId;
        foreach (glob("$dir/logo.*") ?: [] as $f) @unlink($f);
        $db->execute("UPDATE reseller_branding SET logo_url = NULL WHERE user_id = ?", [$resellerId]);
        Response::success(null, 'Logo removed');
    })(),

    'resellers' => (function() use ($db, $user) {
        Auth::getInstance()->require('admin');
        $rows = $db->fetchAll(
            "SELECT u.id, u.username, u.email, b.panel_name, b.logo_url, b.primary_color
             FROM users u LEFT JOIN reseller_branding b ON b.user_id = u.id
             WHERE u.role = 'reseller' ORDER BY u.username"
        );
        Response::success($rows);
    })(),

    default => Response::error("Unknown branding action: $action", 404),
};
