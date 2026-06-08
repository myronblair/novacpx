<?php
/**
 * Sessions endpoint — admin session management
 * GET  sessions/list              — all active sessions with user info
 * DELETE sessions/revoke          — {session_id} revoke one session
 * DELETE sessions/revoke-user     — {user_id} revoke all sessions for a user
 * DELETE sessions/revoke-all      — revoke all sessions except current
 */

Auth::getInstance()->require('admin');
$body = json_decode(file_get_contents('php://input'), true) ?? [];
$db   = DB::getInstance();
$me   = Auth::getInstance()->user();
$method = $_SERVER['REQUEST_METHOD'];

match (true) {

    $action === 'list' && $method === 'GET' => (function() use ($db) {
        $rows = $db->fetchAll(
            "SELECT s.id, s.user_id, s.ip_address, s.user_agent, s.created_at, s.expires_at,
                    u.username, u.email, u.role
             FROM sessions s
             JOIN users u ON u.id = s.user_id
             WHERE s.expires_at > NOW()
             ORDER BY s.created_at DESC
             LIMIT 200"
        ) ?: [];
        Response::json(['success' => true, 'data' => $rows]);
    })(),

    $action === 'revoke' && $method === 'DELETE' => (function() use ($db, $body) {
        $sid = trim($body['session_id'] ?? '');
        if (!$sid) Response::error('session_id required', 400);
        $db->execute("DELETE FROM sessions WHERE id = ?", [$sid]);
        Response::json(['success' => true]);
    })(),

    $action === 'revoke-user' && $method === 'DELETE' => (function() use ($db, $body) {
        $uid = (int)($body['user_id'] ?? 0);
        if (!$uid) Response::error('user_id required', 400);
        $count = $db->execute("DELETE FROM sessions WHERE user_id = ?", [$uid]);
        Response::json(['success' => true, 'data' => ['revoked' => $count]]);
    })(),

    $action === 'revoke-all' && $method === 'DELETE' => (function() use ($db, $me, $body) {
        // Keep current session if provided
        $keepId = $body['keep_session'] ?? null;
        if ($keepId) {
            $db->execute("DELETE FROM sessions WHERE id != ?", [hash('sha256', $keepId)]);
        } else {
            $db->execute("DELETE FROM sessions");
        }
        Response::json(['success' => true]);
    })(),

    default => Response::error('Not found', 404),
};
