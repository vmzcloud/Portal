<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/bootstrap.php';

$method = request_method();

if ($method === 'GET') {
    $user = Auth::requireUsableSession();
    $uid = (int) $user['id'];

    // Lazy: create calendar day-before reminders when due
    Notifications::dispatchEventReminders();

    if (isset($_GET['count']) && ($_GET['count'] === '1' || $_GET['count'] === 'true')) {
        json_ok(['unread' => Notifications::unreadCount($uid)]);
    }

    $unreadOnly = isset($_GET['unread']) && (
        $_GET['unread'] === '1' || $_GET['unread'] === 'true'
    );
    $limit = isset($_GET['limit']) ? (int) $_GET['limit'] : 100;
    json_ok([
        'unread' => Notifications::unreadCount($uid),
        'items' => Notifications::listForUser($uid, $limit, $unreadOnly),
    ]);
}

if ($method === 'PUT' || $method === 'PATCH' || $method === 'POST') {
    require_csrf();
    $user = Auth::requireUsableSession();
    $uid = (int) $user['id'];
    $body = request_json();

    if (!empty($body['all'])) {
        $n = Notifications::markAllRead($uid);
        json_ok([
            'marked' => $n,
            'unread' => Notifications::unreadCount($uid),
        ]);
    }

    $id = (int) ($body['id'] ?? 0);
    if ($id <= 0) {
        json_error('Provide id or all');
    }
    if (!Notifications::markRead($id, $uid)) {
        json_error('Notification not found', 404);
    }
    json_ok([
        'id' => $id,
        'is_read' => true,
        'unread' => Notifications::unreadCount($uid),
    ]);
}

if ($method === 'DELETE') {
    require_csrf();
    $user = Auth::requireUsableSession();
    $uid = (int) $user['id'];
    $body = request_json();
    $id = (int) ($body['id'] ?? $_GET['id'] ?? 0);
    if ($id <= 0) {
        json_error('Provide id');
    }
    if (!Notifications::deleteForUser($id, $uid)) {
        json_error('Notification not found', 404);
    }
    json_ok([
        'id' => $id,
        'unread' => Notifications::unreadCount($uid),
    ]);
}

json_error('Method not allowed', 405);
