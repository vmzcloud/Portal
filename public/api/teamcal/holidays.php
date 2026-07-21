<?php

declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/src/bootstrap.php';

$method = request_method();
TeamCalDatabase::connection();

if ($method === 'GET') {
    $user = Auth::user();
    $isAdmin = $user && $user['role'] === 'admin';
    if (!TeamCal::isEnabled() && !$isAdmin) {
        json_error('Team Calendar is disabled', 403);
    }

    $from = isset($_GET['from']) ? substr(trim((string) $_GET['from']), 0, 10) : '';
    $to = isset($_GET['to']) ? substr(trim((string) $_GET['to']), 0, 10) : '';

    if ($from !== '' && $to !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
        $map = TeamCal::holidaysInRange($from, $to);
    } else {
        $map = TeamCal::holidays();
    }

    json_ok([
        'holidays' => $map,
        'count' => count(TeamCal::holidays()),
    ]);
}

if ($method === 'POST') {
    require_csrf();
    Auth::requireAdmin();

    // Clear holidays
    if (!empty($_POST['clear']) || (isset($_GET['clear']) && $_GET['clear'] === '1')) {
        TeamCal::writeHolidays([]);
        json_ok(['holidays' => [], 'count' => 0]);
    }

    if (empty($_FILES['file']) || !is_array($_FILES['file'])) {
        json_error('Holiday ICS file is required');
    }
    $file = $_FILES['file'];
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        json_error('Upload failed');
    }
    if (($file['size'] ?? 0) > 2 * 1024 * 1024) {
        json_error('ICS file too large (max 2MB)');
    }
    $tmp = (string) ($file['tmp_name'] ?? '');
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        json_error('Invalid upload');
    }
    $raw = file_get_contents($tmp);
    if ($raw === false || trim($raw) === '') {
        json_error('Unable to read ICS file');
    }

    $map = IcsParser::parseHolidays($raw);
    if ($map === []) {
        json_error('No holiday dates found in ICS file');
    }

    try {
        $saved = TeamCal::writeHolidays($map);
    } catch (Throwable $e) {
        json_error($e->getMessage(), 500);
    }

    json_ok([
        'holidays' => $saved,
        'count' => count($saved),
    ]);
}

if ($method === 'DELETE') {
    require_csrf();
    Auth::requireAdmin();
    TeamCal::writeHolidays([]);
    json_ok(['holidays' => [], 'count' => 0]);
}

json_error('Method not allowed', 405);
