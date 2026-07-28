<?php

declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/src/bootstrap.php';

$method = request_method();
TodoDatabase::connection();

if ($method === 'GET') {
    $data = [
        'enabled' => Todo::isEnabled(),
    ];
    if (Auth::isAdmin()) {
        $data['task_viewer_ids'] = Todo::getTaskViewerIds();
    }
    json_ok($data);
}

if ($method === 'PUT' || $method === 'PATCH' || $method === 'POST') {
    require_csrf();
    Auth::requireAdmin();
    $body = request_json();
    $result = [];

    if (array_key_exists('enabled', $body)) {
        $enabled = !empty($body['enabled']) && $body['enabled'] !== '0' && $body['enabled'] !== false;
        Todo::setSetting('enabled', $enabled ? '1' : '0');
        $result['enabled'] = $enabled;
    }

    if (array_key_exists('task_viewer_ids', $body)) {
        if (!is_array($body['task_viewer_ids'])) {
            json_error('task_viewer_ids must be an array');
        }
        $result['task_viewer_ids'] = Todo::setTaskViewerIds($body['task_viewer_ids']);
    }

    if ($result === []) {
        json_error('Provide enabled and/or task_viewer_ids');
    }

    if (!array_key_exists('enabled', $result)) {
        $result['enabled'] = Todo::isEnabled();
    }
    if (!array_key_exists('task_viewer_ids', $result)) {
        $result['task_viewer_ids'] = Todo::getTaskViewerIds();
    }
    json_ok($result);
}

json_error('Method not allowed', 405);
