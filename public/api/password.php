<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/bootstrap.php';

if (request_method() !== 'POST' && request_method() !== 'PUT') {
    json_error('Method not allowed', 405);
}

require_csrf();
$user = Auth::requireLogin();
$body = request_json();

$current = (string) ($body['current_password'] ?? '');
$new = (string) ($body['new_password'] ?? '');
$confirm = (string) ($body['confirm_password'] ?? $body['new_password_confirm'] ?? '');

if ($current === '' || $new === '') {
    json_error('Current and new password are required');
}
if (mb_strlen($new) < 6) {
    json_error('New password must be at least 6 characters');
}
if ($confirm !== '' && $new !== $confirm) {
    json_error('New password confirmation does not match');
}
if ($current === $new) {
    json_error('New password must be different from the current password');
}

$pdo = Database::connection();
$stmt = $pdo->prepare('SELECT password_hash FROM users WHERE id = ?');
$stmt->execute([(int) $user['id']]);
$row = $stmt->fetch();
if (!$row || !password_verify($current, $row['password_hash'])) {
    json_error('Current password is incorrect', 403);
}

$pdo->prepare(
    'UPDATE users SET password_hash = ?, must_change_password = 0 WHERE id = ?'
)->execute([password_hash($new, PASSWORD_DEFAULT), (int) $user['id']]);

json_ok(['message' => 'Password updated', 'must_change_password' => 0]);
