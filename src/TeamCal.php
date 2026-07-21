<?php

declare(strict_types=1);

final class TeamCal
{
    public static function pdo(): PDO
    {
        return TeamCalDatabase::connection();
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
            json_error('Team Calendar is disabled', 403);
        }
    }

    public static function readJsonList(string $filename): array
    {
        $path = TeamCalDatabase::configDir() . '/' . $filename;
        if (!is_file($path)) {
            return [];
        }
        $raw = file_get_contents($path);
        if ($raw === false || trim($raw) === '') {
            return [];
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return [];
        }
        $out = [];
        foreach ($data as $item) {
            if (is_string($item)) {
                $item = trim($item);
                if ($item !== '') {
                    $out[] = $item;
                }
            }
        }
        return array_values(array_unique($out));
    }

    public static function writeJsonList(string $filename, array $items): array
    {
        $out = [];
        foreach ($items as $item) {
            if (!is_string($item) && !is_numeric($item)) {
                continue;
            }
            $s = trim((string) $item);
            if ($s !== '') {
                $out[] = $s;
            }
        }
        $out = array_values(array_unique($out));
        $path = TeamCalDatabase::configDir() . '/' . $filename;
        $json = json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false || file_put_contents($path, $json . "\n") === false) {
            throw new RuntimeException('Failed to write ' . $filename);
        }
        return $out;
    }

    public static function eventTypes(): array
    {
        return self::readJsonList('event_types.json');
    }

    public static function locations(): array
    {
        return self::readJsonList('locations.json');
    }

    public static function canViewEvent(array $event, ?array $user, array $userGroupIds): bool
    {
        $visibility = $event['visibility'] ?? 'public';
        if ($visibility === 'public') {
            return true;
        }
        if (!$user) {
            return false;
        }
        if ($user['role'] === 'admin') {
            return true;
        }
        $ownerId = $event['owner_id'] ?? null;
        if ($ownerId !== null && (int) $ownerId === (int) $user['id']) {
            return true;
        }
        if ($visibility === 'share') {
            $groups = $event['group_ids'] ?? [];
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

    public static function canEditEvent(array $event, ?array $user): bool
    {
        if (!$user) {
            return false;
        }
        if ($user['role'] === 'admin') {
            return true;
        }
        $ownerId = $event['owner_id'] ?? null;
        return $ownerId !== null && (int) $ownerId === (int) $user['id'];
    }

    public static function fetchPersonIds(int $eventId): array
    {
        $stmt = self::pdo()->prepare('SELECT user_id FROM event_persons WHERE event_id = ?');
        $stmt->execute([$eventId]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    public static function fetchGroupIds(int $eventId): array
    {
        $stmt = self::pdo()->prepare('SELECT group_id FROM event_groups WHERE event_id = ?');
        $stmt->execute([$eventId]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    public static function syncPersons(int $eventId, array $userIds): void
    {
        $pdo = self::pdo();
        $pdo->prepare('DELETE FROM event_persons WHERE event_id = ?')->execute([$eventId]);
        $ins = $pdo->prepare('INSERT INTO event_persons (event_id, user_id) VALUES (?, ?)');
        foreach (array_unique(array_map('intval', $userIds)) as $uid) {
            if ($uid > 0) {
                $ins->execute([$eventId, $uid]);
            }
        }
    }

    public static function syncGroups(int $eventId, array $groupIds): void
    {
        $pdo = self::pdo();
        $pdo->prepare('DELETE FROM event_groups WHERE event_id = ?')->execute([$eventId]);
        $ins = $pdo->prepare('INSERT INTO event_groups (event_id, group_id) VALUES (?, ?)');
        foreach (array_unique(array_map('intval', $groupIds)) as $gid) {
            if ($gid > 0) {
                $ins->execute([$eventId, $gid]);
            }
        }
    }

    public static function loadEvent(int $id): ?array
    {
        $stmt = self::pdo()->prepare('SELECT * FROM events WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }
        return self::enrichEvent($row);
    }

    public static function enrichEvent(array $row, ?array $userMap = null): array
    {
        $id = (int) $row['id'];
        $row['id'] = $id;
        $row['all_day'] = (int) $row['all_day'];
        $row['owner_id'] = $row['owner_id'] !== null ? (int) $row['owner_id'] : null;
        $row['person_ids'] = self::fetchPersonIds($id);
        $row['group_ids'] = self::fetchGroupIds($id);

        if ($userMap === null) {
            $userMap = self::portalUserMap();
        }
        $persons = [];
        foreach ($row['person_ids'] as $uid) {
            if (isset($userMap[$uid])) {
                $persons[] = ['id' => $uid, 'username' => $userMap[$uid]];
            } else {
                $persons[] = ['id' => $uid, 'username' => 'user#' . $uid];
            }
        }
        $row['persons'] = $persons;
        $row['owner_name'] = $row['owner_id'] !== null
            ? ($userMap[$row['owner_id']] ?? ('user#' . $row['owner_id']))
            : null;

        return $row;
    }

    /** @return array<int, string> id => username */
    public static function portalUserMap(): array
    {
        $pdo = Database::connection();
        $rows = $pdo->query('SELECT id, username FROM users ORDER BY username COLLATE NOCASE')->fetchAll();
        $map = [];
        foreach ($rows as $r) {
            $map[(int) $r['id']] = (string) $r['username'];
        }
        return $map;
    }

    /** @return list<array{id:int,username:string}> */
    public static function activePortalUsers(): array
    {
        $pdo = Database::connection();
        $rows = $pdo->query(
            'SELECT id, username FROM users WHERE is_active = 1 ORDER BY username COLLATE NOCASE'
        )->fetchAll();
        $out = [];
        foreach ($rows as $r) {
            $out[] = ['id' => (int) $r['id'], 'username' => (string) $r['username']];
        }
        return $out;
    }

    public static function normalizeDatetime(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        // Accept "YYYY-MM-DD" or "YYYY-MM-DDTHH:MM" or "YYYY-MM-DD HH:MM:SS"
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return $value . ' 00:00:00';
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}$/', $value)) {
            return str_replace('T', ' ', $value) . ':00';
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}:\d{2}$/', $value)) {
            return str_replace('T', ' ', $value);
        }
        $ts = strtotime($value);
        if ($ts === false) {
            return null;
        }
        return date('Y-m-d H:i:s', $ts);
    }
}
