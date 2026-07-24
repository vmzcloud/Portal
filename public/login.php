<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/bootstrap.php';

if (Auth::check()) {
    if (Auth::mustChangePassword()) {
        header('Location: /change-password.php');
    } else {
        header('Location: /');
    }
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Auth::verifyCsrf($_POST['csrf_token'] ?? null)) {
        $error = 'CSRF check failed. Please refresh the page.';
    } else {
        $username = trim((string) ($_POST['username'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        if ($username === '' || $password === '') {
            $error = 'Please enter username and password.';
        } else {
            $result = Auth::attempt($username, $password);
            if ($result === true) {
                if (Auth::mustChangePassword()) {
                    header('Location: /change-password.php');
                } else {
                    header('Location: /');
                }
                exit;
            }
            $error = is_string($result) ? $result : 'Invalid username or password.';
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
  <title>Login · Portal</title>
  <script>(function(){try{var t=localStorage.getItem('portal-theme');if(t!=='light'&&t!=='dark')t='dark';document.documentElement.setAttribute('data-theme',t);}catch(e){document.documentElement.setAttribute('data-theme','dark');}})();</script>
  <link rel="stylesheet" href="<?= e(asset_url('assets/css/style.css')) ?>">
</head>
<body>
  <div class="auth-theme-bar">
    <button type="button" class="btn btn-sm btn-ghost" id="themeToggle" aria-label="Toggle theme" title="Theme">☀</button>
  </div>
  <div class="auth-page">
    <form class="auth-card" method="post" action="/login.php" autocomplete="on">
      <h1>Portal Login</h1>
      <p>Sign in to manage bookmarks, icons, tabs, and categories</p>
      <?php if ($error !== ''): ?>
        <div class="form-error"><?= e($error) ?></div>
      <?php endif; ?>
      <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
      <div class="form-group">
        <label for="username">Username</label>
        <input class="form-control" id="username" name="username" required autofocus
               value="<?= e($_POST['username'] ?? '') ?>">
      </div>
      <div class="form-group">
        <label for="password">Password</label>
        <input class="form-control" id="password" name="password" type="password" required>
      </div>
      <div class="form-actions">
        <a class="btn btn-ghost" href="/">Continue as guest</a>
        <button class="btn btn-primary" type="submit">Login</button>
      </div>
      <p class="form-hint">Default admin: admin / admin123 · Demo user: demo / demo123</p>
    </form>
  </div>
  <script src="<?= e(asset_url('assets/js/theme.js')) ?>"></script>
</body>
</html>
