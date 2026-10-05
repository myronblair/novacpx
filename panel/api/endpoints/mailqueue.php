<?php
// Copyright (c) 2026 TomTom Enterprises. Licensed under the MIT License (see LICENSE).
/**
 * Mail queue API (admin): the Postfix queue and the usual repair actions.
 *   GET  /api/mailqueue/list
 *   POST /api/mailqueue/action  {action: flush|retry|delete|hold|release|purge, id?, confirm_all?}
 */
require_once NOVACPX_LIB . '/Root.php';
Auth::getInstance()->require('admin');
$body = json_decode(file_get_contents('php://input'), true) ?? [];

match ($action) {
    'list' => (function() {
        try { $items = Root::json('mail.queue.list'); }
        catch (RuntimeException $e) { Response::error($e->getMessage()); }
        usort($items, fn($a, $b) => $b['time'] <=> $a['time']);
        Response::success(['count' => count($items), 'messages' => $items]);
    })(),

    'action' => (function() use ($body) {
        $act = (string)($body['action'] ?? '');
        if (!in_array($act, ['flush', 'retry', 'delete', 'hold', 'release', 'purge'], true)) Response::error('Unknown action');
        $params = ['action' => $act];
        if (in_array($act, ['retry', 'delete', 'hold', 'release'], true)) $params['id'] = (string)($body['id'] ?? '');
        if ($act === 'purge') $params['confirm_all'] = !empty($body['confirm_all']);
        try { Root::ok('mail.queue.action', $params); }
        catch (RuntimeException $e) { Response::error($e->getMessage()); }
        audit('mailqueue.' . $act, (string)($body['id'] ?? 'all'));
        Response::success(null, 'Done');
    })(),

    default => Response::error("Unknown mailqueue action: $action", 404),
};
