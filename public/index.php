<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/bootstrap.php';

$user = Auth::user();
if ($user && Auth::mustChangePassword()) {
    header('Location: /change-password.php');
    exit;
}
$csrf = Auth::csrfToken();
$isAdmin = Auth::isAdmin();
TeamCalDatabase::connection();
$teamcalEnabled = TeamCal::isEnabled();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Portal</title>
  <script>(function(){try{var t=localStorage.getItem('portal-theme');if(t!=='light'&&t!=='dark')t='dark';document.documentElement.setAttribute('data-theme',t);}catch(e){document.documentElement.setAttribute('data-theme','dark');}})();</script>
  <link rel="stylesheet" href="<?= e(asset_url('assets/css/style.css')) ?>">
</head>
<body
  data-csrf="<?= e($csrf) ?>"
  data-auth="<?= $user ? '1' : '0' ?>"
  data-admin="<?= $isAdmin ? '1' : '0' ?>"
  data-username="<?= e($user['username'] ?? '') ?>"
>
  <header class="app-header">
    <div class="brand">PORTAL</div>
    <nav class="tabs" id="tabsNav" aria-label="Tabs"></nav>
    <div class="header-actions">
      <button type="button" class="btn btn-sm btn-ghost" id="themeToggle" aria-label="Toggle theme" title="Theme">☀</button>
      <?php if ($teamcalEnabled): ?>
        <a class="btn btn-sm" href="/calendar.php">Calendar</a>
      <?php endif; ?>
      <?php if ($user): ?>
        <button type="button" class="btn btn-sm" id="btnAddBookmark">+ Bookmark</button>
        <button type="button" class="btn btn-sm" id="btnManage">Manage</button>
        <?php if ($isAdmin): ?>
          <a class="btn btn-sm" href="/admin.php">Admin</a>
        <?php endif; ?>
        <span class="user-chip"><?= e($user['username']) ?></span>
        <button type="button" class="btn btn-sm btn-ghost" id="btnChangePassword">Password</button>
        <form method="post" action="/logout.php" style="display:inline">
          <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
          <button class="btn btn-sm btn-ghost" type="submit">Logout</button>
        </form>
      <?php else: ?>
        <a class="btn btn-sm btn-primary" href="/login.php">Login</a>
      <?php endif; ?>
    </div>
  </header>

  <main class="main">
    <form class="search-wrap" id="searchForm" action="https://www.google.com/search" method="get" target="_blank" rel="noopener">
      <div class="search-box">
        <select class="search-engine" id="searchEngine" aria-label="Search mode">
          <option value="bookmarks" selected>Bookmarks</option>
          <option value="google">Google</option>
          <option value="bing">Bing</option>
          <option value="duckduckgo">DuckDuckGo</option>
        </select>
        <input type="text" name="q" id="searchInput" placeholder="Search bookmarks…" autocomplete="off">
        <button class="search-go" type="submit" aria-label="Search">→</button>
      </div>
    </form>

    <div id="categoriesRoot" class="categories"></div>
    <div id="emptyState" class="empty-state hidden">No bookmarks to show on this tab</div>
  </main>

  <footer class="footer-bar">
    <span id="footerDate"></span>
    <span id="footerTime"></span>
  </footer>

  <!-- Bookmark modal -->
  <div class="modal-backdrop" id="bookmarkModal" role="dialog" aria-modal="true">
    <div class="modal">
      <h2 id="bookmarkModalTitle">Add bookmark</h2>
      <form id="bookmarkForm">
        <input type="hidden" id="bmId">
        <div class="form-group">
          <label for="bmTitle">Title</label>
          <input class="form-control" id="bmTitle" required maxlength="120" placeholder="e.g. Google 地圖">
        </div>
        <div class="form-group">
          <label for="bmUrl">URL</label>
          <input class="form-control" id="bmUrl" required placeholder="https://">
        </div>
        <div class="form-row">
          <div class="form-group">
            <label for="bmCategory">Category</label>
            <select class="form-control" id="bmCategory" required></select>
          </div>
          <div class="form-group">
            <label for="bmVisibility">Visibility</label>
            <select class="form-control" id="bmVisibility">
              <option value="private">Private (only me)</option>
              <option value="share">Share (selected groups)</option>
              <option value="public">Public (everyone)</option>
            </select>
          </div>
        </div>
        <div class="form-group hidden" id="bmGroupsWrap">
          <label>Share groups</label>
          <div class="checkbox-list" id="bmGroups"></div>
        </div>
        <div class="form-group">
          <label>Icon</label>
          <div class="icon-row">
            <img class="icon-preview" id="bmIconPreview" alt="" src="">
            <div>
              <input type="file" id="bmIconFile" accept="image/png,image/jpeg,image/gif,image/webp,image/svg+xml">
              <input type="hidden" id="bmIconPath">
              <div style="margin-top:8px;display:flex;gap:6px;flex-wrap:wrap">
                <button type="button" class="btn btn-sm" id="bmUploadIcon">Upload icon</button>
                <button type="button" class="btn btn-sm btn-danger" id="bmClearIcon">Delete icon</button>
              </div>
              <div class="form-hint">PNG / JPG / GIF / WebP / SVG, max 2MB</div>
            </div>
          </div>
        </div>
        <div class="form-actions">
          <button type="button" class="btn btn-ghost" data-close-modal>Cancel</button>
          <button type="submit" class="btn btn-primary">Save</button>
        </div>
      </form>
    </div>
  </div>

  <!-- Manage tabs/categories modal -->
  <div class="modal-backdrop" id="manageModal" role="dialog" aria-modal="true">
    <div class="modal">
      <h2>Manage tabs & categories</h2>
      <div class="form-group">
        <label>Add tab</label>
        <div style="display:flex;gap:8px">
          <input class="form-control" id="newTabName" placeholder="Tab name">
          <button type="button" class="btn btn-primary" id="btnCreateTab">Add</button>
        </div>
        <?php if ($isAdmin): ?>
          <label style="display:flex;align-items:center;gap:8px;margin-top:8px;color:var(--text-muted);font-size:0.88rem">
            <input type="checkbox" id="newTabGlobal"> Create as global tab
          </label>
        <?php endif; ?>
      </div>
      <div class="form-group">
        <label>Tabs</label>
        <div id="manageTabsList" class="checkbox-list"></div>
      </div>
      <hr style="border:0;border-top:1px solid var(--border);margin:16px 0">
      <div class="form-group">
        <label>Add category</label>
        <div class="form-row">
          <input class="form-control" id="newCatName" placeholder="Category name">
          <input class="form-control" id="newCatColor" type="color" value="#4fc3f7">
        </div>
        <div class="form-row" style="margin-top:8px">
          <select class="form-control" id="newCatTab"></select>
          <button type="button" class="btn btn-primary" id="btnCreateCat">Add category</button>
        </div>
        <?php if ($isAdmin): ?>
          <label style="display:flex;align-items:center;gap:8px;margin-top:8px;color:var(--text-muted);font-size:0.88rem">
            <input type="checkbox" id="newCatGlobal"> Create as global category
          </label>
        <?php endif; ?>
      </div>
      <div class="form-group">
        <label>Categories</label>
        <div id="manageCatsList" class="checkbox-list"></div>
      </div>
      <div class="form-actions">
        <button type="button" class="btn btn-primary" data-close-modal>Done</button>
      </div>
    </div>
  </div>

  <!-- Change password modal -->
  <div class="modal-backdrop" id="passwordModal" role="dialog" aria-modal="true">
    <div class="modal">
      <h2>Change password</h2>
      <form id="passwordForm">
        <div class="form-group">
          <label for="pwCurrent">Current password</label>
          <input class="form-control" id="pwCurrent" type="password" required autocomplete="current-password">
        </div>
        <div class="form-group">
          <label for="pwNew">New password</label>
          <input class="form-control" id="pwNew" type="password" required minlength="6" autocomplete="new-password">
        </div>
        <div class="form-group">
          <label for="pwConfirm">Confirm new password</label>
          <input class="form-control" id="pwConfirm" type="password" required minlength="6" autocomplete="new-password">
        </div>
        <p class="form-hint">Minimum 6 characters</p>
        <div class="form-actions">
          <button type="button" class="btn btn-ghost" data-close-modal>Cancel</button>
          <button type="submit" class="btn btn-primary">Update password</button>
        </div>
      </form>
    </div>
  </div>

  <div class="category-picker" id="categoryPicker" role="menu" aria-label="Choose category"></div>
  <div class="toast" id="toast"></div>
  <script src="<?= e(asset_url('assets/js/theme.js')) ?>"></script>
  <script src="<?= e(asset_url('assets/js/app.js')) ?>"></script>
</body>
</html>
