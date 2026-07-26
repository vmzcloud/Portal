<?php

declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/src/bootstrap.php';

$method = request_method();
NotesDatabase::connection();

if ($method === 'GET') {
    $user = Auth::requireUsableSession();
    Notes::requireEnabled();
    $userGroupIds = Auth::userGroupIds((int) $user['id']);

    $noteId = (int) ($_GET['note_id'] ?? 0);
    if ($noteId <= 0) {
        json_error('note_id is required');
    }

    $note = Notes::loadNote($noteId);
    if (!$note) {
        json_error('Note not found', 404);
    }
    if (!Notes::canViewNote($note, $user, $userGroupIds)) {
        json_error('Permission denied', 403);
    }

    if (isset($_GET['id'])) {
        $versionId = (int) $_GET['id'];
        $version = Notes::loadVersion($noteId, $versionId);
        if (!$version) {
            json_error('Version not found', 404);
        }
        json_ok($version);
    }

    json_ok(Notes::listVersions($noteId));
}

if (in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
    require_csrf();
    $user = Auth::requireUsableSession();
    Notes::requireEnabled();
    $body = request_json();
}

if ($method === 'POST') {
    $noteId = (int) ($body['note_id'] ?? 0);
    $versionId = (int) ($body['version_id'] ?? $body['id'] ?? 0);
    if ($noteId <= 0 || $versionId <= 0) {
        json_error('note_id and version_id are required');
    }

    $note = Notes::loadNote($noteId);
    if (!$note) {
        json_error('Note not found', 404);
    }
    if (!Notes::canEditNote($note, $user)) {
        json_error('Permission denied', 403);
    }

    $restored = Notes::restoreVersion($noteId, $versionId, (int) $user['id']);
    if (!$restored) {
        json_error('Version not found', 404);
    }
    $restored['can_edit'] = true;
    json_ok($restored);
}

json_error('Method not allowed', 405);
