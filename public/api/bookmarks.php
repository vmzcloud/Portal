<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/bootstrap.php';

$pdo = Database::connection();
$method = request_method();

function fetch_bookmark_groups(PDO $pdo, int $bookmarkId): array
{
    $stmt = $pdo->prepare('SELECT group_id FROM bookmark_groups WHERE bookmark_id = ?');
    $stmt->execute([$bookmarkId]);
    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

function load_bookmark(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM bookmarks WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if (!$row) {
        return null;
    }
    $row['group_ids'] = fetch_bookmark_groups($pdo, $id);
    return $row;
}

function sync_bookmark_groups(PDO $pdo, int $bookmarkId, array $groupIds): void
{
    $pdo->prepare('DELETE FROM bookmark_groups WHERE bookmark_id = ?')->execute([$bookmarkId]);
    $ins = $pdo->prepare('INSERT INTO bookmark_groups (bookmark_id, group_id) VALUES (?, ?)');
    foreach (array_unique(array_map('intval', $groupIds)) as $gid) {
        if ($gid > 0) {
            $ins->execute([$bookmarkId, $gid]);
        }
    }
}

if ($method === 'GET') {
    $user = Auth::user();
    $userGroupIds = $user ? Auth::userGroupIds((int) $user['id']) : [];
    $tabId = isset($_GET['tab_id']) && $_GET['tab_id'] !== '' ? (int) $_GET['tab_id'] : null;
    $categoryId = isset($_GET['category_id']) && $_GET['category_id'] !== '' ? (int) $_GET['category_id'] : null;

    $sql = 'SELECT b.*, c.name AS category_name, c.color AS category_color, c.tab_id,
                   u.username AS owner_name
            FROM bookmarks b
            JOIN categories c ON c.id = b.category_id
            JOIN users u ON u.id = b.owner_id
            WHERE 1=1';
    $params = [];

    if ($categoryId) {
        $sql .= ' AND b.category_id = ?';
        $params[] = $categoryId;
    }
    if ($tabId) {
        $sql .= ' AND c.tab_id = ?';
        $params[] = $tabId;
    }

    $sql .= ' ORDER BY c.sort_order ASC, b.sort_order ASC, b.id ASC';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    $result = [];
    foreach ($rows as $row) {
        $row['group_ids'] = fetch_bookmark_groups($pdo, (int) $row['id']);
        if (!can_view_bookmark($row, $user, $userGroupIds)) {
            continue;
        }
        $row['icon_src'] = bookmark_icon_src($row['icon_path'], $row['title']);
        $row['can_edit'] = $user ? can_edit_bookmark($row, $user) : false;
        $result[] = $row;
    }
    json_ok($result);
}

if ($method === 'POST' || $method === 'PUT' || $method === 'PATCH' || $method === 'DELETE') {
    require_csrf();
    $user = Auth::requireUsableSession();
    $body = request_json();
}

if ($method === 'POST') {
    $title = trim((string) ($body['title'] ?? ''));
    $url = normalize_url((string) ($body['url'] ?? ''));
    $categoryId = (int) ($body['category_id'] ?? 0);
    $visibility = (string) ($body['visibility'] ?? 'private');
    $groupIds = $body['group_ids'] ?? [];
    $iconPath = isset($body['icon_path']) ? trim((string) $body['icon_path']) : null;
    $sortOrder = (int) ($body['sort_order'] ?? 0);

    if ($title === '' || $url === '' || $categoryId <= 0) {
        json_error('Title, URL, and category are required');
    }
    if (!in_array($visibility, ['public', 'share', 'private'], true)) {
        json_error('Invalid visibility');
    }
    if ($visibility === 'share' && (!is_array($groupIds) || count($groupIds) === 0)) {
        json_error('Shared bookmarks require at least one group');
    }

    $cat = $pdo->prepare('SELECT * FROM categories WHERE id = ?');
    $cat->execute([$categoryId]);
    $category = $cat->fetch();
    if (!$category) {
        json_error('Category not found', 404);
    }
    // May use global categories or own personal categories
    if (empty($category['is_global']) && (int) ($category['owner_id'] ?? 0) !== (int) $user['id'] && $user['role'] !== 'admin') {
        json_error('You cannot use this category', 403);
    }

    if ($iconPath === '') {
        $iconPath = null;
    }

    $stmt = $pdo->prepare(
        'INSERT INTO bookmarks (title, url, icon_path, category_id, visibility, owner_id, sort_order)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([$title, $url, $iconPath, $categoryId, $visibility, (int) $user['id'], $sortOrder]);
    $id = (int) $pdo->lastInsertId();
    if ($visibility === 'share') {
        sync_bookmark_groups($pdo, $id, is_array($groupIds) ? $groupIds : []);
    }
    json_ok(load_bookmark($pdo, $id));
}

if ($method === 'PUT' || $method === 'PATCH') {
    $id = (int) ($body['id'] ?? $_GET['id'] ?? 0);
    $bookmark = load_bookmark($pdo, $id);
    if (!$bookmark) {
        json_error('Bookmark not found', 404);
    }
    if (!can_edit_bookmark($bookmark, $user)) {
        json_error('You cannot edit this bookmark', 403);
    }

    $title = trim((string) ($body['title'] ?? $bookmark['title']));
    $url = normalize_url((string) ($body['url'] ?? $bookmark['url']));
    $categoryId = (int) ($body['category_id'] ?? $bookmark['category_id']);
    $visibility = (string) ($body['visibility'] ?? $bookmark['visibility']);
    $groupIds = $body['group_ids'] ?? $bookmark['group_ids'];
    $sortOrder = (int) ($body['sort_order'] ?? $bookmark['sort_order']);
    $clearIcon = !empty($body['clear_icon']);
    $iconPath = array_key_exists('icon_path', $body)
        ? (trim((string) $body['icon_path']) ?: null)
        : $bookmark['icon_path'];

    if ($title === '' || $url === '' || $categoryId <= 0) {
        json_error('Title, URL, and category are required');
    }
    if (!in_array($visibility, ['public', 'share', 'private'], true)) {
        json_error('Invalid visibility');
    }
    if ($visibility === 'share' && (!is_array($groupIds) || count($groupIds) === 0)) {
        json_error('Shared bookmarks require at least one group');
    }

    $cat = $pdo->prepare('SELECT * FROM categories WHERE id = ?');
    $cat->execute([$categoryId]);
    $category = $cat->fetch();
    if (!$category) {
        json_error('Category not found', 404);
    }
    if (empty($category['is_global']) && (int) ($category['owner_id'] ?? 0) !== (int) $user['id'] && $user['role'] !== 'admin') {
        json_error('You cannot use this category', 403);
    }

    if ($clearIcon) {
        delete_icon_file($bookmark['icon_path']);
        $iconPath = null;
    } elseif ($iconPath !== $bookmark['icon_path'] && $bookmark['icon_path']) {
        // keep old file unless explicitly replaced via icons API delete
    }

    $stmt = $pdo->prepare(
        'UPDATE bookmarks SET title = ?, url = ?, icon_path = ?, category_id = ?, visibility = ?,
         sort_order = ?, updated_at = datetime(\'now\') WHERE id = ?'
    );
    $stmt->execute([$title, $url, $iconPath, $categoryId, $visibility, $sortOrder, $id]);

    if ($visibility === 'share') {
        sync_bookmark_groups($pdo, $id, is_array($groupIds) ? $groupIds : []);
    } else {
        sync_bookmark_groups($pdo, $id, []);
    }

    json_ok(load_bookmark($pdo, $id));
}

if ($method === 'DELETE') {
    $id = (int) ($body['id'] ?? $_GET['id'] ?? 0);
    $bookmark = load_bookmark($pdo, $id);
    if (!$bookmark) {
        json_error('Bookmark not found', 404);
    }
    if (!can_edit_bookmark($bookmark, $user)) {
        json_error('You cannot delete this bookmark', 403);
    }
    delete_icon_file($bookmark['icon_path']);
    $pdo->prepare('DELETE FROM bookmarks WHERE id = ?')->execute([$id]);
    json_ok(['id' => $id]);
}

json_error('Method not allowed', 405);
