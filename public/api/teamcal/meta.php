<?php

declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/src/bootstrap.php';

$method = request_method();
TeamCalDatabase::connection();

if ($method === 'GET') {
    // Meta is available when enabled for everyone; admin can always read for settings UI
    $user = Auth::user();
    $isAdmin = $user && $user['role'] === 'admin';
    if (!TeamCal::isEnabled() && !$isAdmin) {
        json_error('Team Calendar is disabled', 403);
    }

    $data = [
        'enabled' => TeamCal::isEnabled(),
        'types' => TeamCal::eventTypes(),
        'locations' => TeamCal::locations(),
        'users' => TeamCal::activePortalUsers(),
    ];

    if ($user) {
        $pdo = Database::connection();
        if ($user['role'] === 'admin') {
            $groups = $pdo->query('SELECT id, name FROM groups ORDER BY name COLLATE NOCASE')->fetchAll();
        } else {
            $stmt = $pdo->prepare(
                'SELECT g.id, g.name FROM groups g
                 INNER JOIN group_members gm ON gm.group_id = g.id
                 WHERE gm.user_id = ?
                 ORDER BY g.name COLLATE NOCASE'
            );
            $stmt->execute([(int) $user['id']]);
            $groups = $stmt->fetchAll();
        }
        $data['groups'] = array_map(static function ($g) {
            return ['id' => (int) $g['id'], 'name' => (string) $g['name']];
        }, $groups);
    } else {
        $data['groups'] = [];
    }

    json_ok($data);
}

if ($method === 'PUT' || $method === 'PATCH' || $method === 'POST') {
    require_csrf();
    Auth::requireAdmin();
    $body = request_json();
    $result = [];

    if (array_key_exists('types', $body)) {
        if (!is_array($body['types'])) {
            json_error('types must be a JSON array of strings');
        }
        try {
            $result['types'] = TeamCal::writeJsonList('event_types.json', $body['types']);
        } catch (Throwable $e) {
            json_error($e->getMessage(), 500);
        }
    }

    if (array_key_exists('locations', $body)) {
        if (!is_array($body['locations'])) {
            json_error('locations must be a JSON array of strings');
        }
        try {
            $result['locations'] = TeamCal::writeJsonList('locations.json', $body['locations']);
        } catch (Throwable $e) {
            json_error($e->getMessage(), 500);
        }
    }

    if ($result === []) {
        json_error('Provide types and/or locations arrays');
    }

    $result['enabled'] = TeamCal::isEnabled();
    if (!isset($result['types'])) {
        $result['types'] = TeamCal::eventTypes();
    }
    if (!isset($result['locations'])) {
        $result['locations'] = TeamCal::locations();
    }
    json_ok($result);
}

json_error('Method not allowed', 405);
