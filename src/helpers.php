<?php

declare(strict_types=1);

function json_response(mixed $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function json_error(string $message, int $status = 400): never
{
    json_response(['ok' => false, 'error' => $message], $status);
}

function json_ok(mixed $data = null): never
{
    $payload = ['ok' => true];
    if ($data !== null) {
        $payload['data'] = $data;
    }
    json_response($payload);
}

function request_json(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || $raw === '') {
        return $_POST ?: [];
    }
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function request_method(): string
{
    return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
}

function require_csrf(): void
{
    $token = $_SERVER['HTTP_X_CSRF_TOKEN']
        ?? ($_POST['csrf_token'] ?? null)
        ?? (request_json()['csrf_token'] ?? null);
    if (!Auth::verifyCsrf(is_string($token) ? $token : null)) {
        json_error('CSRF validation failed', 403);
    }
}

function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function base_path(string $path = ''): string
{
    $root = dirname(__DIR__);
    return $path === '' ? $root : $root . '/' . ltrim($path, '/');
}

function uploads_dir(): string
{
    $dir = base_path('public/uploads/icons');
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    return $dir;
}

function public_url_path(string $relative): string
{
    return '/' . ltrim(str_replace('\\', '/', $relative), '/');
}

function can_view_bookmark(array $bookmark, ?array $user, array $userGroupIds): bool
{
    $visibility = $bookmark['visibility'];
    if ($visibility === 'public') {
        return true;
    }
    if (!$user) {
        return false;
    }
    if ((int) $bookmark['owner_id'] === (int) $user['id'] || $user['role'] === 'admin') {
        return true;
    }
    if ($visibility === 'share') {
        $groups = $bookmark['group_ids'] ?? [];
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

function can_edit_bookmark(array $bookmark, array $user): bool
{
    return (int) $bookmark['owner_id'] === (int) $user['id'] || $user['role'] === 'admin';
}

function can_edit_owned_row(array $row, array $user): bool
{
    if ($user['role'] === 'admin') {
        return true;
    }
    if (!empty($row['is_global'])) {
        return false;
    }
    return isset($row['owner_id']) && (int) $row['owner_id'] === (int) $user['id'];
}

function normalize_url(string $url): string
{
    $url = trim($url);
    if ($url === '') {
        return '';
    }
    if (!preg_match('#^https?://#i', $url)) {
        $url = 'https://' . $url;
    }
    return $url;
}

function validate_hex_color(string $color): string
{
    $color = trim($color);
    if (!preg_match('/^#[0-9A-Fa-f]{6}$/', $color)) {
        return '#4fc3f7';
    }
    return strtolower($color);
}

function letter_avatar_data_uri(string $title): string
{
    $letter = mb_strtoupper(mb_substr($title, 0, 1, 'UTF-8'), 'UTF-8');
    $colors = ['#4fc3f7', '#ab47bc', '#ef5350', '#66bb6a', '#ffa726', '#26c6da', '#42a5f5', '#ec407a'];
    $idx = abs(crc32($title)) % count($colors);
    $bg = $colors[$idx];
    $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="64" height="64">'
        . '<rect width="64" height="64" rx="12" fill="' . $bg . '"/>'
        . '<text x="50%" y="54%" dominant-baseline="middle" text-anchor="middle" '
        . 'fill="#fff" font-family="sans-serif" font-size="28" font-weight="700">'
        . htmlspecialchars($letter, ENT_XML1) . '</text></svg>';
    return 'data:image/svg+xml;base64,' . base64_encode($svg);
}

function bookmark_icon_src(?string $iconPath, string $title): string
{
    if ($iconPath && is_file(base_path('public/' . ltrim($iconPath, '/')))) {
        return public_url_path($iconPath);
    }
    return letter_avatar_data_uri($title);
}

function delete_icon_file(?string $iconPath): void
{
    if (!$iconPath) {
        return;
    }
    $full = base_path('public/' . ltrim(str_replace(['..', '\\'], '', $iconPath), '/'));
    $uploads = realpath(uploads_dir());
    $real = realpath($full);
    if ($uploads && $real && str_starts_with($real, $uploads) && is_file($real)) {
        @unlink($real);
    }
}
