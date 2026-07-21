<?php

declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/src/bootstrap.php';

$method = request_method();

if ($method === 'GET') {
    TeamCalDatabase::connection();
    $data = [
        'enabled' => TeamCal::isEnabled(),
        'period_ranges' => TeamCal::periodRanges(),
    ];
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
    $result = [];

    if (array_key_exists('enabled', $body)) {
        $enabled = !empty($body['enabled']) && $body['enabled'] !== '0' && $body['enabled'] !== false;
        TeamCal::setSetting('enabled', $enabled ? '1' : '0');
        $result['enabled'] = $enabled;
    }

    if (array_key_exists('period_ranges', $body)) {
        if (!is_array($body['period_ranges'])) {
            json_error('period_ranges must be an object');
        }
        try {
            $result['period_ranges'] = TeamCal::setPeriodRanges($body['period_ranges']);
        } catch (InvalidArgumentException $e) {
            json_error($e->getMessage());
        } catch (Throwable $e) {
            json_error($e->getMessage(), 500);
        }
    }

    if ($result === []) {
        json_error('Provide enabled and/or period_ranges');
    }

    if (!array_key_exists('enabled', $result)) {
        $result['enabled'] = TeamCal::isEnabled();
    }
    if (!array_key_exists('period_ranges', $result)) {
        $result['period_ranges'] = TeamCal::periodRanges();
    }
    json_ok($result);
}

json_error('Method not allowed', 405);
