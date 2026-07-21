<?php

declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/src/bootstrap.php';

$method = request_method();
TeamCalDatabase::connection();
$pdo = TeamCal::pdo();

function teamcal_validate_event_payload(array $body, bool $isGuest): array
{
    $title = trim((string) ($body['title'] ?? ''));
    if ($title === '') {
        json_error('Title is required');
    }

    $type = trim((string) ($body['type'] ?? ''));
    $description = trim((string) ($body['description'] ?? ''));
    $location = trim((string) ($body['location'] ?? ''));
    $color = validate_hex_color((string) ($body['color'] ?? '#4fc3f7'));

    $allDay = !empty($body['all_day']) && $body['all_day'] !== '0' && $body['all_day'] !== false;
    $period = strtolower(trim((string) ($body['period'] ?? 'none')));
    if (!in_array($period, ['none', 'am', 'pm'], true)) {
        json_error('Invalid period');
    }
    if ($allDay) {
        $period = 'none';
    } elseif ($period === 'am' || $period === 'pm') {
        $allDay = false;
    }

    $startsAt = TeamCal::normalizeDatetime((string) ($body['starts_at'] ?? ''));
    $endsAt = TeamCal::normalizeDatetime((string) ($body['ends_at'] ?? ''));
    if (!$startsAt || !$endsAt) {
        json_error('Valid start and end datetime are required');
    }
    if (strcmp($endsAt, $startsAt) < 0) {
        json_error('End must be on or after start');
    }

    // Normalize half-day / all-day bounds using admin-configured ranges
    $startDay = substr($startsAt, 0, 10);
    $endDay = substr($endsAt, 0, 10);
    if ($allDay) {
        [$startsAt, $endsAt] = TeamCal::applyPeriodTimes($startDay, $endDay, 'all_day');
    } elseif ($period === 'am') {
        [$startsAt, $endsAt] = TeamCal::applyPeriodTimes($startDay, $startDay, 'am');
    } elseif ($period === 'pm') {
        [$startsAt, $endsAt] = TeamCal::applyPeriodTimes($startDay, $startDay, 'pm');
    }

    $visibility = strtolower(trim((string) ($body['visibility'] ?? 'public')));
    if (!in_array($visibility, ['public', 'share', 'private'], true)) {
        json_error('Invalid visibility');
    }
    if ($isGuest && $visibility !== 'public') {
        json_error('Guests can only create public events', 403);
    }

    $personIds = $body['person_ids'] ?? [];
    if (!is_array($personIds)) {
        $personIds = [];
    }
    $personIds = array_values(array_unique(array_filter(array_map('intval', $personIds), static fn ($id) => $id > 0)));

    $groupIds = $body['group_ids'] ?? [];
    if (!is_array($groupIds)) {
        $groupIds = [];
    }
    $groupIds = array_values(array_unique(array_filter(array_map('intval', $groupIds), static fn ($id) => $id > 0)));

    if ($visibility === 'share' && $groupIds === []) {
        json_error('Select at least one group for share visibility');
    }
    if ($visibility !== 'share') {
        $groupIds = [];
    }

    return [
        'title' => $title,
        'type' => $type,
        'description' => $description,
        'location' => $location,
        'color' => $color,
        'starts_at' => $startsAt,
        'ends_at' => $endsAt,
        'all_day' => $allDay ? 1 : 0,
        'period' => $period,
        'visibility' => $visibility,
        'person_ids' => $personIds,
        'group_ids' => $groupIds,
    ];
}

if ($method === 'GET') {
    TeamCal::requireEnabled();
    $user = Auth::user();
    $userGroupIds = $user ? Auth::userGroupIds((int) $user['id']) : [];

    $from = TeamCal::normalizeDatetime((string) ($_GET['from'] ?? ''));
    $to = TeamCal::normalizeDatetime((string) ($_GET['to'] ?? ''));
    if (!$from || !$to) {
        json_error('from and to query params are required (datetime)');
    }

    $stmt = $pdo->prepare(
        'SELECT * FROM events
         WHERE starts_at <= ? AND ends_at >= ?
         ORDER BY starts_at ASC, id ASC'
    );
    $stmt->execute([$to, $from]);
    $rows = $stmt->fetchAll();
    $userMap = TeamCal::portalUserMap();

    $result = [];
    foreach ($rows as $row) {
        $event = TeamCal::enrichEvent($row, $userMap);
        if (!TeamCal::canViewEvent($event, $user, $userGroupIds)) {
            continue;
        }
        $event['can_edit'] = TeamCal::canEditEvent($event, $user);
        $result[] = $event;
    }
    json_ok($result);
}

