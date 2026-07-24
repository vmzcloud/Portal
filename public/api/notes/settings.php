<?php

declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/src/bootstrap.php';

$method = request_method();
NotesDatabase::connection();

if ($method === 'GET') {
    json_ok([
        'enabled' => Notes::isEnabled(),
    ]);
}

if ($method === 'PUT' || $method === 'PATCH' || $method === 'POST') {
    require_csrf();
    Auth::requireAdmin();
    $body = request_json();

    if (!array_key_exists('enabled', $body)) {
        json_error('Provide enabled');
    }

    $enabled = !empty($body['enabled']) && $body['enabled'] !== '0' && $body['enabled'] !== false;
    Notes::setSetting('enabled', $enabled ? '1' : '0');
    json_ok(['enabled' => $enabled]);
}

json_error('Method not allowed', 405);
