<?php

declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/src/bootstrap.php';

$method = request_method();

if ($method === 'GET') {
    TeamCalDatabase::connection();
    $data = ['enabled' => TeamCal::isEnabled()];
    // Admins always get types/locations for the settings UI (even when disabled)
    if (Auth::isAdmin()) {
        $data['types'] = TeamCal::eventTypes();
        $data['locations'] = TeamCal::locations();
    }
    json_ok($data);
}

if ($method === 'PUT' || $method === 'PATCH' || $method === 'POST') {
    require_csrf();
    Auth::requireAdmin();
    TeamCalDatabase::connection();
    $body = request_json();
    if (!array_key_exists('enabled', $body)) {
        json_error('enabled is required');
    }
    $enabled = !empty($body['enabled']) && $body['enabled'] !== '0' && $body['enabled'] !== false;
    TeamCal::setSetting('enabled', $enabled ? '1' : '0');
    json_ok(['enabled' => $enabled]);
}

json_error('Method not allowed', 405);