if ($method === 'POST' || $method === 'PUT' || $method === 'PATCH' || $method === 'DELETE') {
    require_csrf();
    TeamCal::requireEnabled();
    $body = request_json();
    $user = Auth::user();
    // Guests may create public events; mutations otherwise need a usable session when editing
    if ($method !== 'POST' && (!$user || Auth::mustChangePassword())) {
        if (!$user) {
            json_error('Authentication required', 401);
        }
        json_error('Password change required', 403);
    }
    if ($user && Auth::mustChangePassword() && $method === 'POST') {
        json_error('Password change required', 403);
    }
}

if ($method === 'POST') {
    $isGuest = !$user;
    $data = teamcal_validate_event_payload($body, $isGuest);
    $ownerId = $user ? (int) $user['id'] : null;

    $stmt = $pdo->prepare(
        'INSERT INTO events
         (title, type, description, location, color, starts_at, ends_at, all_day, period, visibility, owner_id, updated_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, datetime(\'now\'))'
    );
    $stmt->execute([
        $data['title'],
        $data['type'],
        $data['description'],
        $data['location'],
        $data['color'],
        $data['starts_at'],
        $data['ends_at'],
        $data['all_day'],
        $data['period'],
        $data['visibility'],
        $ownerId,
    ]);
    $id = (int) $pdo->lastInsertId();
    TeamCal::syncPersons($id, $data['person_ids']);
    TeamCal::syncGroups($id, $data['group_ids']);

    $event = TeamCal::loadEvent($id);
    $event['can_edit'] = TeamCal::canEditEvent($event, $user);
    json_ok($event);
}

if ($method === 'PUT' || $method === 'PATCH') {
    if (!$user) {
        json_error('Authentication required', 401);
    }
    $id = (int) ($body['id'] ?? $_GET['id'] ?? 0);
    $existing = TeamCal::loadEvent($id);
    if (!$existing) {
        json_error('Event not found', 404);
    }
    if (!TeamCal::canEditEvent($existing, $user)) {
        json_error('Permission denied', 403);
    }

    $data = teamcal_validate_event_payload($body, false);

    $stmt = $pdo->prepare(
        'UPDATE events SET
            title = ?, type = ?, description = ?, location = ?, color = ?,
            starts_at = ?, ends_at = ?, all_day = ?, period = ?, visibility = ?,
            updated_at = datetime(\'now\')
         WHERE id = ?'
    );
    $stmt->execute([
        $data['title'],
        $data['type'],
        $data['description'],
        $data['location'],
        $data['color'],
        $data['starts_at'],
        $data['ends_at'],
        $data['all_day'],
        $data['period'],
        $data['visibility'],
        $id,
    ]);
    TeamCal::syncPersons($id, $data['person_ids']);
    TeamCal::syncGroups($id, $data['group_ids']);

    $event = TeamCal::loadEvent($id);
    $event['can_edit'] = true;
    json_ok($event);
}

if ($method === 'DELETE') {
    if (!$user) {
        json_error('Authentication required', 401);
    }
    $id = (int) ($body['id'] ?? $_GET['id'] ?? 0);
    $existing = TeamCal::loadEvent($id);
    if (!$existing) {
        json_error('Event not found', 404);
    }
    if (!TeamCal::canEditEvent($existing, $user)) {
        json_error('Permission denied', 403);
    }
    $pdo->prepare('DELETE FROM events WHERE id = ?')->execute([$id]);
    json_ok(['id' => $id]);
}

json_error('Method not allowed', 405);
