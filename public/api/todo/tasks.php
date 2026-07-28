<?php

declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/src/bootstrap.php';

$method = request_method();
TodoDatabase::connection();
$pdo = Todo::pdo();

/**
 * @return array{
 *   title: string,
 *   description: string,
 *   status: string,
 *   due_date: ?string,
 *   assignee_id: ?int,
 *   visibility: string,
 *   group_ids: list<int>,
 *   tags: list<string>
 * }
 */
function todo_validate_payload(array $body, bool $requireTitle = true): array
{
    $title = trim((string) ($body['title'] ?? ''));
    if (mb_strlen($title) > 200) {
        json_error('Title must be at most 200 characters');
    }
    $description = trim((string) ($body['description'] ?? ''));
    if (mb_strlen($description) > 5000) {
        json_error('Description must be at most 5000 characters');
    }
    if ($requireTitle && $title === '') {
        json_error('Title is required');
    }

    $statusRaw = (string) ($body['status'] ?? 'todo');
    $status = Todo::normalizeStatus($statusRaw);
    if ($status === null) {
        json_error('Invalid status');
    }

    $dueRaw = $body['due_date'] ?? null;
    if ($dueRaw !== null && !is_string($dueRaw) && !is_numeric($dueRaw)) {
        json_error('Invalid due_date');
    }
    $dueDate = Todo::normalizeDueDate($dueRaw === null ? null : (string) $dueRaw);
    if ($dueRaw !== null && trim((string) $dueRaw) !== '' && $dueDate === null) {
        json_error('due_date must be YYYY-MM-DD');
    }

    $assigneeId = null;
    if (array_key_exists('assignee_id', $body) && $body['assignee_id'] !== null && $body['assignee_id'] !== '') {
        $assigneeId = (int) $body['assignee_id'];
        if ($assigneeId <= 0) {
            json_error('Invalid assignee_id');
        }
        $userMap = TeamCal::portalUserMap();
        if (!isset($userMap[$assigneeId])) {
            json_error('Assignee not found');
        }
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
    $tags = Todo::normalizeTagList($rawTags);

    return [
        'title' => $title,
        'description' => $description,
        'status' => $status,
        'due_date' => $dueDate,
        'assignee_id' => $assigneeId,
        'visibility' => $visibility,
        'group_ids' => $groupIds,
        'tags' => $tags,
    ];
}

if ($method === 'GET') {
    $user = Auth::requireUsableSession();
    Todo::requireEnabled();
    $userGroupIds = Auth::userGroupIds((int) $user['id']);
    $q = (string) ($_GET['q'] ?? '');
    $filter = (string) ($_GET['filter'] ?? '');
    $statusParam = isset($_GET['status']) ? (string) $_GET['status'] : null;
    if ($statusParam !== null && $statusParam !== '') {
        $normalized = Todo::normalizeStatus($statusParam);
        if ($normalized === null) {
            json_error('Invalid status filter');
        }
        $statusParam = $normalized;
    } else {
        $statusParam = null;
    }

    if (isset($_GET['id'])) {
        $id = (int) $_GET['id'];
        $task = Todo::loadTask($id);
        if (!$task) {
            json_error('Task not found', 404);
        }
        if (!Todo::canViewTask($task, $user, $userGroupIds)) {
            json_error('Permission denied', 403);
        }
        $task['can_edit'] = Todo::canEditTask($task, $user) && empty($task['archived']);
        $task['can_status'] = Todo::canChangeStatus($task, $user);
        $task['can_archive'] = Todo::canArchive($task, $user);
        json_ok($task);
    }

    $archivedOnly = isset($_GET['archived']) && (
        $_GET['archived'] === '1' || $_GET['archived'] === 'true' || $_GET['archived'] === 'yes'
    );

    $viewUserId = null;
    if (isset($_GET['view_user_id'])) {
        if (!Todo::canViewAllTasks($user)) {
            json_error('Permission denied', 403);
        }
        $rawView = strtolower(trim((string) $_GET['view_user_id']));
        if ($rawView === '' || $rawView === 'me') {
            $viewUserId = null;
        } elseif ($rawView === 'all') {
            $viewUserId = 0;
        } else {
            $viewUserId = (int) $rawView;
            if ($viewUserId <= 0) {
                json_error('Invalid view_user_id');
            }
            $userMap = TeamCal::portalUserMap();
            if (!isset($userMap[$viewUserId])) {
                json_error('View user not found', 404);
            }
        }
    }

    $list = Todo::listVisibleTasks(
        $user,
        $userGroupIds,
        $q,
        $statusParam,
        $filter,
        $archivedOnly,
        $viewUserId
    );
    json_ok($list);
}

if (in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
    require_csrf();
    $user = Auth::requireUsableSession();
    Todo::requireEnabled();
    $body = request_json();
}

if ($method === 'POST') {
    $data = todo_validate_payload($body, true);
    $ownerId = (int) $user['id'];

    $stmt = $pdo->prepare(
        'INSERT INTO tasks (title, description, status, due_date, assignee_id, visibility, owner_id,
         archived, archived_at, updated_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, 0, NULL, datetime(\'now\'))'
    );
    $stmt->execute([
        $data['title'],
        $data['description'],
        $data['status'],
        $data['due_date'],
        $data['assignee_id'],
        $data['visibility'],
        $ownerId,
    ]);
    $id = (int) $pdo->lastInsertId();
    Todo::syncGroups($id, $data['group_ids']);
    Todo::syncTags($id, $data['tags']);

    if ($data['assignee_id'] !== null) {
        Notifications::notifyTodoAssigned(
            $data['assignee_id'],
            $ownerId,
            (string) ($user['username'] ?? 'Someone'),
            $id,
            $data['title']
        );
    }

    $task = Todo::loadTask($id);
    $task['can_edit'] = true;
    $task['can_status'] = true;
    $task['can_archive'] = true;
    json_ok($task);
}

/**
 * @return array
 */
function todo_task_flags(array $task, array $user): array
{
    $task['can_edit'] = Todo::canEditTask($task, $user) && empty($task['archived']);
    $task['can_status'] = Todo::canChangeStatus($task, $user);
    $task['can_archive'] = Todo::canArchive($task, $user);
    return $task;
}

if ($method === 'PUT' || $method === 'PATCH') {
    $id = (int) ($body['id'] ?? $_GET['id'] ?? 0);
    $existing = Todo::loadTask($id);
    if (!$existing) {
        json_error('Task not found', 404);
    }

    $userGroupIds = Auth::userGroupIds((int) $user['id']);
    if (!Todo::canViewTask($existing, $user, $userGroupIds)) {
        json_error('Permission denied', 403);
    }

    $canEdit = Todo::canEditTask($existing, $user);
    $canStatus = Todo::canChangeStatus($existing, $user);
    $canArchive = Todo::canArchive($existing, $user);
    $keys = array_keys($body);

    // Archive / unarchive only
    $archiveOnly = !array_diff($keys, ['id', 'archived']);
    if ($archiveOnly && array_key_exists('archived', $body)) {
        if (!$canArchive) {
            json_error('Permission denied', 403);
        }
        $wantArchived = !empty($body['archived']) && $body['archived'] !== '0' && $body['archived'] !== false;
        if ($wantArchived) {
            if (!empty($existing['archived'])) {
                json_error('Task is already archived');
            }
            if (($existing['status'] ?? '') !== 'done') {
                json_error('Only done tasks can be archived');
            }
            Todo::setArchived($id, true);
        } else {
            if (empty($existing['archived'])) {
                json_error('Task is not archived');
            }
            Todo::setArchived($id, false);
        }
        $task = Todo::loadTask($id);
        json_ok(todo_task_flags($task, $user));
    }

    // Status-only update for assignee (or when only status is sent and user can change status)
    $statusOnly = !array_diff($keys, ['id', 'status']);
    if ($statusOnly && array_key_exists('status', $body)) {
        if (!$canStatus) {
            json_error('Permission denied', 403);
        }
        $status = Todo::normalizeStatus((string) $body['status']);
        if ($status === null) {
            json_error('Invalid status');
        }
        $pdo->prepare(
            'UPDATE tasks SET status = ?, updated_at = datetime(\'now\') WHERE id = ?'
        )->execute([$status, $id]);
        $task = Todo::loadTask($id);
        json_ok(todo_task_flags($task, $user));
    }

    if (!empty($existing['archived'])) {
        json_error('Unarchive the task before editing', 403);
    }

    if (!$canEdit) {
        // Allow status change as part of partial update for assignee
        if ($canStatus && array_key_exists('status', $body) && count($keys) <= 2) {
            $status = Todo::normalizeStatus((string) $body['status']);
            if ($status === null) {
                json_error('Invalid status');
            }
            $pdo->prepare(
                'UPDATE tasks SET status = ?, updated_at = datetime(\'now\') WHERE id = ?'
            )->execute([$status, $id]);
            $task = Todo::loadTask($id);
            json_ok(todo_task_flags($task, $user));
        }
        json_error('Permission denied', 403);
    }

    $merged = [
        'title' => $body['title'] ?? $existing['title'],
        'description' => $body['description'] ?? $existing['description'],
        'status' => $body['status'] ?? $existing['status'],
        'due_date' => array_key_exists('due_date', $body) ? $body['due_date'] : $existing['due_date'],
        'assignee_id' => array_key_exists('assignee_id', $body) ? $body['assignee_id'] : $existing['assignee_id'],
        'visibility' => $body['visibility'] ?? $existing['visibility'],
        'group_ids' => $body['group_ids'] ?? $existing['group_ids'],
        'tags' => $body['tags'] ?? $existing['tags'] ?? [],
    ];
    $data = todo_validate_payload($merged, true);

    $pdo->prepare(
        'UPDATE tasks SET title = ?, description = ?, status = ?, due_date = ?, assignee_id = ?,
         visibility = ?, updated_at = datetime(\'now\')
         WHERE id = ?'
    )->execute([
        $data['title'],
        $data['description'],
        $data['status'],
        $data['due_date'],
        $data['assignee_id'],
        $data['visibility'],
        $id,
    ]);
    Todo::syncGroups($id, $data['group_ids']);
    Todo::syncTags($id, $data['tags']);

    $prevAssignee = $existing['assignee_id'] !== null ? (int) $existing['assignee_id'] : null;
    $newAssignee = $data['assignee_id'];
    if ($newAssignee !== null && $newAssignee !== $prevAssignee) {
        Notifications::notifyTodoAssigned(
            $newAssignee,
            (int) $user['id'],
            (string) ($user['username'] ?? 'Someone'),
            $id,
            $data['title']
        );
    }

    $task = Todo::loadTask($id);
    json_ok(todo_task_flags($task, $user));
}

if ($method === 'DELETE') {
    $id = (int) ($body['id'] ?? $_GET['id'] ?? 0);
    $existing = Todo::loadTask($id);
    if (!$existing) {
        json_error('Task not found', 404);
    }
    if (!Todo::canEditTask($existing, $user)) {
        json_error('Permission denied', 403);
    }
    $pdo->prepare('DELETE FROM tasks WHERE id = ?')->execute([$id]);
    json_ok(['id' => $id]);
}

json_error('Method not allowed', 405);
