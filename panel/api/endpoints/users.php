<?php
Auth::getInstance()->require('admin');

$db = DB::getInstance();

match ($action) {

    // List users — admin only; supports ?role=reseller filter
    'list' => (function() use ($db) {
        $role   = $_GET['role'] ?? '';
        $search = $_GET['search'] ?? '';
        $where  = 'WHERE 1=1';
        $params = [];
        if ($role)   { $where .= " AND role = ?";                      $params[] = $role; }
        if ($search) { $where .= " AND (username LIKE ? OR email LIKE ?)"; $params[] = "%$search%"; $params[] = "%$search%"; }
        $rows = $db->fetchAll(
            "SELECT id, username, email, role, status, reseller_id, created_at FROM users $where ORDER BY username",
            $params
        );
        Response::success($rows);
    })(),

    default => Response::error("Unknown users action: $action", 404),
};
