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

    /** @return array{all_day: array{start: string, end: string}, am: array{start: string, end: string}, pm: array{start: string, end: string}} */
    public static function defaultPeriodRanges(): array
    {
        return [
            'all_day' => ['start' => '09:00', 'end' => '18:00'],
            'am' => ['start' => '09:00', 'end' => '13:00'],
            'pm' => ['start' => '14:00', 'end' => '18:00'],
        ];
    }

    /** @return array{all_day: array{start: string, end: string}, am: array{start: string, end: string}, pm: array{start: string, end: string}} */
    public static function periodRanges(): array
    {
        $defaults = self::defaultPeriodRanges();
        $raw = self::getSetting('period_ranges', '');
        if ($raw === '') {
            return $defaults;
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return $defaults;
        }
        $out = $defaults;
        foreach (['all_day', 'am', 'pm'] as $key) {
            if (!isset($data[$key]) || !is_array($data[$key])) {
                continue;
            }
            $start = self::normalizeTimeHm((string) ($data[$key]['start'] ?? ''));
            $end = self::normalizeTimeHm((string) ($data[$key]['end'] ?? ''));
            if ($start !== null && $end !== null && strcmp($start, $end) < 0) {
                $out[$key] = ['start' => $start, 'end' => $end];
            }
        }
        return $out;
    }

    /**
     * @param array<string, mixed> $ranges
     * @return array{all_day: array{start: string, end: string}, am: array{start: string, end: string}, pm: array{start: string, end: string}}
     */
    public static function setPeriodRanges(array $ranges): array
    {
        $normalized = self::defaultPeriodRanges();
        foreach (['all_day', 'am', 'pm'] as $key) {
            if (!isset($ranges[$key]) || !is_array($ranges[$key])) {
                throw new InvalidArgumentException("Missing range for {$key}");
            }
            $start = self::normalizeTimeHm((string) ($ranges[$key]['start'] ?? ''));
            $end = self::normalizeTimeHm((string) ($ranges[$key]['end'] ?? ''));
            if ($start === null || $end === null) {
                throw new InvalidArgumentException("Invalid time for {$key} (use HH:MM)");
            }
            if (strcmp($start, $end) >= 0) {
                throw new InvalidArgumentException("Start must be before end for {$key}");
            }
            $normalized[$key] = ['start' => $start, 'end' => $end];
        }
        $json = json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new RuntimeException('Failed to encode period ranges');
        }
        self::setSetting('period_ranges', $json);
        return $normalized;
    }

    public static function normalizeTimeHm(string $value): ?string
    {
        $value = trim($value);
        if (preg_match('/^(\d{1,2}):(\d{2})(?::\d{2})?$/', $value, $m)) {
            $h = (int) $m[1];
            $min = (int) $m[2];
            if ($h < 0 || $h > 23 || $min < 0 || $min > 59) {
                return null;
            }
            return sprintf('%02d:%02d', $h, $min);
        }
        return null;
    }

    /**
     * Build starts_at / ends_at for all_day, am, or pm using configured ranges.
     *
     * @return array{0: string, 1: string} [starts_at, ends_at]
     */
    public static function applyPeriodTimes(string $startDay, string $endDay, string $mode): array
    {
        $startDay = substr($startDay, 0, 10);
        $endDay = substr($endDay, 0, 10);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDay)) {
            throw new InvalidArgumentException('Invalid start day');
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDay)) {
            $endDay = $startDay;
        }
        if (strcmp($endDay, $startDay) < 0) {
            $endDay = $startDay;
        }

        $ranges = self::periodRanges();
        $key = $mode === 'all_day' ? 'all_day' : ($mode === 'am' || $mode === 'pm' ? $mode : null);
        if ($key === null) {
            throw new InvalidArgumentException('Invalid period mode');
        }
        $startHm = $ranges[$key]['start'];
        $endHm = $ranges[$key]['end'];
        // am/pm are single-day; all_day may span end day
        if ($key === 'am' || $key === 'pm') {
            $endDay = $startDay;
        }
        return [
            $startDay . ' ' . $startHm . ':00',
            $endDay . ' ' . $endHm . ':00',
        ];
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

    /** @return array<string, string> Y-m-d => name */
    public static function holidays(): array
    {
        $path = TeamCalDatabase::configDir() . '/holidays.json';
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
        foreach ($data as $date => $name) {
            $d = is_string($date) ? trim($date) : '';
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) {
                continue;
            }
            $n = is_string($name) ? trim($name) : (is_numeric($name) ? (string) $name : '');
            $out[$d] = $n !== '' ? $n : 'Holiday';
        }
        ksort($out);
        return $out;
    }

    /** @param array<string, string> $map */
    public static function writeHolidays(array $map): array
    {
        $out = [];
        foreach ($map as $date => $name) {
            $d = is_string($date) ? trim($date) : '';
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) {
                continue;
            }
            $n = is_string($name) ? trim($name) : '';
            $out[$d] = $n !== '' ? $n : 'Holiday';
        }
        ksort($out);
        $path = TeamCalDatabase::configDir() . '/holidays.json';
        $json = json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false || file_put_contents($path, $json . "\n") === false) {
            throw new RuntimeException('Failed to write holidays.json');
        }
        return $out;
    }

    /** @return array<string, string> */
    public static function holidaysInRange(string $fromDate, string $toDate): array
    {
        $fromDate = substr($fromDate, 0, 10);
        $toDate = substr($toDate, 0, 10);
        $all = self::holidays();
        $out = [];
        foreach ($all as $d => $name) {
            if (strcmp($d, $fromDate) >= 0 && strcmp($d, $toDate) <= 0) {
                $out[$d] = $name;
            }
        }
        return $out;
    }

    public static function findEventIdByIcsUid(string $uid): ?int
    {
        if ($uid === '') {
            return null;
        }
        $stmt = self::pdo()->prepare('SELECT id FROM events WHERE ics_uid = ? LIMIT 1');
        $stmt->execute([$uid]);
        $id = $stmt->fetchColumn();
        return $id === false ? null : (int) $id;
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

    /**
     * Admin event list with filters. Returns ['events' => ..., 'truncated' => bool, 'count' => int].
     *
     * @param array{
     *   from?: string|null,
     *   to?: string|null,
     *   q?: string|null,
     *   type?: string|null,
     *   location?: string|null,
     *   visibility?: string|null,
     *   color?: string|null,
     *   time_mode?: string|null,
     *   owner_id?: int|null,
     *   person_id?: int|null,
     *   group_id?: int|null,
     *   limit?: int
     * } $filters
     * @return array{events: list<array>, truncated: bool, count: int}
     */
    public static function listEventsForAdmin(array $filters): array
    {
        $from = self::normalizeDatetime((string) ($filters['from'] ?? ''));
        $to = self::normalizeDatetime((string) ($filters['to'] ?? ''));
        if (!$from || !$to) {
            throw new InvalidArgumentException('from and to are required');
        }

        $limit = (int) ($filters['limit'] ?? 500);
        if ($limit < 1) {
            $limit = 500;
        }
        if ($limit > 1000) {
            $limit = 1000;
        }

        $where = ['e.starts_at <= ?', 'e.ends_at >= ?'];
        $params = [$to, $from];

        $q = trim((string) ($filters['q'] ?? ''));
        if ($q !== '') {
            $where[] = '(e.title LIKE ? OR e.description LIKE ? OR e.location LIKE ?)';
            $like = '%' . $q . '%';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }

        $type = trim((string) ($filters['type'] ?? ''));
        if ($type !== '') {
            $where[] = 'e.type = ?';
            $params[] = $type;
        }

        $location = trim((string) ($filters['location'] ?? ''));
        if ($location !== '') {
            $where[] = 'e.location = ?';
            $params[] = $location;
        }

        $visibility = strtolower(trim((string) ($filters['visibility'] ?? '')));
        if (in_array($visibility, ['public', 'share', 'private'], true)) {
            $where[] = 'e.visibility = ?';
            $params[] = $visibility;
        }

        $color = trim((string) ($filters['color'] ?? ''));
        if ($color !== '' && preg_match('/^#[0-9A-Fa-f]{6}$/', $color)) {
            $where[] = 'lower(e.color) = ?';
            $params[] = strtolower($color);
        }

        $timeMode = strtolower(trim((string) ($filters['time_mode'] ?? '')));
        if ($timeMode === 'all_day') {
            $where[] = 'e.all_day = 1';
        } elseif ($timeMode === 'am') {
            $where[] = "e.all_day = 0 AND e.period = 'am'";
        } elseif ($timeMode === 'pm') {
            $where[] = "e.all_day = 0 AND e.period = 'pm'";
        } elseif ($timeMode === 'timed') {
            $where[] = "e.all_day = 0 AND e.period = 'none'";
        }

        $ownerId = (int) ($filters['owner_id'] ?? 0);
        if ($ownerId > 0) {
            $where[] = 'e.owner_id = ?';
            $params[] = $ownerId;
        }

        $personId = (int) ($filters['person_id'] ?? 0);
        if ($personId > 0) {
            $where[] = 'EXISTS (SELECT 1 FROM event_persons ep WHERE ep.event_id = e.id AND ep.user_id = ?)';
            $params[] = $personId;
        }

        $groupId = (int) ($filters['group_id'] ?? 0);
        if ($groupId > 0) {
            $where[] = 'EXISTS (SELECT 1 FROM event_groups eg WHERE eg.event_id = e.id AND eg.group_id = ?)';
            $params[] = $groupId;
        }

        $sql = 'SELECT e.* FROM events e WHERE ' . implode(' AND ', $where)
            . ' ORDER BY e.starts_at ASC, e.id ASC LIMIT ' . ($limit + 1);

        $stmt = self::pdo()->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        $truncated = count($rows) > $limit;
        if ($truncated) {
            $rows = array_slice($rows, 0, $limit);
        }

        $userMap = self::portalUserMap();
        $events = [];
        foreach ($rows as $row) {
            $event = self::enrichEvent($row, $userMap);
            $event['can_edit'] = true;
            $events[] = $event;
        }

        return [
            'events' => $events,
            'truncated' => $truncated,
            'count' => count($events),
        ];
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
