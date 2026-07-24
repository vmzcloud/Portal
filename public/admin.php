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
      <button class="btn btn-sm" data-panel="events">Events</button>
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

    <section class="admin-panel hidden" id="panel-events">
      <div class="admin-events-header">
        <h2 style="margin:0">Event management</h2>
        <button type="button" class="btn btn-sm btn-primary" id="adminEvNew">+ Event</button>
      </div>
      <form id="adminEvFilters" class="admin-event-filters">
        <div class="form-group">
          <label for="aevQ">Search</label>
          <input class="form-control" id="aevQ" type="search" placeholder="Title, description, location">
        </div>
        <div class="form-group">
          <label for="aevFrom">From</label>
          <input class="form-control" id="aevFrom" type="date" required>
        </div>
        <div class="form-group">
          <label for="aevTo">To</label>
          <input class="form-control" id="aevTo" type="date" required>
        </div>
        <div class="form-group">
          <label for="aevType">Type</label>
          <select class="form-control" id="aevType">
            <option value="">All</option>
          </select>
        </div>
        <div class="form-group">
          <label for="aevLocation">Location</label>
          <select class="form-control" id="aevLocation">
            <option value="">All</option>
          </select>
        </div>
        <div class="form-group">
          <label for="aevVisibility">Visibility</label>
          <select class="form-control" id="aevVisibility">
            <option value="">All</option>
            <option value="public">public</option>
            <option value="share">share</option>
            <option value="private">private</option>
          </select>
        </div>
        <div class="form-group">
          <label for="aevTimeMode">Time mode</label>
          <select class="form-control" id="aevTimeMode">
            <option value="">All</option>
            <option value="timed">Timed</option>
            <option value="all_day">All day</option>
            <option value="am">AM</option>
            <option value="pm">PM</option>
          </select>
        </div>
        <div class="form-group">
          <label for="aevColor">Color</label>
          <select class="form-control" id="aevColor">
            <option value="">All</option>
            <option value="#4fc3f7">Blue</option>
            <option value="#ab47bc">Purple</option>
            <option value="#ef5350">Red</option>
            <option value="#66bb6a">Green</option>
            <option value="#ffa726">Orange</option>
            <option value="#26c6da">Cyan</option>
            <option value="#ec407a">Pink</option>
          </select>
        </div>
        <div class="form-group">
          <label for="aevOwner">Owner</label>
          <select class="form-control" id="aevOwner">
            <option value="">All</option>
          </select>
        </div>
        <div class="form-group">
          <label for="aevPerson">Person</label>
          <select class="form-control" id="aevPerson">
            <option value="">All</option>
          </select>
        </div>
        <div class="form-group">
          <label for="aevGroup">Group</label>
          <select class="form-control" id="aevGroup">
            <option value="">All</option>
          </select>
        </div>
        <div class="form-group admin-event-filter-actions">
          <label>&nbsp;</label>
          <div style="display:flex;gap:8px;flex-wrap:wrap">
            <button type="submit" class="btn btn-primary btn-sm">Apply</button>
            <button type="button" class="btn btn-sm" id="aevClear">Clear</button>
          </div>
        </div>
      </form>
      <div class="admin-event-meta">
        <span id="aevCount">0 events</span>
        <span id="aevTruncated" class="hidden" style="color:var(--warning)">Showing first 500 — narrow filters</span>
      </div>
      <div class="table-wrap">
        <table class="data admin-events-table">
          <thead>
            <tr>
              <th></th>
              <th>Start</th>
              <th>End</th>
              <th>Mode</th>
              <th>Type</th>
              <th>Title</th>
              <th>Location</th>
              <th>People</th>
              <th>Visibility</th>
              <th>Owner</th>
              <th></th>
            </tr>
          </thead>
          <tbody id="adminEventsTable"></tbody>
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
      <h3 style="margin:0 0 10px;font-size:1rem">Period time ranges</h3>
      <p class="form-hint" style="margin-top:0">Used when an event is All day, AM, or PM. Defaults: All day 09:00–18:00, AM 09:00–13:00, PM 14:00–18:00.</p>
      <div class="table-wrap" style="margin-bottom:12px">
        <table class="data" id="teamcalRangesTable">
          <thead>
            <tr><th>Mode</th><th>Start</th><th>End</th></tr>
          </thead>
          <tbody>
            <tr>
              <td>All day</td>
              <td><input class="form-control" type="time" id="rangeAllDayStart" value="09:00"></td>
              <td><input class="form-control" type="time" id="rangeAllDayEnd" value="18:00"></td>
            </tr>
            <tr>
              <td>AM</td>
              <td><input class="form-control" type="time" id="rangeAmStart" value="09:00"></td>
              <td><input class="form-control" type="time" id="rangeAmEnd" value="13:00"></td>
            </tr>
            <tr>
              <td>PM</td>
              <td><input class="form-control" type="time" id="rangePmStart" value="14:00"></td>
              <td><input class="form-control" type="time" id="rangePmEnd" value="18:00"></td>
            </tr>
          </tbody>
        </table>
      </div>
      <div class="form-actions" style="justify-content:flex-start;margin-bottom:18px">
        <button type="button" class="btn btn-primary" id="teamcalSaveRanges">Save period ranges</button>
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
      <hr style="border:0;border-top:1px solid var(--border);margin:18px 0">
      <h3 style="margin:0 0 10px;font-size:1rem">Holidays (ICS)</h3>
      <p class="form-hint" style="margin-top:0">Upload a holiday calendar (.ics). Dates are marked red on the week view (with Sundays). Replacing uploads overwrite the list.</p>
      <div class="form-group">
        <div style="display:flex;flex-wrap:wrap;gap:8px;align-items:center">
          <input type="file" id="teamcalHolidayFile" accept=".ics,.ical,text/calendar">
          <button type="button" class="btn btn-primary" id="teamcalUploadHolidays">Upload holiday ICS</button>
          <button type="button" class="btn btn-ghost" id="teamcalClearHolidays">Clear holidays</button>
        </div>
        <div class="form-hint" id="teamcalHolidayCount">Holidays loaded: —</div>
      </div>
    </section>
  </div>

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
            <label>Color</label>
            <input type="hidden" id="evColor" value="#4fc3f7">
            <div class="cal-color-swatches" id="evColorSwatches" role="radiogroup" aria-label="Color"></div>
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
              <option value="share">Share (selected groups)</option>
              <option value="private">Private (only me)</option>
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
  <script src="<?= e(asset_url('assets/js/admin.js')) ?>"></script>
</body>
</html>
