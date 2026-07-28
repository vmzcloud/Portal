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

    public static function canViewTask(array $task, ?array $user, array $userGroupIds): bool
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
     * @return list<array>
     */
    public static function listVisibleTasks(
        array $user,
        array $userGroupIds,
        string $q = '',
        ?string $status = null,
        string $filter = '',
        bool $archivedOnly = false
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
        $q = mb_strtolower(trim($q));
        $filter = strtolower(trim($filter));
        $uid = (int) $user['id'];
        $out = [];
        foreach ($rows as $row) {
            $task = self::enrichTask($row, $userMap);
            if (!self::canViewTask($task, $user, $userGroupIds)) {
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
            if ($q !== '') {
                $hay = mb_strtolower(
                    $task['title'] . ' ' . $task['description'] . ' '
                    . ($task['owner_name'] ?? '') . ' ' . ($task['assignee_name'] ?? '')
                );
                if (!str_contains($hay, $q)) {
                    continue;
                }
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
