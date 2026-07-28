<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/bootstrap.php';

$user = Auth::user();
if (!$user) {
    header('Location: /login.php');
    exit;
}
if (Auth::mustChangePassword()) {
    header('Location: /change-password.php');
    exit;
}

$csrf = Auth::csrfToken();
$isAdmin = Auth::isAdmin();
TeamCalDatabase::connection();
$teamcalEnabled = TeamCal::isEnabled();
NotesDatabase::connection();
$notesEnabled = Notes::isEnabled();
TodoDatabase::connection();
$todoEnabled = Todo::isEnabled();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Notifications · Portal</title>
  <script>(function(){try{var t=localStorage.getItem('portal-theme');if(t!=='light'&&t!=='dark')t='dark';document.documentElement.setAttribute('data-theme',t);}catch(e){document.documentElement.setAttribute('data-theme','dark');}})();</script>
  <link rel="stylesheet" href="<?= e(asset_url('assets/css/style.css')) ?>">
</head>
<body
  data-csrf="<?= e($csrf) ?>"
  data-auth="1"
  data-admin="<?= $isAdmin ? '1' : '0' ?>"
  data-username="<?= e($user['username'] ?? '') ?>"
>
  <header class="app-header">
    <div class="brand">NOTIFICATIONS</div>
    <div class="header-actions">
      <button type="button" class="btn btn-sm btn-ghost" id="themeToggle" aria-label="Toggle theme" title="Theme">☀</button>
      <a class="btn btn-sm" href="/">Portal</a>
      <?php if ($teamcalEnabled): ?>
        <a class="btn btn-sm btn-app btn-app-cal" href="/calendar.php">Calendar</a>
      <?php endif; ?>
      <?php if ($notesEnabled): ?>
        <a class="btn btn-sm btn-app btn-app-notes" href="/notes.php">Notes</a>
      <?php endif; ?>
      <?php if ($todoEnabled): ?>
        <a class="btn btn-sm btn-app btn-app-todo" href="/todo.php">Todo</a>
      <?php endif; ?>
      <?php if ($isAdmin): ?>
        <a class="btn btn-sm" href="/admin.php">Admin</a>
      <?php endif; ?>
      <?= render_user_menu($user) ?>
      <form method="post" action="/logout.php" style="display:inline">
        <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
        <button class="btn btn-sm btn-ghost" type="submit">Logout</button>
      </form>
    </div>
  </header>

  <main class="notif-page">
    <div class="notif-page-head">
      <h1>Notifications</h1>
      <div class="notif-page-actions">
        <button type="button" class="btn btn-sm" id="notifMarkAll">Mark all read</button>
      </div>
    </div>
    <p class="form-hint" id="notifSummary">Loading…</p>
    <div class="notif-list" id="notifList"></div>
  </main>

  <div class="toast" id="toast"></div>
  <script src="<?= e(asset_url('assets/js/theme.js')) ?>"></script>
  <script src="<?= e(asset_url('assets/js/user-menu.js')) ?>"></script>
  <script src="<?= e(asset_url('assets/js/notifications.js')) ?>"></script>
</body>
</html>
