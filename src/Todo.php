<?php

declare(strict_types=1);

final class Todo
{
    public const STATUSES = ['todo', 'in_progress', 'done'];

    public static function pdo(): PDO
    {
        return TodoDatabase::connection();
    }

    public static function getSetting(string $key, string $default = ''): string
    {
        $stmt = self::pdo()->prepare('SELECT value FROM settings WHERE key = ?');
        $stmt->execute([$key]);
        $val = $stmt->fetchColumn();
        return $val === false ? $default : (string) $val;
    }

    public static function setSetting(string $key, string $value): void
    {
        self::pdo()->prepare(
            'INSERT INTO settings (key, value) VALUES (?, ?)
             ON CONFLICT(key) DO UPDATE SET value = excluded.value'
        )->execute([$key, $value]);
    }

    public static function isEnabled(): bool
    {
        return self::getSetting('enabled', '0') === '1';
    }

    public static function requireEnabled(): void
    {
        if (!self::isEnabled()) {
            json_error('Todo is disabled', 403);
        }
    }

    /** @return list<int> */
    public static function getTaskViewerIds(): array
    {
        $raw = self::getSetting('task_viewers', '[]');
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [];
        }
        $ids = [];
        foreach ($decoded as $id) {
            $n = (int) $id;
            if ($n > 0) {
                $ids[$n] = $n;
            }
        }
        return array_values($ids);
    }

    /**
     * @param list<int|string> $ids
     * @return list<int>
     */
    public static function setTaskViewerIds(array $ids): array
    {
        $userMap = TeamCal::portalUserMap();
        $clean = [];
        foreach ($ids as $id) {
            $n = (int) $id;
            if ($n > 0 && isset($userMap[$n])) {
                $clean[$n] = $n;
            }
        }
        $list = array_values($clean);
        self::setSetting('task_viewers', json_encode($list, JSON_UNESCAPED_UNICODE));
        return $list;
    }

    public static function isTaskViewer(?array $user): bool
    {
        if (!$user) {
            return false;
        }
        $uid = (int) ($user['id'] ?? 0);
        if ($uid <= 0) {
            return false;
        }
        return in_array($uid, self::getTaskViewerIds(), true);
    }

    public static function canViewAllTasks(?array $user): bool
    {
        if (!$user) {
            return false;
        }
        if (($user['role'] ?? '') === 'admin') {
            return true;
        }
        return self::isTaskViewer($user);
    }

    public static function removeTaskViewer(int $userId): void
    {
        if ($userId <= 0) {
            return;
        }
        $ids = array_values(array_filter(
            self::getTaskViewerIds(),
            static fn (int $id) => $id !== $userId
        ));
        self::setSetting('task_viewers', json_encode($ids, JSON_UNESCAPED_UNICODE));
    }

    public static function normalizeStatus(string $raw): ?string
    {
        $s = strtolower(trim($raw));
        if ($s === 'in-progress') {
            $s = 'in_progress';
        }
        return in_array($s, self::STATUSES, true) ? $s : null;
    }

    public static function normalizeDueDate(?string $raw): ?string
    {
        if ($raw === null) {
            return null;
        }
        $v = trim($raw);
        if ($v === '') {
            return null;
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) {
            return null;
        }
        $parts = explode('-', $v);
        if (!checkdate((int) $parts[1], (int) $parts[2], (int) $parts[0])) {
            return null;
        }
        return $v;
    }

    /** @return list<int> */
    public static function fetchGroupIds(int $taskId): array
    {
        $stmt = self::pdo()->prepare('SELECT group_id FROM task_groups WHERE task_id = ?');
        $stmt->execute([$taskId]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    public static function syncGroups(int $taskId, array $groupIds): void
    {
        $pdo = self::pdo();
        $pdo->prepare('DELETE FROM task_groups WHERE task_id = ?')->execute([$taskId]);
        $ins = $pdo->prepare('INSERT INTO task_groups (task_id, group_id) VALUES (?, ?)');
        foreach (array_unique(array_map('intval', $groupIds)) as $gid) {
            if ($gid > 0) {
                $ins->execute([$taskId, $gid]);
            }
        }
    }

    public static function normalizeTagName(string $raw): ?string
    {
        $name = mb_strtolower(trim($raw));
        if (str_starts_with($name, '#')) {
            $name = ltrim($name, '#');
        }
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 40) {
            return null;
        }
        if (!preg_match('/^[a-z0-9][a-z0-9_-]*$/u', $name)) {
            return null;
        }
        return $name;
    }

    /**
     * @param list<mixed> $raw
     * @return list<string>
     */
    public static function normalizeTagList(array $raw): array
    {
        $out = [];
        foreach ($raw as $item) {
            if (!is_string($item) && !is_numeric($item)) {
                continue;
            }
            $name = self::normalizeTagName((string) $item);
            if ($name === null) {
                continue;
            }
            $out[$name] = $name;
            if (count($out) >= 20) {
                break;
            }
        }
        return array_values($out);
    }

    /** @return list<string> */
    public static function fetchTags(int $taskId): array
    {
        $stmt = self::pdo()->prepare(
            'SELECT t.name FROM tags t
             INNER JOIN task_tags tt ON tt.tag_id = t.id
             WHERE tt.task_id = ?
             ORDER BY t.name COLLATE NOCASE'
        );
        $stmt->execute([$taskId]);
        return array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * @param list<string> $names Already normalized tag names
     */
    public static function syncTags(int $taskId, array $names): void
    {
        $pdo = self::pdo();
        $pdo->prepare('DELETE FROM task_tags WHERE task_id = ?')->execute([$taskId]);
        if ($names === []) {
            return;
        }

        $find = $pdo->prepare('SELECT id FROM tags WHERE name = ?');
        $create = $pdo->prepare('INSERT INTO tags (name) VALUES (?)');
        $link = $pdo->prepare('INSERT OR IGNORE INTO task_tags (task_id, tag_id) VALUES (?, ?)');

        foreach ($names as $name) {
            $find->execute([$name]);
            $tagId = $find->fetchColumn();
            if ($tagId === false) {
                $create->execute([$name]);
                $tagId = (int) $pdo->lastInsertId();
            } else {
                $tagId = (int) $tagId;
            }
            $link->execute([$taskId, $tagId]);
        }
    }

    /**
     * Evaluate AND/OR search query against a task.
     */
    public static function matchesQuery(array $task, string $q): bool
    {
        $tags = is_array($task['tags'] ?? null) ? $task['tags'] : [];
        $fields = [
            (string) ($task['title'] ?? ''),
            (string) ($task['description'] ?? ''),
            (string) ($task['owner_name'] ?? ''),
            (string) ($task['assignee_name'] ?? ''),
        ];
        return SearchQuery::matches($q, $fields, $tags, static fn (string $raw) => self::normalizeTagName($raw));
    }

    /** Personal board rules (owner / assignee / share). Does not include task-viewer override. */
    public static function canViewTaskPersonal(array $task, ?array $user, array $userGroupIds): bool
    {
        if (!$user) {
            return false;
        }
        $uid = (int) $user['id'];
        if ((int) ($task['owner_id'] ?? 0) === $uid) {
            return true;
        }
        $assignee = $task['assignee_id'] ?? null;
        if ($assignee !== null && (int) $assignee === $uid) {
            return true;
        }
        if (($task['visibility'] ?? '') === 'share') {
            $groups = $task['group_ids'] ?? [];
            if (!is_array($groups)) {
                $groups = [];
            }
            foreach ($groups as $gid) {
                if (in_array((int) $gid, $userGroupIds, true)) {
                    return true;
                }
            }
        }
        return false;
    }

    public static function canViewTask(array $task, ?array $user, array $userGroupIds): bool
    {
        if (!$user) {
            return false;
        }
        if (self::canViewAllTasks($user)) {
            return true;
        }
        return self::canViewTaskPersonal($task, $user, $userGroupIds);
    }

    public static function canEditTask(array $task, ?array $user): bool
    {
        if (!$user) {
            return false;
        }
        if (($user['role'] ?? '') === 'admin') {
            return true;
        }
        return (int) ($task['owner_id'] ?? 0) === (int) $user['id'];
    }

    public static function canChangeStatus(array $task, ?array $user): bool
    {
        if (!$user) {
            return false;
        }
        if (!empty($task['archived'])) {
            return false;
        }
        if (($user['role'] ?? '') === 'admin') {
            return true;
        }
        $uid = (int) $user['id'];
        if ((int) ($task['owner_id'] ?? 0) === $uid) {
            return true;
        }
        $assignee = $task['assignee_id'] ?? null;
        return $assignee !== null && (int) $assignee === $uid;
    }

    public static function canArchive(array $task, ?array $user): bool
    {
        if (!$user) {
            return false;
        }
        if (($user['role'] ?? '') === 'admin') {
            return true;
        }
        $uid = (int) $user['id'];
        if ((int) ($task['owner_id'] ?? 0) === $uid) {
            return true;
        }
        $assignee = $task['assignee_id'] ?? null;
        return $assignee !== null && (int) $assignee === $uid;
    }

    public static function setArchived(int $taskId, bool $archived): void
    {
        if ($archived) {
            self::pdo()->prepare(
                'UPDATE tasks SET archived = 1, archived_at = datetime(\'now\'), updated_at = datetime(\'now\')
                 WHERE id = ?'
            )->execute([$taskId]);
            return;
        }
        self::pdo()->prepare(
            'UPDATE tasks SET archived = 0, archived_at = NULL, updated_at = datetime(\'now\')
             WHERE id = ?'
        )->execute([$taskId]);
    }

    public static function enrichTask(array $row, ?array $userMap = null): array
    {
        $id = (int) $row['id'];
        $row['id'] = $id;
        $row['owner_id'] = (int) $row['owner_id'];
        $assignee = $row['assignee_id'] ?? null;
        $row['assignee_id'] = $assignee !== null && $assignee !== '' ? (int) $assignee : null;
        $row['title'] = (string) ($row['title'] ?? '');
        $row['description'] = (string) ($row['description'] ?? '');
        $row['status'] = (string) ($row['status'] ?? 'todo');
        $due = $row['due_date'] ?? null;
        $row['due_date'] = $due !== null && $due !== '' ? (string) $due : null;
        $row['visibility'] = (string) ($row['visibility'] ?? 'private');
        $row['archived'] = !empty($row['archived']);
        $archivedAt = $row['archived_at'] ?? null;
        $row['archived_at'] = $archivedAt !== null && $archivedAt !== '' ? (string) $archivedAt : null;
        $row['group_ids'] = self::fetchGroupIds($id);
        $row['tags'] = self::fetchTags($id);

        if ($userMap === null) {
            $userMap = TeamCal::portalUserMap();
        }
        $row['owner_name'] = $userMap[$row['owner_id']] ?? 'Deleted user';
        $row['assignee_name'] = $row['assignee_id'] !== null
            ? ($userMap[$row['assignee_id']] ?? 'Deleted user')
            : null;

        return $row;
    }

    public static function loadTask(int $id): ?array
    {
        $stmt = self::pdo()->prepare('SELECT * FROM tasks WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }
        return self::enrichTask($row);
    }

    /**
     * @param int|null $viewUserId null = personal/default visibility only (no view-as);
     *                             0 = all tasks (viewers/admins); >0 = owner or assignee is that user
     * @return list<array>
     */
    public static function listVisibleTasks(
        array $user,
        array $userGroupIds,
        string $q = '',
        ?string $status = null,
        string $filter = '',
        bool $archivedOnly = false,
        ?int $viewUserId = null
    ): array {
        if ($archivedOnly) {
            $sql = 'SELECT * FROM tasks WHERE archived = 1 ORDER BY
                    COALESCE(archived_at, updated_at) DESC,
                    id DESC';
        } else {
            $sql = 'SELECT * FROM tasks WHERE archived = 0 ORDER BY
                    CASE status WHEN \'todo\' THEN 0 WHEN \'in_progress\' THEN 1 WHEN \'done\' THEN 2 ELSE 3 END,
                    CASE WHEN due_date IS NULL THEN 1 ELSE 0 END,
                    due_date ASC,
                    updated_at DESC,
                    id DESC';
        }
        $rows = self::pdo()->query($sql)->fetchAll();
        $userMap = TeamCal::portalUserMap();
        $q = trim($q);
        $filter = strtolower(trim($filter));
        $uid = (int) $user['id'];
        $canViewAll = self::canViewAllTasks($user);
        $out = [];
        foreach ($rows as $row) {
            $task = self::enrichTask($row, $userMap);

            // View-as mode (admin / task viewer only): all tasks or one user's owned/assigned
            if ($viewUserId !== null) {
                if (!$canViewAll) {
                    continue;
                }
                if ($viewUserId > 0) {
                    $ownerMatch = (int) $task['owner_id'] === $viewUserId;
                    $assigneeMatch = $task['assignee_id'] !== null && (int) $task['assignee_id'] === $viewUserId;
                    if (!$ownerMatch && !$assigneeMatch) {
                        continue;
                    }
                }
                // viewUserId === 0 → all tasks
            } elseif (!self::canViewTaskPersonal($task, $user, $userGroupIds)) {
                // Default "Me" board: personal visibility only (even for admin / task viewer)
                continue;
            }

            if ($status !== null && $status !== '' && $task['status'] !== $status) {
                continue;
            }
            if ($filter === 'mine') {
                if ((int) $task['owner_id'] !== $uid) {
                    continue;
                }
            } elseif ($filter === 'assigned') {
                if ($task['assignee_id'] === null || (int) $task['assignee_id'] !== $uid) {
                    continue;
                }
            }
            if ($q !== '' && !self::matchesQuery($task, $q)) {
                continue;
            }
            $task['can_edit'] = self::canEditTask($task, $user) && !$task['archived'];
            $task['can_status'] = self::canChangeStatus($task, $user);
            $task['can_archive'] = self::canArchive($task, $user);
            $out[] = $task;
        }
        return $out;
    }

    public static function countByOwner(int $userId): int
    {
        $stmt = self::pdo()->prepare('SELECT COUNT(*) FROM tasks WHERE owner_id = ?');
        $stmt->execute([$userId]);
        return (int) $stmt->fetchColumn();
    }

    public static function deleteByOwner(int $userId): int
    {
        $stmt = self::pdo()->prepare('DELETE FROM tasks WHERE owner_id = ?');
        $stmt->execute([$userId]);
        return $stmt->rowCount();
    }

    public static function reassignOwner(int $fromUserId, int $toUserId): int
    {
        if ($fromUserId <= 0 || $toUserId <= 0 || $fromUserId === $toUserId) {
            return 0;
        }
        $stmt = self::pdo()->prepare('UPDATE tasks SET owner_id = ? WHERE owner_id = ?');
        $stmt->execute([$toUserId, $fromUserId]);
        return $stmt->rowCount();
    }

    public static function clearAssignee(int $userId): int
    {
        $stmt = self::pdo()->prepare(
            'UPDATE tasks SET assignee_id = NULL, updated_at = datetime(\'now\') WHERE assignee_id = ?'
        );
        $stmt->execute([$userId]);
        return $stmt->rowCount();
    }
}
