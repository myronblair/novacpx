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

    // Create a panel user (reseller or admin) — no hosting account provisioned
    'create' => (function() use ($db, $body) {
        $username = trim($body['username'] ?? '');
        $password = $body['password'] ?? '';
        $email    = trim($body['email']    ?? '');
        $role     = $body['role']     ?? 'reseller';

        if (!$username || !$password || !$email) Response::error('username, password and email are required');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL))  Response::error('Invalid email address');
        if (!in_array($role, ['reseller', 'admin'], true)) Response::error('role must be reseller or admin');
        if (strlen($password) < 8) Response::error('Password must be at least 8 characters');
        if (!preg_match('/^[a-zA-Z0-9_\-\.]+$/', $username)) Response::error('Username may only contain letters, numbers, _ - .');

        $exists = $db->fetchOne("SELECT id FROM users WHERE username=? OR email=?", [$username, $email]);
        if ($exists) Response::error('Username or email already in use');

        $uid = (int)$db->insert(
            "INSERT INTO users (username, password, email, role, status, created_at) VALUES (?,?,?,?,?,datetime('now'))",
            [$username, password_hash($password, PASSWORD_BCRYPT), $email, $role, 'active']
        );
        audit("user.create.{$role}", "user:{$username}");
        Response::success(['id' => $uid, 'username' => $username, 'email' => $email, 'role' => $role], ucfirst($role) . ' created');
    })(),

    // Suspend a panel user (sets status=suspended on the users row)
    'suspend' => (function() use ($db, $body) {
        $id = (int)($body['id'] ?? 0);
        if (!$id) Response::error('id required');
        $u = $db->fetchOne("SELECT id, username, role FROM users WHERE id=?", [$id]);
        if (!$u) Response::error('User not found', 404);
        if ($u['role'] === 'admin') Response::error('Cannot suspend admin users');
        $db->execute("UPDATE users SET status='suspended' WHERE id=?", [$id]);
        audit('user.suspend', "user:{$u['username']}");
        Response::success(null, 'User suspended');
    })(),

    // Unsuspend a panel user
    'unsuspend' => (function() use ($db, $body) {
        $id = (int)($body['id'] ?? 0);
        if (!$id) Response::error('id required');
        $u = $db->fetchOne("SELECT id, username FROM users WHERE id=?", [$id]);
        if (!$u) Response::error('User not found', 404);
        $db->execute("UPDATE users SET status='active' WHERE id=?", [$id]);
        audit('user.unsuspend', "user:{$u['username']}");
        Response::success(null, 'User unsuspended');
    })(),

    // Change password for a panel user by user id
    'change-password' => (function() use ($db, $body) {
        $id   = (int)($body['id'] ?? 0);
        $pass = $body['password'] ?? '';
        if (!$id) Response::error('id required');
        if (strlen($pass) < 8) Response::error('Password must be at least 8 characters');
        $u = $db->fetchOne("SELECT id, username FROM users WHERE id=?", [$id]);
        if (!$u) Response::error('User not found', 404);
        $db->execute("UPDATE users SET password=? WHERE id=?", [password_hash($pass, PASSWORD_BCRYPT), $id]);
        audit('user.change-password', "user:{$u['username']}");
        Response::success(null, 'Password updated');
    })(),

    // Delete a panel user (admin or reseller — not a hosting account user)
    'delete' => (function() use ($db, $body) {
        $id = (int)($body['id'] ?? 0);
        if (!$id) Response::error('id required');
        $user = $db->fetchOne("SELECT id, username, role FROM users WHERE id=?", [$id]);
        if (!$user) Response::error('User not found', 404);
        if ($user['role'] === 'admin') Response::error('Cannot delete admin users');
        // Disown accounts under this reseller rather than deleting them
        $db->execute("UPDATE users SET reseller_id=NULL WHERE reseller_id=?", [$id]);
        $db->execute("UPDATE accounts SET reseller_id=NULL WHERE reseller_id=?", [$id]);
        $db->execute("DELETE FROM users WHERE id=?", [$id]);
        audit('user.delete', "user:{$user['username']}");
        Response::success(null, 'User deleted');
    })(),

    default => Response::error("Unknown users action: $action", 404),
};
