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

TodoDatabase::connection();
if (!Todo::isEnabled()) {
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
  <title>Todo · Portal</title>
  <script>(function(){try{var t=localStorage.getItem('portal-theme');if(t!=='light'&&t!=='dark')t='dark';document.documentElement.setAttribute('data-theme',t);}catch(e){document.documentElement.setAttribute('data-theme','dark');}})();</script>
  <link rel="stylesheet" href="<?= e(asset_url('assets/css/style.css')) ?>">
</head>
<body
  data-csrf="<?= e($csrf) ?>"
  data-auth="1"
  data-admin="<?= $isAdmin ? '1' : '0' ?>"
  data-username="<?= e($user['username'] ?? '') ?>"
  data-user-id="<?= (int) $user['id'] ?>"
>
  <header class="app-header">
    <div class="brand">TODO</div>
    <div class="header-actions">
      <button type="button" class="btn btn-sm btn-ghost" id="themeToggle" aria-label="Toggle theme" title="Theme">☀</button>
      <a class="btn btn-sm" href="/">Portal</a>
      <div class="todo-view-toggle" role="group" aria-label="View">
        <button type="button" class="btn btn-sm btn-primary" id="todoViewBoard" data-view="board">Board</button>
        <button type="button" class="btn btn-sm" id="todoViewArchive" data-view="archive">Archive</button>
      </div>
      <button type="button" class="btn btn-sm btn-primary" id="btnNewTask">+ Task</button>
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

  <main class="todo-main">
    <div class="todo-toolbar">
      <input class="form-control todo-search" id="todoSearch" type="search" placeholder='Search…  #urgent AND api  ·  foo OR bar' title="AND / OR / #tag / &quot;phrase&quot; / parentheses">
      <select class="form-control todo-filter" id="todoFilter" aria-label="Filter">
        <option value="">All visible</option>
        <option value="mine">Created by me</option>
        <option value="assigned">Assigned to me</option>
      </select>
      <label class="todo-view-as-wrap hidden" id="todoViewAsWrap">
        <span class="todo-view-as-label">View as</span>
        <select class="form-control todo-view-as" id="todoViewAs" aria-label="View as user">
          <option value="me">Me</option>
          <option value="all">All users</option>
        </select>
      </label>
      <button type="button" class="btn btn-sm hidden" id="btnArchiveAllDone" title="Archive all done tasks you can manage">Archive done</button>
    </div>
    <div class="todo-banner hidden" id="todoViewBanner"></div>

    <div class="todo-board" id="todoBoard">
      <section class="todo-column" data-status="todo">
        <header class="todo-column-head">
          <h2>To do</h2>
          <span class="todo-column-count" data-count="todo">0</span>
        </header>
        <div class="todo-column-body" data-drop="todo"></div>
      </section>
      <section class="todo-column" data-status="in_progress">
        <header class="todo-column-head">
          <h2>In progress</h2>
          <span class="todo-column-count" data-count="in_progress">0</span>
        </header>
        <div class="todo-column-body" data-drop="in_progress"></div>
      </section>
      <section class="todo-column" data-status="done">
        <header class="todo-column-head">
          <h2>Done</h2>
          <span class="todo-column-count" data-count="done">0</span>
        </header>
        <div class="todo-column-body" data-drop="done"></div>
      </section>
    </div>

    <div class="todo-archive hidden" id="todoArchive">
      <div class="todo-archive-head">
        <h2>Archived tasks</h2>
        <span class="todo-column-count" id="todoArchiveCount">0</span>
      </div>
      <div class="todo-archive-list" id="todoArchiveList"></div>
    </div>
  </main>

  <div class="modal-backdrop" id="taskModal" role="dialog" aria-modal="true" aria-labelledby="taskModalTitle">
    <div class="modal todo-modal">
      <h2 id="taskModalTitle">Task</h2>
      <form id="taskForm">
        <input type="hidden" id="taskId">
        <div class="form-group">
          <label for="taskTitle">Title</label>
          <input class="form-control" id="taskTitle" maxlength="200" required>
        </div>
        <div class="form-group">
          <label for="taskDescription">Description</label>
          <textarea class="form-control" id="taskDescription" rows="4" maxlength="5000"></textarea>
        </div>
        <div class="form-group">
          <label for="taskTagInput">Hashtags</label>
          <div class="todo-tags-input" id="taskTags">
            <div class="todo-tags-chips" id="taskTagsChips"></div>
            <input class="todo-tags-field" id="taskTagInput" type="text" maxlength="40" placeholder="Add #tag…" autocomplete="off">
          </div>
        </div>
        <div class="form-row">
          <div class="form-group">
            <label for="taskStatus">Status</label>
            <select class="form-control" id="taskStatus">
              <option value="todo">To do</option>
              <option value="in_progress">In progress</option>
              <option value="done">Done</option>
            </select>
          </div>
          <div class="form-group">
            <label for="taskDue">Due date</label>
            <input class="form-control" id="taskDue" type="date">
          </div>
        </div>
        <div class="form-row">
          <div class="form-group">
            <label for="taskAssignee">Assignee</label>
            <select class="form-control" id="taskAssignee">
              <option value="">Unassigned</option>
            </select>
          </div>
          <div class="form-group">
            <label for="taskVisibility">Visibility</label>
            <select class="form-control" id="taskVisibility">
              <option value="private">Private (owner + assignee)</option>
              <option value="share">Share (selected groups)</option>
            </select>
          </div>
        </div>
        <div class="form-group hidden" id="taskGroupsWrap">
          <label>Share groups</label>
          <div class="checkbox-list" id="taskGroups"></div>
        </div>
        <div class="todo-task-meta" id="taskMetaInfo"></div>
        <div class="form-actions">
          <button type="button" class="btn btn-danger hidden" id="taskDelete">Delete</button>
          <button type="button" class="btn hidden" id="taskArchive">Archive</button>
          <button type="button" class="btn hidden" id="taskUnarchive">Unarchive</button>
          <span style="flex:1"></span>
          <button type="button" class="btn" id="taskCancel">Cancel</button>
          <button type="submit" class="btn btn-primary" id="taskSave">Save</button>
        </div>
      </form>
    </div>
  </div>

  <div class="toast" id="toast"></div>
  <script src="<?= e(asset_url('assets/js/theme.js')) ?>"></script>
  <script src="<?= e(asset_url('assets/js/user-menu.js')) ?>"></script>
  <script src="<?= e(asset_url('assets/js/todo.js')) ?>"></script>
</body>
</html>
