<?php

declare(strict_types=1);

final class Notifications
{
    public static function pdo(): PDO
    {
        return Database::connection();
    }

    /**
     * @return int New notification id
     */
    public static function create(
        int $userId,
        string $type,
        string $title,
        string $body = '',
        ?string $linkUrl = null,
        ?string $refType = null,
        ?int $refId = null,
        ?int $actorId = null
    ): int {
        if ($userId <= 0) {
            return 0;
        }
        $stmt = self::pdo()->prepare(
            'INSERT INTO notifications
             (user_id, type, title, body, link_url, ref_type, ref_id, actor_id, is_read, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0, datetime(\'now\'))'
        );
        $stmt->execute([
            $userId,
            $type,
            mb_substr(trim($title), 0, 200),
            mb_substr(trim($body), 0, 1000),
            $linkUrl,
            $refType,
            $refId,
            $actorId !== null && $actorId > 0 ? $actorId : null,
        ]);
        return (int) self::pdo()->lastInsertId();
    }

    /**
     * @param list<int> $userIds
     * @return int Number created
     */
    public static function notifyMany(
        array $userIds,
        string $type,
        string $title,
        string $body = '',
        ?string $linkUrl = null,
        ?string $refType = null,
        ?int $refId = null,
        ?int $actorId = null
    ): int {
        $ids = [];
        foreach ($userIds as $id) {
            $n = (int) $id;
            if ($n > 0 && ($actorId === null || $n !== (int) $actorId)) {
                $ids[$n] = $n;
            }
        }
        $count = 0;
        foreach ($ids as $uid) {
            if (self::create($uid, $type, $title, $body, $linkUrl, $refType, $refId, $actorId) > 0) {
                $count++;
            }
        }
        return $count;
    }

    /** @return list<int> */
    public static function userIdsInGroups(array $groupIds): array
    {
        $gids = array_values(array_unique(array_filter(
            array_map('intval', $groupIds),
            static fn ($id) => $id > 0
        )));
        if ($gids === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($gids), '?'));
        $stmt = self::pdo()->prepare(
            "SELECT DISTINCT user_id FROM group_members WHERE group_id IN ($placeholders)"
        );
        $stmt->execute($gids);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    public static function notifyTodoAssigned(
        int $assigneeId,
        int $actorId,
        string $actorName,
        int $taskId,
        string $taskTitle
    ): void {
        if ($assigneeId <= 0 || $assigneeId === $actorId) {
            return;
        }
        $title = 'Task assigned to you';
        $label = $taskTitle !== '' ? $taskTitle : 'Untitled';
        $body = $actorName . ' assigned you “' . $label . '”';
        self::create(
            $assigneeId,
            'todo_assigned',
            $title,
            $body,
            '/todo.php',
            'todo',
            $taskId,
            $actorId
        );
    }

    /**
     * Notify members of newly shared groups (or all groups on first share).
     *
     * @param list<int> $newGroupIds
     * @param list<int> $previousGroupIds
     */
    public static function notifyNoteShared(
        int $actorId,
        string $actorName,
        int $noteId,
        string $noteTitle,
        array $newGroupIds,
        array $previousGroupIds = []
    ): void {
        $prev = array_map('intval', $previousGroupIds);
        $added = [];
        foreach ($newGroupIds as $gid) {
            $g = (int) $gid;
            if ($g > 0 && !in_array($g, $prev, true)) {
                $added[] = $g;
            }
        }
        if ($added === []) {
            return;
        }
        $recipients = self::userIdsInGroups($added);
        $label = $noteTitle !== '' ? $noteTitle : 'Untitled';
        $body = $actorName . ' shared “' . $label . '” with your group';
        self::notifyMany(
            $recipients,
            'note_shared',
            'Note shared with you',
            $body,
            '/notes.php',
            'note',
            $noteId,
            $actorId
        );
    }

    public static function unreadCount(int $userId): int
    {
        if ($userId <= 0) {
            return 0;
        }
        $stmt = self::pdo()->prepare(
            'SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0'
        );
        $stmt->execute([$userId]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * @return list<array>
     */
    public static function listForUser(int $userId, int $limit = 100, bool $unreadOnly = false): array
    {
        if ($userId <= 0) {
            return [];
        }
        $limit = max(1, min(200, $limit));
        $sql = 'SELECT * FROM notifications WHERE user_id = ?';
        if ($unreadOnly) {
            $sql .= ' AND is_read = 0';
        }
        $sql .= ' ORDER BY created_at DESC, id DESC LIMIT ' . $limit;
        $stmt = self::pdo()->prepare($sql);
        $stmt->execute([$userId]);
        $rows = $stmt->fetchAll();
        $userMap = TeamCal::portalUserMap();
        $out = [];
        foreach ($rows as $row) {
            $out[] = self::enrich($row, $userMap);
        }
        return $out;
    }

    public static function setRead(int $id, int $userId, bool $isRead): bool
    {
        if ($id <= 0 || $userId <= 0) {
            return false;
        }
        $check = self::pdo()->prepare(
            'SELECT id FROM notifications WHERE id = ? AND user_id = ?'
        );
        $check->execute([$id, $userId]);
        if (!$check->fetchColumn()) {
            return false;
        }
        self::pdo()->prepare(
            'UPDATE notifications SET is_read = ? WHERE id = ? AND user_id = ?'
        )->execute([$isRead ? 1 : 0, $id, $userId]);
        return true;
    }

    public static function markRead(int $id, int $userId): bool
    {
        return self::setRead($id, $userId, true);
    }

    public static function markAllRead(int $userId): int
    {
        if ($userId <= 0) {
            return 0;
        }
        $stmt = self::pdo()->prepare(
            'UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0'
        );
        $stmt->execute([$userId]);
        return $stmt->rowCount();
    }

    public static function deleteForUser(int $id, int $userId): bool
    {
        if ($id <= 0 || $userId <= 0) {
            return false;
        }
        $stmt = self::pdo()->prepare(
            'DELETE FROM notifications WHERE id = ? AND user_id = ?'
        );
        $stmt->execute([$id, $userId]);
        return $stmt->rowCount() > 0;
    }

    public static function deleteAllForUser(int $userId): int
    {
        if ($userId <= 0) {
            return 0;
        }
        $stmt = self::pdo()->prepare('DELETE FROM notifications WHERE user_id = ?');
        $stmt->execute([$userId]);
        return $stmt->rowCount();
    }

    public static function existsForRef(
        int $userId,
        string $type,
        string $refType,
        int $refId
    ): bool {
        if ($userId <= 0 || $refId <= 0) {
            return false;
        }
        $stmt = self::pdo()->prepare(
            'SELECT 1 FROM notifications
             WHERE user_id = ? AND type = ? AND ref_type = ? AND ref_id = ?
             LIMIT 1'
        );
        $stmt->execute([$userId, $type, $refType, $refId]);
        return (bool) $stmt->fetchColumn();
    }

    /**
     * Create day-before reminders for calendar events starting tomorrow.
     * Safe to call often (deduped per user + event).
     *
     * @return int Number of notifications created
     */
    public static function dispatchEventReminders(): int
    {
        try {
            TeamCalDatabase::connection();
            if (!TeamCal::isEnabled()) {
                return 0;
            }
        } catch (Throwable) {
            return 0;
        }

        $tomorrow = (new DateTimeImmutable('tomorrow'))->format('Y-m-d');
        $dayStart = $tomorrow . ' 00:00:00';
        $dayEnd = $tomorrow . ' 23:59:59';

        $pdo = TeamCal::pdo();
        $stmt = $pdo->prepare(
            'SELECT * FROM events
             WHERE notify_day_before = 1
               AND starts_at >= ?
               AND starts_at <= ?
             ORDER BY starts_at ASC, id ASC'
        );
        $stmt->execute([$dayStart, $dayEnd]);
        $rows = $stmt->fetchAll();
        if ($rows === []) {
            return 0;
        }

        $created = 0;
        foreach ($rows as $row) {
            $event = TeamCal::enrichEvent($row);
            $eventId = (int) $event['id'];
            $title = (string) ($event['title'] ?? 'Event');
            $startsAt = (string) ($event['starts_at'] ?? '');
            $when = self::formatEventWhen($event);

            $recipients = [];
            foreach ($event['person_ids'] ?? [] as $uid) {
                $n = (int) $uid;
                if ($n > 0) {
                    $recipients[$n] = $n;
                }
            }
            $ownerId = $event['owner_id'] !== null ? (int) $event['owner_id'] : 0;
            if ($ownerId > 0) {
                $recipients[$ownerId] = $ownerId;
            }

            $body = '“' . $title . '” starts ' . $when;
            foreach ($recipients as $uid) {
                if (self::existsForRef($uid, 'event_day_before', 'event', $eventId)) {
                    continue;
                }
                if (self::create(
                    $uid,
                    'event_day_before',
                    'Event tomorrow',
                    $body,
                    '/calendar.php',
                    'event',
                    $eventId,
                    $ownerId > 0 ? $ownerId : null
                ) > 0) {
                    $created++;
                }
            }
        }
        return $created;
    }

    private static function formatEventWhen(array $event): string
    {
        $starts = (string) ($event['starts_at'] ?? '');
        if ($starts === '') {
            return 'tomorrow';
        }
        $date = substr($starts, 0, 10);
        $allDay = !empty($event['all_day']);
        $period = (string) ($event['period'] ?? 'none');
        if ($allDay) {
            return $date . ' (all day)';
        }
        if ($period === 'am') {
            return $date . ' (AM)';
        }
        if ($period === 'pm') {
            return $date . ' (PM)';
        }
        $time = strlen($starts) >= 16 ? substr($starts, 11, 5) : '';
        return $time !== '' ? $date . ' ' . $time : $date;
    }

    /**
     * @param array<int,string>|null $userMap
     */
    public static function enrich(array $row, ?array $userMap = null): array
    {
        if ($userMap === null) {
            $userMap = TeamCal::portalUserMap();
        }
        $actorId = $row['actor_id'] !== null && $row['actor_id'] !== '' ? (int) $row['actor_id'] : null;
        return [
            'id' => (int) $row['id'],
            'user_id' => (int) $row['user_id'],
            'type' => (string) ($row['type'] ?? ''),
            'title' => (string) ($row['title'] ?? ''),
            'body' => (string) ($row['body'] ?? ''),
            'link_url' => $row['link_url'] !== null && $row['link_url'] !== ''
                ? (string) $row['link_url']
                : null,
            'ref_type' => $row['ref_type'] !== null && $row['ref_type'] !== ''
                ? (string) $row['ref_type']
                : null,
            'ref_id' => $row['ref_id'] !== null && $row['ref_id'] !== ''
                ? (int) $row['ref_id']
                : null,
            'actor_id' => $actorId,
            'actor_name' => $actorId !== null
                ? ($userMap[$actorId] ?? 'Deleted user')
                : null,
            'is_read' => !empty($row['is_read']),
            'created_at' => (string) ($row['created_at'] ?? ''),
        ];
    }
}
