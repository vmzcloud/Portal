<?php

declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/src/bootstrap.php';

$method = request_method();
TodoDatabase::connection();

if ($method === 'GET') {
    $user = Auth::requireUsableSession();
    if (!Todo::isEnabled() && !Auth::isAdmin()) {
        json_error('Todo is disabled', 403);
    }

    $pdo = Database::connection();
    if (($user['role'] ?? '') === 'admin') {
        $groups = $pdo->query('SELECT id, name FROM groups ORDER BY name COLLATE NOCASE')->fetchAll();
    } else {
        $stmt = $pdo->prepare(
            'SELECT g.id, g.name FROM groups g
             INNER JOIN group_members gm ON gm.group_id = g.id
             WHERE gm.user_id = ?
             ORDER BY g.name COLLATE NOCASE'
        );
        $stmt->execute([(int) $user['id']]);
        $groups = $stmt->fetchAll();
    }

    $data = [
        'enabled' => Todo::isEnabled(),
        'statuses' => Todo::STATUSES,
        'users' => TeamCal::activePortalUsers(),
        'groups' => array_map(static fn ($g) => [
            'id' => (int) $g['id'],
            'name' => (string) $g['name'],
        ], $groups),
        'is_task_viewer' => Todo::isTaskViewer($user),
        'can_view_all' => Todo::canViewAllTasks($user),
    ];
    if (Auth::isAdmin()) {
        $data['task_viewer_ids'] = Todo::getTaskViewerIds();
    }
    json_ok($data);
}

json_error('Method not allowed', 405);
