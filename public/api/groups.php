<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/bootstrap.php';

$pdo = Database::connection();
$method = request_method();

function load_group(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM groups WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if (!$row) {
        return null;
    }
    $m = $pdo->prepare(
        'SELECT u.id, u.username FROM group_members gm
         JOIN users u ON u.id = gm.user_id
         WHERE gm.group_id = ? ORDER BY u.username'
    );
    $m->execute([$id]);
    $row['members'] = $m->fetchAll();
    $row['member_ids'] = array_map(static fn ($x) => (int) $x['id'], $row['members']);
    return $row;
}

if ($method === 'GET') {
    $user = Auth::requireUsableSession();
    if (Auth::isAdmin()) {
        $rows = $pdo->query('SELECT * FROM groups ORDER BY name COLLATE NOCASE')->fetchAll();
    } else {
        $stmt = $pdo->prepare(
            'SELECT g.* FROM groups g
             JOIN group_members gm ON gm.group_id = g.id
             WHERE gm.user_id = ?
             ORDER BY g.name COLLATE NOCASE'
        );
        $stmt->execute([(int) $user['id']]);
        $rows = $stmt->fetchAll();
    }
    foreach ($rows as &$row) {
        $full = load_group($pdo, (int) $row['id']);
        $row = $full ?? $row;
    }
    json_ok($rows);
}

if (in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
    require_csrf();
    Auth::requireAdmin();
    $body = request_json();
}

if ($method === 'POST') {
    $name = trim((string) ($body['name'] ?? ''));
    $description = trim((string) ($body['description'] ?? ''));
    $memberIds = $body['member_ids'] ?? [];
    if ($name === '') {
        json_error('Group name is required');
    }
    try {
        $pdo->prepare('INSERT INTO groups (name, description) VALUES (?, ?)')
            ->execute([$name, $description]);
    } catch (PDOException $e) {
        json_error('Group name already exists');
    }
    $id = (int) $pdo->lastInsertId();
    if (is_array($memberIds)) {
        $ins = $pdo->prepare('INSERT OR IGNORE INTO group_members (group_id, user_id) VALUES (?, ?)');
        foreach ($memberIds as $uid) {
            $ins->execute([$id, (int) $uid]);
        }
    }
    json_ok(load_group($pdo, $id));
}

if ($method === 'PUT' || $method === 'PATCH') {
    $id = (int) ($body['id'] ?? $_GET['id'] ?? 0);
    $group = load_group($pdo, $id);
    if (!$group) {
        json_error('Group not found', 404);
    }
    $name = trim((string) ($body['name'] ?? $group['name']));
    $description = trim((string) ($body['description'] ?? $group['description']));
    if ($name === '') {
        json_error('Group name is required');
    }
    try {
        $pdo->prepare('UPDATE groups SET name = ?, description = ? WHERE id = ?')
            ->execute([$name, $description, $id]);
    } catch (PDOException $e) {
        json_error('Group name already exists');
    }
    if (array_key_exists('member_ids', $body) && is_array($body['member_ids'])) {
        $pdo->prepare('DELETE FROM group_members WHERE group_id = ?')->execute([$id]);
        $ins = $pdo->prepare('INSERT OR IGNORE INTO group_members (group_id, user_id) VALUES (?, ?)');
        foreach ($body['member_ids'] as $uid) {
            $ins->execute([$id, (int) $uid]);
        }
    }
    json_ok(load_group($pdo, $id));
}

if ($method === 'DELETE') {
    $id = (int) ($body['id'] ?? $_GET['id'] ?? 0);
    $group = load_group($pdo, $id);
    if (!$group) {
        json_error('Group not found', 404);
    }
    $pdo->prepare('DELETE FROM groups WHERE id = ?')->execute([$id]);
    json_ok(['id' => $id]);
}

json_error('Method not allowed', 405);
