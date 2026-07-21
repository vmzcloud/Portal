<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/bootstrap.php';

$user = Auth::user();
if (!$user) {
    header('Location: /login.php');
    exit;
}

$forced = Auth::mustChangePassword();
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Auth::verifyCsrf($_POST['csrf_token'] ?? null)) {
        $error = 'CSRF check failed. Please refresh the page.';
    } else {
        $current = (string) ($_POST['current_password'] ?? '');
        $new = (string) ($_POST['new_password'] ?? '');
        $confirm = (string) ($_POST['confirm_password'] ?? '');

        if ($current === '' || $new === '') {
            $error = 'Current and new password are required.';
        } elseif (mb_strlen($new) < 6) {
            $error = 'New password must be at least 6 characters.';
        } elseif ($new !== $confirm) {
            $error = 'New password confirmation does not match.';
        } elseif ($current === $new) {
            $error = 'New password must be different from the current password.';
        } else {
            $pdo = Database::connection();
            $stmt = $pdo->prepare('SELECT password_hash FROM users WHERE id = ?');
            $stmt->execute([(int) $user['id']]);
            $row = $stmt->fetch();
            if (!$row || !password_verify($current, $row['password_hash'])) {
                $error = 'Current password is incorrect.';
            } else {
                $pdo->prepare(
                    'UPDATE users SET password_hash = ?, must_change_password = 0 WHERE id = ?'
                )->execute([password_hash($new, PASSWORD_DEFAULT), (int) $user['id']]);
                header('Location: /');
                exit;
            }
        }
    }
}

$csrf = Auth::csrfToken();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Change password · Portal</title>
  <link rel="stylesheet" href="<?= e(asset_url('assets/css/style.css')) ?>">
</head>
<body>
  <div class="auth-page">
    <div class="auth-card">
      <h1>Change password</h1>
      <?php if ($forced): ?>
        <p>An administrator requires you to set a new password before continuing.</p>
      <?php else: ?>
        <p>Update your account password.</p>
      <?php endif; ?>
      <?php if ($error !== ''): ?>
        <div class="form-error"><?= e($error) ?></div>
      <?php endif; ?>
      <form method="post" action="/change-password.php" autocomplete="on">
        <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
        <div class="form-group">
          <label for="current_password">Current password</label>
          <input class="form-control" id="current_password" name="current_password" type="password" required autocomplete="current-password">
        </div>
        <div class="form-group">
          <label for="new_password">New password</label>
          <input class="form-control" id="new_password" name="new_password" type="password" required minlength="6" autocomplete="new-password">
        </div>
        <div class="form-group">
          <label for="confirm_password">Confirm new password</label>
          <input class="form-control" id="confirm_password" name="confirm_password" type="password" required minlength="6" autocomplete="new-password">
        </div>
        <p class="form-hint">Minimum 6 characters</p>
        <div class="form-actions">
          <?php if (!$forced): ?>
            <a class="btn btn-ghost" href="/">Cancel</a>
          <?php endif; ?>
          <button class="btn btn-primary" type="submit">Update password</button>
        </div>
      </form>
      <?php if ($forced): ?>
        <form method="post" action="/logout.php" style="margin-top:12px">
          <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
          <button class="btn btn-ghost" type="submit" style="width:100%">Logout</button>
        </form>
      <?php endif; ?>
    </div>
  </div>
</body>
</html>
