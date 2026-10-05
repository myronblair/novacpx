<?php
// Copyright (c) 2026 TomTom Enterprises. Licensed under the MIT License (see LICENSE).
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

        $allowed = ['primary_color','accent_color',
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

    // The panel name and logo are fixed in the code and cannot be changed.
    'upload-logo' => Response::error('The panel name and logo are fixed and cannot be changed', 403),
    'delete-logo' => Response::error('The panel name and logo are fixed and cannot be changed', 403),

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
