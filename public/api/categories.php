<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/bootstrap.php';

$pdo = Database::connection();
$method = request_method();

function load_category(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM categories WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

if ($method === 'GET') {
    $user = Auth::user();
    $tabId = isset($_GET['tab_id']) && $_GET['tab_id'] !== '' ? (int) $_GET['tab_id'] : null;

    if ($user) {
        $sql = 'SELECT * FROM categories WHERE (is_global = 1 OR owner_id = ?)';
        $params = [(int) $user['id']];
    } else {
        $sql = 'SELECT * FROM categories WHERE is_global = 1';
        $params = [];
    }
    if ($tabId) {
        $sql .= ' AND tab_id = ?';
        $params[] = $tabId;
    }
    $sql .= ' ORDER BY sort_order ASC, id ASC';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
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
    $color = validate_hex_color((string) ($body['color'] ?? '#4fc3f7'));
    $sortOrder = (int) ($body['sort_order'] ?? 0);
    $tabId = isset($body['tab_id']) && $body['tab_id'] !== '' && $body['tab_id'] !== null
        ? (int) $body['tab_id'] : null;
    $wantGlobal = !empty($body['is_global']);

    if ($name === '') {
        json_error('Category name is required');
    }
    if ($wantGlobal && $user['role'] !== 'admin') {
        json_error('Only admins can create global categories', 403);
    }

    if ($tabId) {
        $t = $pdo->prepare('SELECT * FROM tabs WHERE id = ?');
        $t->execute([$tabId]);
        $tab = $t->fetch();
        if (!$tab) {
            json_error('Tab not found', 404);
        }
        if (empty($tab['is_global']) && (int) $tab['owner_id'] !== (int) $user['id'] && $user['role'] !== 'admin') {
            json_error('You cannot use this tab', 403);
        }
    }

    $isGlobal = $wantGlobal ? 1 : 0;
    $ownerId = $isGlobal ? null : (int) $user['id'];

    $stmt = $pdo->prepare(
        'INSERT INTO categories (name, color, sort_order, tab_id, owner_id, is_global)
         VALUES (?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([$name, $color, $sortOrder, $tabId, $ownerId, $isGlobal]);
    json_ok(load_category($pdo, (int) $pdo->lastInsertId()));
}

if ($method === 'PUT' || $method === 'PATCH') {
    $id = (int) ($body['id'] ?? $_GET['id'] ?? 0);
    $cat = load_category($pdo, $id);
    if (!$cat) {
        json_error('Category not found', 404);
    }
    if (!can_edit_owned_row($cat, $user)) {
        json_error('You cannot edit this category', 403);
    }

    $name = trim((string) ($body['name'] ?? $cat['name']));
    $color = validate_hex_color((string) ($body['color'] ?? $cat['color']));
    $sortOrder = (int) ($body['sort_order'] ?? $cat['sort_order']);
    $tabId = array_key_exists('tab_id', $body)
        ? (($body['tab_id'] === null || $body['tab_id'] === '') ? null : (int) $body['tab_id'])
        : $cat['tab_id'];

    if ($name === '') {
        json_error('Category name is required');
    }

    $stmt = $pdo->prepare(
        'UPDATE categories SET name = ?, color = ?, sort_order = ?, tab_id = ? WHERE id = ?'
    );
    $stmt->execute([$name, $color, $sortOrder, $tabId, $id]);
    json_ok(load_category($pdo, $id));
}

if ($method === 'DELETE') {
    $id = (int) ($body['id'] ?? $_GET['id'] ?? 0);
    $cat = load_category($pdo, $id);
    if (!$cat) {
        json_error('Category not found', 404);
    }
    if (!can_edit_owned_row($cat, $user)) {
        json_error('You cannot delete this category', 403);
    }

    $bms = $pdo->prepare('SELECT icon_path FROM bookmarks WHERE category_id = ?');
    $bms->execute([$id]);
    foreach ($bms->fetchAll(PDO::FETCH_COLUMN) as $iconPath) {
        delete_icon_file($iconPath ?: null);
    }

    $pdo->prepare('DELETE FROM categories WHERE id = ?')->execute([$id]);
    json_ok(['id' => $id]);
}

json_error('Method not allowed', 405);
