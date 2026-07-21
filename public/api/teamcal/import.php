<?php

declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/src/bootstrap.php';

if (request_method() !== 'POST') {
    json_error('Method not allowed', 405);
}

require_csrf();
$admin = Auth::requireAdmin();
TeamCal::requireEnabled();
TeamCalDatabase::connection();

if (empty($_FILES['file']) || !is_array($_FILES['file'])) {
    json_error('ICS file is required');
}

$file = $_FILES['file'];
if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    json_error('Upload failed');
}
if (($file['size'] ?? 0) > 2 * 1024 * 1024) {
    json_error('ICS file too large (max 2MB)');
}

$name = (string) ($file['name'] ?? '');
$tmp = (string) ($file['tmp_name'] ?? '');
if ($tmp === '' || !is_uploaded_file($tmp)) {
    json_error('Invalid upload');
}
$ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
if ($ext !== 'ics' && $ext !== 'ical') {
    // allow missing/odd extension if content looks like ics
    $sniff = (string) file_get_contents($tmp, false, null, 0, 64);
    if (!str_contains($sniff, 'BEGIN:VCALENDAR') && !str_contains($sniff, 'BEGIN:VEVENT')) {
        json_error('File must be an .ics calendar');
    }
}

$raw = file_get_contents($tmp);
if ($raw === false || trim($raw) === '') {
    json_error('Unable to read ICS file');
}

$parsed = IcsParser::parseEvents($raw);
if ($parsed === []) {
    json_error('No events found in ICS file');
}

$pdo = TeamCal::pdo();
$ownerId = (int) $admin['id'];
$imported = 0;
$skipped = 0;
$errors = [];

$ins = $pdo->prepare(
    'INSERT INTO events
     (title, type, description, location, color, starts_at, ends_at, all_day, period, visibility, owner_id, ics_uid, updated_at)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, datetime(\'now\'))'
);

foreach ($parsed as $i => $ev) {
    try {
        $uid = trim((string) ($ev['uid'] ?? ''));
        if ($uid !== '' && TeamCal::findEventIdByIcsUid($uid) !== null) {
            $skipped++;
            continue;
        }
        $title = trim((string) $ev['title']);
        if ($title === '') {
            $title = '(No title)';
        }
        $allDay = !empty($ev['all_day']) ? 1 : 0;
        $startsAt = (string) $ev['starts_at'];
        $endsAt = (string) $ev['ends_at'];
        if ($allDay === 1) {
            $startDay = substr($startsAt, 0, 10);
            $endDay = substr($endsAt, 0, 10);
            [$startsAt, $endsAt] = TeamCal::applyPeriodTimes($startDay, $endDay, 'all_day');
        }
        $ins->execute([
            $title,
            'Imported',
            (string) ($ev['description'] ?? ''),
            (string) ($ev['location'] ?? ''),
            '#4fc3f7',
            $startsAt,
            $endsAt,
            $allDay,
            'none',
            'public',
            $ownerId,
            $uid !== '' ? $uid : null,
        ]);
        $imported++;
    } catch (Throwable $e) {
        $errors[] = 'Event #' . ($i + 1) . ': ' . $e->getMessage();
    }
}

json_ok([
    'imported' => $imported,
    'skipped' => $skipped,
    'errors' => $errors,
    'total_parsed' => count($parsed),
]);
