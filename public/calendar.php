<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/bootstrap.php';

$user = Auth::user();
if ($user && Auth::mustChangePassword()) {
    header('Location: /change-password.php');
    exit;
}

TeamCalDatabase::connection();
if (!TeamCal::isEnabled()) {
    header('Location: /');
    exit;
}

$csrf = Auth::csrfToken();
$isAdmin = Auth::isAdmin();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Team Calendar · Portal</title>
  <link rel="stylesheet" href="<?= e(asset_url('assets/css/style.css')) ?>">
</head>
<body
  data-csrf="<?= e($csrf) ?>"
  data-auth="<?= $user ? '1' : '0' ?>"
  data-admin="<?= $isAdmin ? '1' : '0' ?>"
  data-username="<?= e($user['username'] ?? '') ?>"
>
  <header class="app-header">
    <div class="brand">TEAM CALENDAR</div>
    <div class="header-actions">
      <a class="btn btn-sm" href="/">Portal</a>
      <button type="button" class="btn btn-sm btn-primary" id="btnNewEvent">+ Event</button>
      <?php if ($user): ?>
        <?php if ($isAdmin): ?>
          <a class="btn btn-sm" href="/admin.php">Admin</a>
        <?php endif; ?>
        <span class="user-chip"><?= e($user['username']) ?></span>
        <form method="post" action="/logout.php" style="display:inline">
          <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
          <button class="btn btn-sm btn-ghost" type="submit">Logout</button>
        </form>
      <?php else: ?>
        <a class="btn btn-sm btn-primary" href="/login.php">Login</a>
      <?php endif; ?>
    </div>
  </header>

  <main class="cal-main">
    <div class="cal-toolbar">
      <div class="cal-toolbar-left">
        <button type="button" class="btn btn-sm" id="calPrev" aria-label="Previous week">‹</button>
        <button type="button" class="btn btn-sm" id="calToday">This week</button>
        <button type="button" class="btn btn-sm" id="calNext" aria-label="Next week">›</button>
      </div>
      <h1 class="cal-week-label" id="calWeekLabel">Week</h1>
      <div class="cal-toolbar-right">
        <span class="cal-hint">Week starts Sunday</span>
      </div>
    </div>
    <div class="cal-week" id="calWeek"></div>
  </main>

  <div class="modal-backdrop" id="eventModal" role="dialog" aria-modal="true">
    <div class="modal cal-modal">
      <h2 id="eventModalTitle">Add event</h2>
      <form id="eventForm">
        <input type="hidden" id="evId">
        <div class="form-row">
          <div class="form-group">
            <label for="evType">Type</label>
            <select class="form-control" id="evType"></select>
          </div>
          <div class="form-group">
            <label for="evColor">Color</label>
            <input class="form-control" id="evColor" type="color" value="#4fc3f7">
          </div>
        </div>
        <div class="form-group">
          <label for="evTitle">Title</label>
          <input class="form-control" id="evTitle" required maxlength="200" placeholder="Event title">
        </div>
        <div class="form-group">
          <label>People</label>
          <div class="checkbox-list cal-people-list" id="evPeople"></div>
        </div>
        <div class="form-group">
          <label for="evLocationSelect">Location</label>
          <select class="form-control" id="evLocationSelect"></select>
          <input class="form-control hidden" id="evLocationCustom" style="margin-top:8px" placeholder="Type location">
        </div>
        <div class="form-group">
          <label for="evDescription">Description</label>
          <textarea class="form-control" id="evDescription" rows="3" maxlength="2000"></textarea>
        </div>
        <div class="form-group">
          <label>Time mode</label>
          <div class="cal-time-mode">
            <label><input type="radio" name="evTimeMode" value="timed" checked> Timed</label>
            <label><input type="radio" name="evTimeMode" value="all_day"> All day</label>
            <label><input type="radio" name="evTimeMode" value="am"> AM</label>
            <label><input type="radio" name="evTimeMode" value="pm"> PM</label>
          </div>
        </div>
        <div class="form-row">
          <div class="form-group">
            <label for="evStart">Start</label>
            <input class="form-control" id="evStart" type="datetime-local" required>
          </div>
          <div class="form-group">
            <label for="evEnd">End</label>
            <input class="form-control" id="evEnd" type="datetime-local" required>
          </div>
        </div>
        <div class="form-row">
          <div class="form-group">
            <label for="evVisibility">Visibility</label>
            <select class="form-control" id="evVisibility">
              <option value="public">Public (everyone)</option>
              <?php if ($user): ?>
                <option value="share">Share (selected groups)</option>
                <option value="private">Private (only me)</option>
              <?php endif; ?>
            </select>
          </div>
        </div>
        <div class="form-group hidden" id="evGroupsWrap">
          <label>Share groups</label>
          <div class="checkbox-list" id="evGroups"></div>
        </div>
        <div class="form-actions">
          <button type="button" class="btn btn-danger hidden" id="evDelete">Delete</button>
          <div style="flex:1"></div>
          <button type="button" class="btn btn-ghost" data-close-modal>Cancel</button>
          <button type="submit" class="btn btn-primary" id="evSubmit">Save</button>
        </div>
      </form>
    </div>
  </div>

  <div class="toast" id="toast"></div>
  <script src="<?= e(asset_url('assets/js/calendar.js')) ?>"></script>
</body>
</html>
