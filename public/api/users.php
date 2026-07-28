<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/bootstrap.php';

$pdo = Database::connection();
$method = request_method();

function fetch_user_row(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare(
        'SELECT id, username, role, is_active, must_change_password, created_at FROM users WHERE id = ?'
    );
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row ? Auth::publicUserRow($row) : null;
}

if ($method === 'GET') {
    Auth::requireAdmin();
    $rows = $pdo->query(
        'SELECT id, username, role, is_active, must_change_password, created_at
         FROM users ORDER BY username COLLATE NOCASE'
    )->fetchAll();
    json_ok(array_map(static fn ($r) => Auth::publicUserRow($r), $rows));
}

if (in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
    require_csrf();
    $admin = Auth::requireAdmin();
    $body = request_json();
}

if ($method === 'POST') {
    $username = trim((string) ($body['username'] ?? ''));
    $password = (string) ($body['password'] ?? '');
    $role = (string) ($body['role'] ?? 'user');
    $isActive = array_key_exists('is_active', $body) ? (!empty($body['is_active']) ? 1 : 0) : 1;
    $mustChange = !empty($body['must_change_password']) ? 1 : 0;

    if ($username === '' || $password === '') {
        json_error('Username and password are required');
    }
    if (mb_strlen($password) < 6) {
        json_error('Password must be at least 6 characters');
    }
    if (!in_array($role, ['admin', 'user'], true)) {
        json_error('Invalid role');
    }
    try {
        $pdo->prepare(
            'INSERT INTO users (username, password_hash, role, is_active, must_change_password)
             VALUES (?, ?, ?, ?, ?)'
        )->execute([
            $username,
            password_hash($password, PASSWORD_DEFAULT),
            $role,
            $isActive,
            $mustChange,
        ]);
    } catch (PDOException $e) {
        json_error('Username already exists');
    }
    json_ok(fetch_user_row($pdo, (int) $pdo->lastInsertId()));
}

if ($method === 'PUT' || $method === 'PATCH') {
    $id = (int) ($body['id'] ?? $_GET['id'] ?? 0);
    $stmt = $pdo->prepare('SELECT * FROM users WHERE id = ?');
    $stmt->execute([$id]);
    $user = $stmt->fetch();
    if (!$user) {
        json_error('User not found', 404);
    }

    $username = trim((string) ($body['username'] ?? $user['username']));
    $role = (string) ($body['role'] ?? $user['role']);
    $password = (string) ($body['password'] ?? '');
    $isActive = array_key_exists('is_active', $body)
        ? (!empty($body['is_active']) ? 1 : 0)
        : (int) ($user['is_active'] ?? 1);
    $mustChange = array_key_exists('must_change_password', $body)
        ? (!empty($body['must_change_password']) ? 1 : 0)
        : (int) ($user['must_change_password'] ?? 0);

    if ($username === '') {
        json_error('Username is required');
    }
    if (!in_array($role, ['admin', 'user'], true)) {
        json_error('Invalid role');
    }
    if ($id === (int) $admin['id'] && $role !== 'admin') {
        json_error('You cannot remove your own admin role');
    }
    if ($id === (int) $admin['id'] && $isActive !== 1) {
        json_error('You cannot deactivate your own account');
    }

    try {
        $pdo->prepare(
            'UPDATE users SET username = ?, role = ?, is_active = ?, must_change_password = ? WHERE id = ?'
        )->execute([$username, $role, $isActive, $mustChange, $id]);
    } catch (PDOException $e) {
        json_error('Username already exists');
    }

    if ($password !== '') {
        if (mb_strlen($password) < 6) {
            json_error('Password must be at least 6 characters');
        }
        $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
            ->execute([password_hash($password, PASSWORD_DEFAULT), $id]);
    }

    json_ok(fetch_user_row($pdo, $id));
}

if ($method === 'DELETE') {
    $id = (int) ($body['id'] ?? $_GET['id'] ?? 0);
    if ($id === (int) $admin['id']) {
        json_error('You cannot delete your own account');
    }
    $stmt = $pdo->prepare('SELECT * FROM users WHERE id = ?');
    $stmt->execute([$id]);
    $user = $stmt->fetch();
    if (!$user) {
        json_error('User not found', 404);
    }

    $notesAction = strtolower(trim((string) ($body['notes_action'] ?? 'keep')));
    if (!in_array($notesAction, ['delete', 'reassign', 'keep'], true)) {
        json_error('Invalid notes_action (use delete, reassign, or keep)');
    }

    $todoAction = strtolower(trim((string) ($body['todo_action'] ?? 'keep')));
    if (!in_array($todoAction, ['delete', 'reassign', 'keep'], true)) {
        json_error('Invalid todo_action (use delete, reassign, or keep)');
    }

    NotesDatabase::connection();
    TeamCalDatabase::connection();
    TodoDatabase::connection();

    $notesAffected = 0;
    if ($notesAction === 'delete') {
        $notesAffected = Notes::deleteByOwner($id);
    } elseif ($notesAction === 'reassign') {
        $notesAffected = Notes::reassignOwner($id, (int) $admin['id']);
    } else {
        $notesAffected = Notes::countByOwner($id);
    }

    $todoAffected = 0;
    if ($todoAction === 'delete') {
        $todoAffected = Todo::deleteByOwner($id);
    } elseif ($todoAction === 'reassign') {
        $todoAffected = Todo::reassignOwner($id, (int) $admin['id']);
    } else {
        $todoAffected = Todo::countByOwner($id);
    }
    $todoAssigneeCleared = Todo::clearAssignee($id);

    $privateEventsDeleted = TeamCal::deletePrivateEventsByOwner($id);
    $personLinksRemoved = TeamCal::removePersonFromAllEvents($id);

    $icons = $pdo->prepare('SELECT icon_path FROM bookmarks WHERE owner_id = ?');
    $icons->execute([$id]);
    foreach ($icons->fetchAll(PDO::FETCH_COLUMN) as $path) {
        delete_icon_file($path ?: null);
    }

    $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$id]);

    $userUploadDir = uploads_dir() . '/' . $id;
    if (is_dir($userUploadDir)) {
        foreach (glob($userUploadDir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($userUploadDir);
    }

    json_ok([
        'id' => $id,
        'notes_action' => $notesAction,
        'notes_affected' => $notesAffected,
        'todo_action' => $todoAction,
        'todo_affected' => $todoAffected,
        'todo_assignee_cleared' => $todoAssigneeCleared,
        'private_events_deleted' => $privateEventsDeleted,
        'person_links_removed' => $personLinksRemoved,
    ]);
}

json_error('Method not allowed', 405);
