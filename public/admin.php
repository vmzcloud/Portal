<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/bootstrap.php';

$user = Auth::user();
if (!$user || $user['role'] !== 'admin') {
    header('Location: /login.php');
    exit;
}
if (Auth::mustChangePassword()) {
    header('Location: /change-password.php');
    exit;
}
$csrf = Auth::csrfToken();
TeamCalDatabase::connection();
$teamcalEnabled = TeamCal::isEnabled();
$teamcalTypesJson = json_encode(
    TeamCal::eventTypes(),
    JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
);
$teamcalLocationsJson = json_encode(
    TeamCal::locations(),
    JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
);
if ($teamcalTypesJson === false) {
    $teamcalTypesJson = "[]";
}
if ($teamcalLocationsJson === false) {
    $teamcalLocationsJson = "[]";
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Admin · Portal</title>
  <link rel="stylesheet" href="<?= e(asset_url('assets/css/style.css')) ?>">
</head>
<body data-csrf="<?= e($csrf) ?>" data-auth="1" data-admin="1">
  <header class="app-header">
    <div class="brand">PORTAL ADMIN</div>
    <div class="header-actions">
      <a class="btn btn-sm" href="/">Back to portal</a>
      <span class="user-chip"><?= e($user['username']) ?></span>
      <form method="post" action="/logout.php" style="display:inline">
        <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
        <button class="btn btn-sm btn-ghost" type="submit">Logout</button>
      </form>
    </div>
  </header>

  <div class="admin-layout">
    <div class="admin-nav">
      <button class="btn btn-sm btn-primary" data-panel="users">Users</button>
      <button class="btn btn-sm" data-panel="groups">Groups</button>
      <button class="btn btn-sm" data-panel="teamcal">Team Calendar</button>
    </div>

    <section class="admin-panel" id="panel-users">
      <h2>User management</h2>
      <form id="userForm" style="margin-bottom:16px">
        <div class="form-row" style="align-items:end">
          <div class="form-group" style="margin:0">
            <label>Username</label>
            <input class="form-control" id="userUsername" required>
          </div>
          <div class="form-group" style="margin:0">
            <label>Password</label>
            <input class="form-control" id="userPassword" type="password" required minlength="6">
          </div>
          <div class="form-group" style="margin:0">
            <label>Role</label>
            <select class="form-control" id="userRole">
              <option value="user">user</option>
              <option value="admin">admin</option>
            </select>
          </div>
          <button class="btn btn-primary" type="submit">Add user</button>
        </div>
        <div style="display:flex;flex-wrap:wrap;gap:16px;margin-top:10px;color:var(--text-muted);font-size:0.9rem">
          <label style="display:flex;align-items:center;gap:8px;margin:0">
            <input type="checkbox" id="userActive" checked> Active
          </label>
          <label style="display:flex;align-items:center;gap:8px;margin:0">
            <input type="checkbox" id="userMustChange"> Must change password on next login
          </label>
        </div>
      </form>
      <div class="table-wrap">
        <table class="data">
          <thead>
            <tr>
              <th>ID</th>
              <th>Username</th>
              <th>Role</th>
              <th>Status</th>
              <th>Force pwd</th>
              <th>Created</th>
              <th></th>
            </tr>
          </thead>
          <tbody id="usersTable"></tbody>
        </table>
      </div>
    </section>

    <section class="admin-panel hidden" id="panel-groups">
      <h2>Group management</h2>
      <form id="groupForm" style="margin-bottom:16px">
        <input type="hidden" id="groupId">
        <div class="form-row">
          <div class="form-group">
            <label>Group name</label>
            <input class="form-control" id="groupName" required>
          </div>
          <div class="form-group">
            <label>Description</label>
            <input class="form-control" id="groupDesc">
          </div>
        </div>
        <div class="form-group">
          <label>Members</label>
          <div class="checkbox-list" id="groupMembers"></div>
        </div>
        <div class="form-actions" style="justify-content:flex-start">
          <button class="btn btn-primary" type="submit" id="groupSubmitBtn">Add group</button>
          <button class="btn btn-ghost hidden" type="button" id="groupCancelBtn">Cancel edit</button>
        </div>
      </form>
      <div class="table-wrap">
        <table class="data">
          <thead>
            <tr><th>ID</th><th>Name</th><th>Description</th><th>Members</th><th></th></tr>
          </thead>
          <tbody id="groupsTable"></tbody>
        </table>
      </div>
    </section>

    <section class="admin-panel hidden" id="panel-teamcal">
      <h2>Team Calendar</h2>
      <div class="form-group">
        <label style="display:flex;align-items:center;gap:10px;margin:0">
          <input type="checkbox" id="teamcalEnabled"<?= $teamcalEnabled ? ' checked' : '' ?>> Enable Team Calendar
        </label>
        <div class="form-hint">When disabled, the Calendar link and page are hidden. Default is off.</div>
      </div>
      <div class="form-actions" style="justify-content:flex-start;margin-bottom:18px">
        <button type="button" class="btn btn-primary" id="teamcalSaveEnabled">Save setting</button>
      </div>
      <hr style="border:0;border-top:1px solid var(--border);margin:8px 0 18px">
      <div class="form-row">
        <div class="form-group" style="flex:1">
          <label for="teamcalTypesJson">Event types (JSON array)</label>
          <textarea class="form-control" id="teamcalTypesJson" rows="10" spellcheck="false" style="font-family:ui-monospace,monospace;font-size:0.85rem"><?= e($teamcalTypesJson) ?></textarea>
          <div class="form-hint">Example: ["Meeting","Leave","Holiday"]</div>
        </div>
        <div class="form-group" style="flex:1">
          <label for="teamcalLocationsJson">Locations (JSON array)</label>
          <textarea class="form-control" id="teamcalLocationsJson" rows="10" spellcheck="false" style="font-family:ui-monospace,monospace;font-size:0.85rem"><?= e($teamcalLocationsJson) ?></textarea>
          <div class="form-hint">Example: ["Office","Remote","Room A"]</div>
        </div>
      </div>
      <div class="form-actions" style="justify-content:flex-start">
        <button type="button" class="btn btn-primary" id="teamcalSaveJson">Save types &amp; locations</button>
      </div>
    </section>
  </div>

  <div class="toast" id="toast"></div>
  <script src="<?= e(asset_url('assets/js/admin.js')) ?>"></script>
</body>
</html>
