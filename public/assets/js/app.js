(() => {
  const body = document.body;
  const csrf = body.dataset.csrf || '';
  const isAuth = body.dataset.auth === '1';
  const isAdmin = body.dataset.admin === '1';

  const state = {
    tabs: [],
    categories: [],
    bookmarks: [],
    groups: [],
    activeTabId: null,
    pendingIconPath: null,
    clearIcon: false,
    searchQuery: '',
    dragBookmarkId: null,
    justDragged: false,
  };

  const els = {
    tabsNav: document.getElementById('tabsNav'),
    categoriesRoot: document.getElementById('categoriesRoot'),
    emptyState: document.getElementById('emptyState'),
    bookmarkModal: document.getElementById('bookmarkModal'),
    manageModal: document.getElementById('manageModal'),
    passwordModal: document.getElementById('passwordModal'),
    bookmarkForm: document.getElementById('bookmarkForm'),
    passwordForm: document.getElementById('passwordForm'),
    toast: document.getElementById('toast'),
    footerDate: document.getElementById('footerDate'),
    footerTime: document.getElementById('footerTime'),
    categoryPicker: document.getElementById('categoryPicker'),
  };

  const DND_TYPE = 'application/x-portal-bookmark-id';

  function toast(msg, isError = false) {
    if (!els.toast) return;
    els.toast.textContent = msg;
    els.toast.classList.toggle('error', !!isError);
    els.toast.classList.add('show');
    clearTimeout(toast._t);
    toast._t = setTimeout(() => els.toast.classList.remove('show'), 2600);
  }

  async function api(url, options = {}) {
    const opts = { ...options };
    opts.headers = { ...(options.headers || {}) };
    if (opts.body && !(opts.body instanceof FormData)) {
      opts.headers['Content-Type'] = 'application/json';
      opts.body = JSON.stringify(opts.body);
    }
    if (opts.method && opts.method !== 'GET') {
      opts.headers['X-CSRF-Token'] = csrf;
    }
    const res = await fetch(url, opts);
    let data;
    try {
      data = await res.json();
    } catch {
      throw new Error('Invalid server response');
    }
    if (!res.ok || data.ok === false) {
      throw new Error(data.error || 'Request failed');
    }
    return data.data;
  }

  function letterSrc(title) {
    const letter = (title || '?').trim().charAt(0).toUpperCase() || '?';
    const colors = ['#4fc3f7', '#ab47bc', '#ef5350', '#66bb6a', '#ffa726', '#26c6da', '#42a5f5', '#ec407a'];
    let hash = 0;
    for (let i = 0; i < (title || '').length; i++) hash = ((hash << 5) - hash) + title.charCodeAt(i);
    const bg = colors[Math.abs(hash) % colors.length];
    const svg = `<svg xmlns="http://www.w3.org/2000/svg" width="64" height="64"><rect width="64" height="64" rx="12" fill="${bg}"/><text x="50%" y="54%" dominant-baseline="middle" text-anchor="middle" fill="#fff" font-family="sans-serif" font-size="28" font-weight="700">${letter}</text></svg>`;
    return `data:image/svg+xml;base64,${btoa(unescape(encodeURIComponent(svg)))}`;
  }

  function iconOf(bm) {
    return bm.icon_src || (bm.icon_path ? `/${bm.icon_path}` : letterSrc(bm.title));
  }

  function visLabel(v) {
    return { public: 'Public', share: 'Shared', private: 'Private' }[v] || v;
  }

  function openModal(el) {
    el.classList.add('open');
  }
  function closeModal(el) {
    el.classList.remove('open');
  }

  document.querySelectorAll('[data-close-modal]').forEach((btn) => {
    btn.addEventListener('click', () => {
      closeModal(els.bookmarkModal);
      closeModal(els.manageModal);
      closeModal(els.passwordModal);
    });
  });
  [els.bookmarkModal, els.manageModal, els.passwordModal].forEach((m) => {
    m?.addEventListener('click', (e) => {
      if (e.target === m) closeModal(m);
    });
  });

  document.getElementById('btnChangePassword')?.addEventListener('click', () => {
    if (els.passwordForm) els.passwordForm.reset();
    openModal(els.passwordModal);
  });

  els.passwordForm?.addEventListener('submit', async (e) => {
    e.preventDefault();
    const current_password = document.getElementById('pwCurrent').value;
    const new_password = document.getElementById('pwNew').value;
    const confirm_password = document.getElementById('pwConfirm').value;
    if (new_password !== confirm_password) {
      toast('New password confirmation does not match', true);
      return;
    }
    if (new_password.length < 6) {
      toast('New password must be at least 6 characters', true);
      return;
    }
    try {
      await api('/api/password.php', {
        method: 'POST',
        body: { current_password, new_password, confirm_password },
      });
      closeModal(els.passwordModal);
      els.passwordForm.reset();
      toast('Password updated');
    } catch (err) {
      toast(err.message, true);
    }
  });

  document.addEventListener('click', (e) => {
    if (els.categoryPicker?.classList.contains('open')
      && !els.categoryPicker.contains(e.target)
      && !e.target.closest?.('.tab-btn')) {
      hideCategoryPicker();
    }
  });
  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') hideCategoryPicker();
  });

  function clearDragOver() {
    document.querySelectorAll('.drag-over').forEach((el) => el.classList.remove('drag-over'));
  }

  function hideCategoryPicker() {
    if (!els.categoryPicker) return;
    els.categoryPicker.classList.remove('open');
    els.categoryPicker.innerHTML = '';
  }

  function getDragBookmarkId(e) {
    const fromState = state.dragBookmarkId;
    if (fromState) return String(fromState);
    const dt = e.dataTransfer?.getData(DND_TYPE) || e.dataTransfer?.getData('text/plain') || '';
    return dt ? String(dt) : '';
  }

  async function moveBookmarkToCategory(bookmarkId, categoryId, opts = {}) {
    const bm = state.bookmarks.find((b) => String(b.id) === String(bookmarkId));
    if (!bm) {
      toast('Bookmark not found', true);
      return;
    }
    if (!bm.can_edit) {
      toast('You cannot move this bookmark', true);
      return;
    }
    if (String(bm.category_id) === String(categoryId)) {
      return;
    }
    const cat = state.categories.find((c) => String(c.id) === String(categoryId));
    if (!cat) {
      toast('Category not found', true);
      return;
    }

    try {
      await api('/api/bookmarks.php', {
        method: 'PUT',
        body: {
          id: Number(bm.id),
          title: bm.title,
          url: bm.url,
          category_id: Number(categoryId),
          visibility: bm.visibility,
          group_ids: bm.group_ids || [],
          icon_path: bm.icon_path || null,
          sort_order: bm.sort_order ?? 0,
        },
      });
      if (opts.switchTab && cat.tab_id != null) {
        state.activeTabId = cat.tab_id;
      }
      await reloadAll();
      toast(`Moved to ${cat.name}`);
    } catch (err) {
      toast(err.message, true);
    }
  }

  function showCategoryPickerForTab(tabId, anchorEl, bookmarkId) {
    const picker = els.categoryPicker;
    if (!picker) return;

    const cats = state.categories.filter((c) => String(c.tab_id) === String(tabId));
    const tab = state.tabs.find((t) => String(t.id) === String(tabId));
    picker.innerHTML = '';

    const title = document.createElement('div');
    title.className = 'category-picker-title';
    title.textContent = tab ? `Move to “${tab.name}”` : 'Choose category';
    picker.appendChild(title);

    if (!cats.length) {
      const empty = document.createElement('div');
      empty.className = 'category-picker-empty';
      empty.textContent = 'No categories on this tab';
      picker.appendChild(empty);
    } else {
      cats.forEach((cat) => {
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'category-picker-item';
        btn.setAttribute('role', 'menuitem');
        btn.innerHTML = `<span class="category-picker-dot" style="background:${escapeAttr(cat.color || '#4fc3f7')}"></span><span>${escapeHtml(cat.name)}</span>`;
        btn.addEventListener('click', async () => {
          hideCategoryPicker();
          await moveBookmarkToCategory(bookmarkId, cat.id, { switchTab: true });
        });
        picker.appendChild(btn);
      });
    }

    const rect = anchorEl.getBoundingClientRect();
    picker.classList.add('open');
    const pw = picker.offsetWidth || 200;
    const ph = picker.offsetHeight || 120;
    let left = rect.left;
    let top = rect.bottom + 8;
    if (left + pw > window.innerWidth - 8) left = window.innerWidth - pw - 8;
    if (top + ph > window.innerHeight - 8) top = Math.max(8, rect.top - ph - 8);
    if (left < 8) left = 8;
    picker.style.left = `${left}px`;
    picker.style.top = `${top}px`;
  }

  function bindCategoryDropZone(el, categoryId) {
    if (!isAuth) return;
    el.addEventListener('dragover', (e) => {
      if (!state.dragBookmarkId && !e.dataTransfer?.types?.includes(DND_TYPE)) return;
      e.preventDefault();
      e.dataTransfer.dropEffect = 'move';
      el.classList.add('drag-over');
    });
    el.addEventListener('dragleave', (e) => {
      if (!el.contains(e.relatedTarget)) {
        el.classList.remove('drag-over');
      }
    });
    el.addEventListener('drop', async (e) => {
      e.preventDefault();
      e.stopPropagation();
      clearDragOver();
      const id = getDragBookmarkId(e);
      state.dragBookmarkId = null;
      if (!id) return;
      await moveBookmarkToCategory(id, categoryId);
    });
  }

  function bindTabDropZone(btn, tab) {
    if (!isAuth) return;
    btn.addEventListener('dragover', (e) => {
      if (!state.dragBookmarkId && !e.dataTransfer?.types?.includes(DND_TYPE)) return;
      e.preventDefault();
      e.dataTransfer.dropEffect = 'move';
      btn.classList.add('drag-over');
    });
    btn.addEventListener('dragleave', (e) => {
      if (!btn.contains(e.relatedTarget)) {
        btn.classList.remove('drag-over');
      }
    });
    btn.addEventListener('drop', (e) => {
      e.preventDefault();
      e.stopPropagation();
      clearDragOver();
      const id = getDragBookmarkId(e);
      state.dragBookmarkId = null;
      if (!id) return;
      showCategoryPickerForTab(tab.id, btn, id);
    });
  }

  function renderTabs() {
    els.tabsNav.innerHTML = '';
    state.tabs.forEach((tab) => {
      const btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'tab-btn' + (String(tab.id) === String(state.activeTabId) ? ' active' : '');
      btn.textContent = tab.name;
      btn.dataset.tabId = tab.id;
      btn.addEventListener('click', () => {
        if (state.justDragged) return;
        hideCategoryPicker();
        state.activeTabId = tab.id;
        renderTabs();
        renderCategories();
      });
      bindTabDropZone(btn, tab);
      els.tabsNav.appendChild(btn);
    });
  }

  function categoriesForActiveTab() {
    return state.categories.filter((c) => {
      if (!state.activeTabId) return true;
      return String(c.tab_id) === String(state.activeTabId);
    });
  }

  function isBookmarkSearchMode() {
    return (document.getElementById('searchEngine')?.value || 'bookmarks') === 'bookmarks';
  }

  function bookmarkMatchesQuery(bm, q) {
    if (!q) return true;
    const hay = [
      bm.title || '',
      bm.url || '',
      bm.category_name || '',
    ].join(' ').toLowerCase();
    return hay.includes(q);
  }

  function bookmarksForCategory(catId) {
    const q = isBookmarkSearchMode() ? state.searchQuery.trim().toLowerCase() : '';
    return state.bookmarks.filter((b) => {
      if (String(b.category_id) !== String(catId)) return false;
      return bookmarkMatchesQuery(b, q);
    });
  }

  function renderCategories() {
    const cats = categoriesForActiveTab();
    const filtering = isBookmarkSearchMode() && state.searchQuery.trim() !== '';
    els.categoriesRoot.innerHTML = '';
    let any = false;

    cats.forEach((cat) => {
      const items = bookmarksForCategory(cat.id);
      if (!items.length && (filtering || !isAuth)) return;
      any = true;

      const block = document.createElement('section');
      block.className = 'category-block';
      block.dataset.categoryId = cat.id;
      block.style.setProperty('--cat-color', cat.color || '#4fc3f7');
      bindCategoryDropZone(block, cat.id);

      const head = document.createElement('div');
      head.className = 'category-head';
      head.innerHTML = `<h3 class="category-title">${escapeHtml(cat.name)}</h3>`;
      if (isAuth) {
        const meta = document.createElement('div');
        meta.className = 'category-meta';
        const addBtn = document.createElement('button');
        addBtn.type = 'button';
        addBtn.className = 'btn btn-sm';
        addBtn.textContent = '＋';
        addBtn.title = 'Add bookmark to this category';
        addBtn.addEventListener('click', () => openBookmarkModal(null, cat.id));
        meta.appendChild(addBtn);
        if (cat.can_edit) {
          const editBtn = document.createElement('button');
          editBtn.type = 'button';
          editBtn.className = 'btn btn-sm';
          editBtn.textContent = '✎';
          editBtn.title = 'Rename category';
          editBtn.addEventListener('click', async () => {
            const name = prompt('Category name', cat.name);
            if (name == null || !name.trim()) return;
            const color = prompt('Color (#rrggbb)', cat.color || '#4fc3f7') || cat.color;
            try {
              await api('/api/categories.php', {
                method: 'PUT',
                body: { id: cat.id, name: name.trim(), color },
              });
              await reloadAll();
              toast('Category updated');
            } catch (err) {
              toast(err.message, true);
            }
          });
          meta.appendChild(editBtn);
          const delBtn = document.createElement('button');
          delBtn.type = 'button';
          delBtn.className = 'btn btn-sm btn-danger';
          delBtn.textContent = 'Del';
          delBtn.addEventListener('click', async () => {
            if (!confirm(`Delete category "${cat.name}" and its bookmarks?`)) return;
            try {
              await api('/api/categories.php', { method: 'DELETE', body: { id: cat.id } });
              await reloadAll();
              toast('Category deleted');
            } catch (err) {
              toast(err.message, true);
            }
          });
          meta.appendChild(delBtn);
        }
        head.appendChild(meta);
      }
      block.appendChild(head);

      const grid = document.createElement('div');
      grid.className = 'bookmark-grid';
      bindCategoryDropZone(grid, cat.id);
      items.forEach((bm) => grid.appendChild(renderBookmarkCard(bm)));
      if (!items.length) {
        const empty = document.createElement('div');
        empty.style.cssText = 'color:var(--text-muted);font-size:0.9rem;grid-column:1/-1';
        empty.textContent = isAuth ? 'No bookmarks yet — drop here' : 'No bookmarks yet';
        grid.appendChild(empty);
      }
      block.appendChild(grid);
      els.categoriesRoot.appendChild(block);
    });

    if (!cats.length) {
      els.emptyState.classList.remove('hidden');
      els.emptyState.textContent = 'No categories on this tab';
    } else if (!any) {
      els.emptyState.classList.remove('hidden');
      els.emptyState.textContent = filtering
        ? 'No bookmarks match your search'
        : 'No bookmarks to show on this tab';
    } else {
      els.emptyState.classList.add('hidden');
    }
  }

  function renderBookmarkCard(bm) {
    const card = document.createElement('div');
    card.className = 'bookmark-card';
    card.dataset.bookmarkId = bm.id;

    const link = document.createElement('a');
    link.href = bm.url;
    link.target = '_blank';
    link.rel = 'noopener noreferrer';
    link.innerHTML = `
      <img class="icon" src="${escapeAttr(iconOf(bm))}" alt="">
      <div class="label">${escapeHtml(bm.title)}</div>
    `;
    link.addEventListener('click', (e) => {
      if (state.justDragged) {
        e.preventDefault();
        e.stopPropagation();
      }
    });
    card.appendChild(link);

    if (bm.visibility && bm.visibility !== 'public') {
      const badge = document.createElement('span');
      badge.className = 'vis-badge';
      badge.textContent = visLabel(bm.visibility);
      card.appendChild(badge);
    }

    if (isAuth && bm.can_edit) {
      card.draggable = true;
      card.title = 'Drag to another category or tab';
      card.addEventListener('dragstart', (e) => {
        hideCategoryPicker();
        state.dragBookmarkId = bm.id;
        state.justDragged = false;
        card.classList.add('dragging');
        e.dataTransfer.effectAllowed = 'move';
        e.dataTransfer.setData(DND_TYPE, String(bm.id));
        e.dataTransfer.setData('text/plain', String(bm.id));
        try {
          e.dataTransfer.setDragImage(card, card.offsetWidth / 2, card.offsetHeight / 2);
        } catch (_) { /* ignore */ }
      });
      card.addEventListener('dragend', () => {
        card.classList.remove('dragging');
        clearDragOver();
        state.dragBookmarkId = null;
        state.justDragged = true;
        setTimeout(() => { state.justDragged = false; }, 120);
      });

      const actions = document.createElement('div');
      actions.className = 'card-actions';
      const edit = document.createElement('button');
      edit.type = 'button';
      edit.className = 'icon-btn';
      edit.title = 'Edit';
      edit.textContent = '✎';
      edit.draggable = false;
      edit.addEventListener('click', (e) => {
        e.preventDefault();
        e.stopPropagation();
        openBookmarkModal(bm);
      });
      const del = document.createElement('button');
      del.type = 'button';
      del.className = 'icon-btn danger';
      del.title = 'Delete';
      del.textContent = '✕';
      del.draggable = false;
      del.addEventListener('click', async (e) => {
        e.preventDefault();
        e.stopPropagation();
        if (!confirm(`Delete bookmark "${bm.title}"?`)) return;
        try {
          await api('/api/bookmarks.php', { method: 'DELETE', body: { id: bm.id } });
          await reloadAll();
          toast('Bookmark deleted');
        } catch (err) {
          toast(err.message, true);
        }
      });
      actions.append(edit, del);
      card.appendChild(actions);
    }
    return card;
  }

  function escapeHtml(s) {
    return String(s ?? '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }
  function escapeAttr(s) {
    return escapeHtml(s).replace(/'/g, '&#39;');
  }

  async function loadGroups() {
    if (!isAuth) {
      state.groups = [];
      return;
    }
    try {
      state.groups = await api('/api/groups.php');
    } catch {
      state.groups = [];
    }
  }

  function fillCategorySelect(selectedId) {
    const sel = document.getElementById('bmCategory');
    sel.innerHTML = '';
    state.categories.forEach((c) => {
      const opt = document.createElement('option');
      opt.value = c.id;
      opt.textContent = c.name + (c.is_global == 1 ? ' (global)' : '');
      if (String(c.id) === String(selectedId)) opt.selected = true;
      sel.appendChild(opt);
    });
  }

  function fillGroups(selectedIds = []) {
    const box = document.getElementById('bmGroups');
    box.innerHTML = '';
    if (!state.groups.length) {
      box.innerHTML = '<div style="color:var(--text-muted);font-size:0.85rem">No groups available</div>';
      return;
    }
    state.groups.forEach((g) => {
      const label = document.createElement('label');
      const cb = document.createElement('input');
      cb.type = 'checkbox';
      cb.value = g.id;
      cb.checked = selectedIds.map(String).includes(String(g.id));
      label.append(cb, document.createTextNode(' ' + g.name));
      box.appendChild(label);
    });
  }

  function toggleGroupsVisibility() {
    const v = document.getElementById('bmVisibility').value;
    document.getElementById('bmGroupsWrap').classList.toggle('hidden', v !== 'share');
  }

  function openBookmarkModal(bm = null, categoryId = null) {
    document.getElementById('bookmarkModalTitle').textContent = bm ? 'Edit bookmark' : 'Add bookmark';
    document.getElementById('bmId').value = bm ? bm.id : '';
    document.getElementById('bmTitle').value = bm ? bm.title : '';
    document.getElementById('bmUrl').value = bm ? bm.url : '';
    document.getElementById('bmVisibility').value = bm ? bm.visibility : 'private';
    document.getElementById('bmIconPath').value = bm?.icon_path || '';
    document.getElementById('bmIconFile').value = '';
    state.pendingIconPath = bm?.icon_path || null;
    state.clearIcon = false;
    const preview = document.getElementById('bmIconPreview');
    preview.src = bm ? iconOf(bm) : letterSrc('?');
    fillCategorySelect(bm ? bm.category_id : (categoryId || state.categories[0]?.id));
    fillGroups(bm?.group_ids || []);
    toggleGroupsVisibility();
    openModal(els.bookmarkModal);
  }

  document.getElementById('bmVisibility')?.addEventListener('change', toggleGroupsVisibility);

  document.getElementById('btnAddBookmark')?.addEventListener('click', () => openBookmarkModal());

  document.getElementById('bmUploadIcon')?.addEventListener('click', async () => {
    const fileInput = document.getElementById('bmIconFile');
    if (!fileInput.files?.length) {
      toast('Please choose an icon file first', true);
      return;
    }
    const fd = new FormData();
    fd.append('icon', fileInput.files[0]);
    const bmId = document.getElementById('bmId').value;
    if (bmId) fd.append('bookmark_id', bmId);
    try {
      const data = await api('/api/icons.php', { method: 'POST', body: fd });
      state.pendingIconPath = data.icon_path;
      state.clearIcon = false;
      document.getElementById('bmIconPath').value = data.icon_path;
      document.getElementById('bmIconPreview').src = data.icon_src;
      toast('Icon uploaded');
    } catch (err) {
      toast(err.message, true);
    }
  });

  document.getElementById('bmClearIcon')?.addEventListener('click', async () => {
    const bmId = document.getElementById('bmId').value;
    try {
      if (bmId) {
        await api('/api/icons.php', { method: 'DELETE', body: { bookmark_id: Number(bmId) } });
      } else if (state.pendingIconPath) {
        await api('/api/icons.php', { method: 'DELETE', body: { icon_path: state.pendingIconPath } });
      }
      state.pendingIconPath = null;
      state.clearIcon = true;
      document.getElementById('bmIconPath').value = '';
      document.getElementById('bmIconFile').value = '';
      document.getElementById('bmIconPreview').src = letterSrc(document.getElementById('bmTitle').value || '?');
      toast('Icon deleted');
    } catch (err) {
      toast(err.message, true);
    }
  });

  els.bookmarkForm?.addEventListener('submit', async (e) => {
    e.preventDefault();
    const id = document.getElementById('bmId').value;
    const groupIds = [...document.querySelectorAll('#bmGroups input:checked')].map((x) => Number(x.value));
    const payload = {
      title: document.getElementById('bmTitle').value.trim(),
      url: document.getElementById('bmUrl').value.trim(),
      category_id: Number(document.getElementById('bmCategory').value),
      visibility: document.getElementById('bmVisibility').value,
      group_ids: groupIds,
      icon_path: document.getElementById('bmIconPath').value || null,
      clear_icon: state.clearIcon,
    };
    try {
      if (id) {
        payload.id = Number(id);
        await api('/api/bookmarks.php', { method: 'PUT', body: payload });
        toast('Bookmark updated');
      } else {
        await api('/api/bookmarks.php', { method: 'POST', body: payload });
        toast('Bookmark added');
      }
      closeModal(els.bookmarkModal);
      await reloadAll();
    } catch (err) {
      toast(err.message, true);
    }
  });

  function renderManageLists() {
    const tabsList = document.getElementById('manageTabsList');
    const catsList = document.getElementById('manageCatsList');
    const catTabSel = document.getElementById('newCatTab');
    if (!tabsList) return;

    tabsList.innerHTML = '';
    state.tabs.forEach((t) => {
      const row = document.createElement('div');
      row.style.cssText = 'display:flex;justify-content:space-between;align-items:center;gap:8px;padding:4px 0';
      row.innerHTML = `<span>${escapeHtml(t.name)} ${t.is_global == 1 ? '<span class="badge">global</span>' : ''}</span>`;
      if (t.can_edit) {
        const actions = document.createElement('span');
        const ren = document.createElement('button');
        ren.type = 'button';
        ren.className = 'btn btn-sm';
        ren.textContent = 'Rename';
        ren.addEventListener('click', async () => {
          const name = prompt('Tab name', t.name);
          if (name == null || !name.trim()) return;
          try {
            await api('/api/tabs.php', { method: 'PUT', body: { id: t.id, name: name.trim() } });
            await reloadAll();
            renderManageLists();
            toast('Tab updated');
          } catch (err) {
            toast(err.message, true);
          }
        });
        const del = document.createElement('button');
        del.type = 'button';
        del.className = 'btn btn-sm btn-danger';
        del.textContent = 'Del';
        del.addEventListener('click', async () => {
          if (!confirm(`Delete tab "${t.name}"?`)) return;
          try {
            await api('/api/tabs.php', { method: 'DELETE', body: { id: t.id } });
            await reloadAll();
            renderManageLists();
            toast('Tab deleted');
          } catch (err) {
            toast(err.message, true);
          }
        });
        actions.append(ren, del);
        row.appendChild(actions);
      }
      tabsList.appendChild(row);
    });

    catTabSel.innerHTML = '';
    state.tabs.forEach((t) => {
      const opt = document.createElement('option');
      opt.value = t.id;
      opt.textContent = t.name;
      if (String(t.id) === String(state.activeTabId)) opt.selected = true;
      catTabSel.appendChild(opt);
    });

    catsList.innerHTML = '';
    state.categories.forEach((c) => {
      const row = document.createElement('div');
      row.style.cssText = 'display:flex;justify-content:space-between;align-items:center;gap:8px;padding:4px 0';
      row.innerHTML = `<span><span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:${escapeAttr(c.color)};margin-right:6px"></span>${escapeHtml(c.name)} ${c.is_global == 1 ? '<span class="badge">global</span>' : ''}</span>`;
      if (c.can_edit) {
        const del = document.createElement('button');
        del.type = 'button';
        del.className = 'btn btn-sm btn-danger';
        del.textContent = 'Del';
        del.addEventListener('click', async () => {
          if (!confirm(`Delete category "${c.name}"?`)) return;
          try {
            await api('/api/categories.php', { method: 'DELETE', body: { id: c.id } });
            await reloadAll();
            renderManageLists();
            toast('Category deleted');
          } catch (err) {
            toast(err.message, true);
          }
        });
        row.appendChild(del);
      }
      catsList.appendChild(row);
    });
  }

  document.getElementById('btnManage')?.addEventListener('click', () => {
    renderManageLists();
    openModal(els.manageModal);
  });

  document.getElementById('btnCreateTab')?.addEventListener('click', async () => {
    const name = document.getElementById('newTabName').value.trim();
    if (!name) return toast('Please enter a tab name', true);
    const is_global = !!document.getElementById('newTabGlobal')?.checked;
    try {
      await api('/api/tabs.php', { method: 'POST', body: { name, is_global } });
      document.getElementById('newTabName').value = '';
      await reloadAll();
      renderManageLists();
      toast('Tab added');
    } catch (err) {
      toast(err.message, true);
    }
  });

  document.getElementById('btnCreateCat')?.addEventListener('click', async () => {
    const name = document.getElementById('newCatName').value.trim();
    const color = document.getElementById('newCatColor').value;
    const tab_id = Number(document.getElementById('newCatTab').value) || null;
    const is_global = !!document.getElementById('newCatGlobal')?.checked;
    if (!name) return toast('Please enter a category name', true);
    try {
      await api('/api/categories.php', { method: 'POST', body: { name, color, tab_id, is_global } });
      document.getElementById('newCatName').value = '';
      await reloadAll();
      renderManageLists();
      toast('Category added');
    } catch (err) {
      toast(err.message, true);
    }
  });

  // Search: default bookmarks filter; optional web engines
  const searchForm = document.getElementById('searchForm');
  const searchEngine = document.getElementById('searchEngine');
  const searchInput = document.getElementById('searchInput');

  function updateSearchPlaceholder() {
    if (!searchInput) return;
    searchInput.placeholder = isBookmarkSearchMode()
      ? 'Search bookmarks…'
      : 'Search the web…';
  }

  function applyBookmarkSearch() {
    state.searchQuery = isBookmarkSearchMode() ? (searchInput?.value || '') : '';
    renderCategories();
  }

  searchEngine?.addEventListener('change', () => {
    updateSearchPlaceholder();
    if (isBookmarkSearchMode()) {
      applyBookmarkSearch();
    } else {
      state.searchQuery = '';
      renderCategories();
    }
  });

  searchInput?.addEventListener('input', () => {
    if (isBookmarkSearchMode()) {
      applyBookmarkSearch();
    }
  });

  searchForm?.addEventListener('submit', (e) => {
    const q = (searchInput?.value || '').trim();
    const engine = searchEngine?.value || 'bookmarks';

    if (engine === 'bookmarks') {
      e.preventDefault();
      applyBookmarkSearch();
      return;
    }

    if (!q) {
      e.preventDefault();
      return;
    }

    if (engine === 'google') {
      e.preventDefault();
      window.open('https://www.google.com/search?q=' + encodeURIComponent(q), '_blank', 'noopener');
    } else if (engine === 'bing') {
      e.preventDefault();
      window.open('https://www.bing.com/search?q=' + encodeURIComponent(q), '_blank', 'noopener');
    } else if (engine === 'duckduckgo') {
      e.preventDefault();
      window.open('https://duckduckgo.com/?q=' + encodeURIComponent(q), '_blank', 'noopener');
    }
  });

  updateSearchPlaceholder();

  function tickClock() {
    const now = new Date();
    if (els.footerDate) {
      els.footerDate.textContent = now.toLocaleDateString('en-US', {
        weekday: 'long', year: 'numeric', month: 'long', day: 'numeric',
      });
    }
    if (els.footerTime) {
      els.footerTime.textContent = now.toLocaleTimeString('en-US', { hour12: false });
    }
  }

  async function reloadAll() {
    const [tabs, categories, bookmarks] = await Promise.all([
      api('/api/tabs.php'),
      api('/api/categories.php'),
      api('/api/bookmarks.php'),
    ]);
    state.tabs = tabs;
    state.categories = categories;
    state.bookmarks = bookmarks;
    if (!state.activeTabId || !tabs.find((t) => String(t.id) === String(state.activeTabId))) {
      state.activeTabId = tabs[0]?.id ?? null;
    }
    renderTabs();
    renderCategories();
  }

  async function init() {
    tickClock();
    setInterval(tickClock, 1000);
    try {
      await loadGroups();
      await reloadAll();
    } catch (err) {
      toast(err.message, true);
    }
  }

  init();
})();
