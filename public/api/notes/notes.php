<?php

declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/src/bootstrap.php';

$method = request_method();
NotesDatabase::connection();
$pdo = Notes::pdo();

/**
 * @return array{title: string, body_html: string, visibility: string, group_ids: list<int>, tags: list<string>}
 */
function notes_validate_payload(array $body): array
{
    $title = trim((string) ($body['title'] ?? ''));
    if (mb_strlen($title) > 200) {
        json_error('Title must be at most 200 characters');
    }

    $bodyHtml = Notes::sanitizeHtml((string) ($body['body_html'] ?? ''));
    if ($title === '' && trim(strip_tags($bodyHtml)) === '') {
        json_error('Title or body is required');
    }
    if ($title === '') {
        $title = 'Untitled';
    }

    $visibility = strtolower(trim((string) ($body['visibility'] ?? 'private')));
    if (!in_array($visibility, ['private', 'share'], true)) {
        json_error('Invalid visibility');
    }

    $groupIds = $body['group_ids'] ?? [];
    if (!is_array($groupIds)) {
        $groupIds = [];
    }
    $groupIds = array_values(array_unique(array_filter(
        array_map('intval', $groupIds),
        static fn ($id) => $id > 0
    )));

    if ($visibility === 'share' && $groupIds === []) {
        json_error('Select at least one group for share visibility');
    }
    if ($visibility !== 'share') {
        $groupIds = [];
    }

    $rawTags = $body['tags'] ?? [];
    if (!is_array($rawTags)) {
        $rawTags = [];
    }
    $tags = Notes::normalizeTagList($rawTags);

    return [
        'title' => $title,
        'body_html' => $bodyHtml,
        'visibility' => $visibility,
        'group_ids' => $groupIds,
        'tags' => $tags,
    ];
}

if ($method === 'GET') {
    $user = Auth::requireUsableSession();
    Notes::requireEnabled();
    $userGroupIds = Auth::userGroupIds((int) $user['id']);
    $q = (string) ($_GET['q'] ?? '');

    if (isset($_GET['id'])) {
        $id = (int) $_GET['id'];
        $note = Notes::loadNote($id);
        if (!$note) {
            json_error('Note not found', 404);
        }
        if (!Notes::canViewNote($note, $user, $userGroupIds)) {
            json_error('Permission denied', 403);
        }
        $note['can_edit'] = Notes::canEditNote($note, $user);
        json_ok($note);
    }

    $list = Notes::listVisibleNotes($user, $userGroupIds, $q);
    json_ok($list);
}

if (in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
    require_csrf();
    $user = Auth::requireUsableSession();
    Notes::requireEnabled();
    $body = request_json();
}

if ($method === 'POST') {
    $data = notes_validate_payload($body);
    $ownerId = (int) $user['id'];

    $stmt = $pdo->prepare(
        'INSERT INTO notes (title, body_html, visibility, owner_id, updated_at)
         VALUES (?, ?, ?, ?, datetime(\'now\'))'
    );
    $stmt->execute([
        $data['title'],
        $data['body_html'],
        $data['visibility'],
        $ownerId,
    ]);
    $id = (int) $pdo->lastInsertId();
    Notes::syncGroups($id, $data['group_ids']);
    Notes::syncTags($id, $data['tags']);

    $note = Notes::loadNote($id);
    $note['can_edit'] = true;
    json_ok($note);
}

if ($method === 'PUT' || $method === 'PATCH') {
    $id = (int) ($body['id'] ?? $_GET['id'] ?? 0);
    $existing = Notes::loadNote($id);
    if (!$existing) {
        json_error('Note not found', 404);
    }
    if (!Notes::canEditNote($existing, $user)) {
        json_error('Permission denied', 403);
    }

    $data = notes_validate_payload($body);
    $pdo->prepare(
        'UPDATE notes SET title = ?, body_html = ?, visibility = ?, updated_at = datetime(\'now\')
         WHERE id = ?'
    )->execute([
        $data['title'],
        $data['body_html'],
        $data['visibility'],
        $id,
    ]);
    Notes::syncGroups($id, $data['group_ids']);
    Notes::syncTags($id, $data['tags']);

    $note = Notes::loadNote($id);
    $note['can_edit'] = true;
    json_ok($note);
}

if ($method === 'DELETE') {
    $id = (int) ($body['id'] ?? $_GET['id'] ?? 0);
    $existing = Notes::loadNote($id);
    if (!$existing) {
        json_error('Note not found', 404);
    }
    if (!Notes::canEditNote($existing, $user)) {
        json_error('Permission denied', 403);
    }
    $pdo->prepare('DELETE FROM notes WHERE id = ?')->execute([$id]);
    json_ok(['id' => $id]);
}

json_error('Method not allowed', 405);
