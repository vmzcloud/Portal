<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/bootstrap.php';

$pdo = Database::connection();
$method = request_method();

function load_tab(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM tabs WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

if ($method === 'GET') {
    $user = Auth::user();
    if ($user) {
        $stmt = $pdo->prepare(
            'SELECT * FROM tabs
             WHERE is_global = 1 OR owner_id = ?
             ORDER BY is_global DESC, sort_order ASC, id ASC'
        );
        $stmt->execute([(int) $user['id']]);
    } else {
        $stmt = $pdo->query(
            'SELECT * FROM tabs WHERE is_global = 1 ORDER BY sort_order ASC, id ASC'
        );
    }
    $rows = $stmt->fetchAll();
    foreach ($rows as &$row) {
        $row['can_edit'] = $user ? can_edit_owned_row($row, $user) : false;
    }
    json_ok($rows);
}

if (in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
    require_csrf();
    $user = Auth::requireUsableSession();
    $body = request_json();
}

if ($method === 'POST') {
    $name = trim((string) ($body['name'] ?? ''));
    $sortOrder = (int) ($body['sort_order'] ?? 0);
    $wantGlobal = !empty($body['is_global']);

    if ($name === '') {
        json_error('Tab name is required');
    }
    if ($wantGlobal && $user['role'] !== 'admin') {
        json_error('Only admins can create global tabs', 403);
    }

    $isGlobal = $wantGlobal ? 1 : 0;
    $ownerId = $isGlobal ? null : (int) $user['id'];

    $stmt = $pdo->prepare(
        'INSERT INTO tabs (name, sort_order, owner_id, is_global) VALUES (?, ?, ?, ?)'
    );
    $stmt->execute([$name, $sortOrder, $ownerId, $isGlobal]);
    json_ok(load_tab($pdo, (int) $pdo->lastInsertId()));
}

if ($method === 'PUT' || $method === 'PATCH') {
    $id = (int) ($body['id'] ?? $_GET['id'] ?? 0);
    $tab = load_tab($pdo, $id);
    if (!$tab) {
        json_error('Tab not found', 404);
    }
    if (!can_edit_owned_row($tab, $user)) {
        json_error('You cannot edit this tab', 403);
    }

    $name = trim((string) ($body['name'] ?? $tab['name']));
    $sortOrder = (int) ($body['sort_order'] ?? $tab['sort_order']);
    if ($name === '') {
        json_error('Tab name is required');
    }

    $stmt = $pdo->prepare('UPDATE tabs SET name = ?, sort_order = ? WHERE id = ?');
    $stmt->execute([$name, $sortOrder, $id]);
    json_ok(load_tab($pdo, $id));
}

if ($method === 'DELETE') {
    $id = (int) ($body['id'] ?? $_GET['id'] ?? 0);
    $tab = load_tab($pdo, $id);
    if (!$tab) {
        json_error('Tab not found', 404);
    }
    if (!can_edit_owned_row($tab, $user)) {
        json_error('You cannot delete this tab', 403);
    }
    $pdo->prepare('UPDATE categories SET tab_id = NULL WHERE tab_id = ?')->execute([$id]);
    $pdo->prepare('DELETE FROM tabs WHERE id = ?')->execute([$id]);
    json_ok(['id' => $id]);
}

json_error('Method not allowed', 405);
