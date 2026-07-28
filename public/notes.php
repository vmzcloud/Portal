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

NotesDatabase::connection();
if (!Notes::isEnabled()) {
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
  <title>Notes · Portal</title>
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
    <div class="brand">NOTES</div>
    <div class="header-actions">
      <button type="button" class="btn btn-sm btn-ghost" id="themeToggle" aria-label="Toggle theme" title="Theme">☀</button>
      <a class="btn btn-sm" href="/">Portal</a>
      <div class="notes-view-toggle" role="group" aria-label="View mode">
        <button type="button" class="btn btn-sm btn-primary" id="notesViewList" data-view="list">List</button>
        <button type="button" class="btn btn-sm" id="notesViewCards" data-view="cards">Cards</button>
      </div>
      <button type="button" class="btn btn-sm btn-primary" id="btnNewNote">+ Note</button>
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

  <main class="notes-main" id="notesMain" data-view="list">
    <aside class="notes-sidebar" id="notesSidebar">
      <div class="notes-sidebar-head">
        <input class="form-control" id="notesSearch" type="search" placeholder='Search…  #tag AND word  ·  a OR b' title="AND / OR / #tag / &quot;phrase&quot; / parentheses">
        <div class="notes-tag-cloud" id="notesTagCloud" aria-label="Tag cloud"></div>
      </div>
      <div class="notes-list" id="notesList"></div>
    </aside>

    <section class="notes-editor-pane" id="notesEditorPane">
      <div class="notes-empty" id="notesEmpty">
        <p>Select a note or create a new one.</p>
      </div>
      <form class="notes-editor hidden" id="notesEditor">
        <input type="hidden" id="noteId">
        <div class="notes-editor-top">
          <input class="notes-title-input" id="noteTitle" maxlength="200" placeholder="Title" required>
          <div class="notes-editor-actions">
            <button type="button" class="btn btn-sm hidden" id="noteHistory">History</button>
            <button type="button" class="btn btn-sm btn-danger hidden" id="noteDelete">Delete</button>
            <button type="submit" class="btn btn-sm btn-primary" id="noteSave">Save</button>
          </div>
        </div>
        <div class="notes-history-panel hidden" id="noteHistoryPanel">
          <div class="notes-history-head">
            <strong>Version history</strong>
            <span class="notes-history-hint">Last 5 title/body snapshots</span>
            <button type="button" class="btn btn-sm btn-ghost" id="noteHistoryClose" aria-label="Close history">×</button>
          </div>
          <div class="notes-history-list" id="noteHistoryList"></div>
        </div>
        <div class="notes-toolbar" id="notesToolbar">
          <button type="button" class="btn btn-sm" data-cmd="bold" title="Bold"><b>B</b></button>
          <button type="button" class="btn btn-sm" data-cmd="italic" title="Italic"><i>I</i></button>
          <button type="button" class="btn btn-sm" data-cmd="underline" title="Underline"><u>U</u></button>
          <label class="notes-color-control" title="Font color">
            <span class="notes-color-label" aria-hidden="true">A</span>
            <input type="color" id="noteFontColor" value="#4fc3f7" aria-label="Font color">
          </label>
          <button type="button" class="btn btn-sm" data-cmd="insertUnorderedList" title="Bullet list">• List</button>
          <button type="button" class="btn btn-sm" data-cmd="insertOrderedList" title="Numbered list">1. List</button>
          <button type="button" class="btn btn-sm" data-cmd="formatBlock" data-value="h2" title="Heading">H</button>
          <button type="button" class="btn btn-sm" data-cmd="formatBlock" data-value="blockquote" title="Quote">“”</button>
          <button type="button" class="btn btn-sm" data-cmd="codeBlock" title="Code block">&lt;/&gt;</button>
          <button type="button" class="btn btn-sm" data-cmd="createLink" title="Link">Link</button>
          <button type="button" class="btn btn-sm" data-cmd="removeFormat" title="Clear formatting">Clear</button>
        </div>
        <div class="notes-body-editor" id="noteBody" contenteditable="true" role="textbox" aria-multiline="true" aria-label="Note body" data-placeholder="Write your note…"></div>
        <div class="notes-meta-row">
          <div class="form-group" style="margin:0;min-width:160px;flex:1">
            <label for="noteTagInput">Hashtags</label>
            <div class="notes-tags-input" id="noteTags">
              <div class="notes-tags-chips" id="noteTagsChips"></div>
              <input class="notes-tags-field" id="noteTagInput" type="text" maxlength="40" placeholder="Add #tag…" autocomplete="off">
            </div>
          </div>
          <div class="form-group" style="margin:0;min-width:160px">
            <label for="noteVisibility">Visibility</label>
            <select class="form-control" id="noteVisibility">
              <option value="private">Private (only me)</option>
              <option value="share">Share (selected groups)</option>
            </select>
          </div>
          <div class="form-group hidden" id="noteGroupsWrap" style="margin:0;flex:1">
            <label>Share groups</label>
            <div class="checkbox-list notes-groups-list" id="noteGroups"></div>
          </div>
          <div class="notes-meta-info" id="noteMetaInfo"></div>
        </div>
      </form>
    </section>

    <section class="notes-cards-wrap hidden" id="notesCardsWrap">
      <div class="notes-cards-head">
        <input class="form-control" id="notesCardsSearch" type="search" placeholder='Search…  #tag AND word  ·  a OR b' title="AND / OR / #tag / &quot;phrase&quot; / parentheses">
        <div class="notes-tag-cloud" id="notesCardsTagCloud" aria-label="Tag cloud"></div>
      </div>
      <div class="notes-cards" id="notesCards"></div>
    </section>
  </main>

  <div class="toast" id="toast"></div>
  <script src="<?= e(asset_url('assets/js/theme.js')) ?>"></script>
  <script src="<?= e(asset_url('assets/js/user-menu.js')) ?>"></script>
  <script src="<?= e(asset_url('assets/js/notes.js')) ?>"></script>
</body>
</html>
