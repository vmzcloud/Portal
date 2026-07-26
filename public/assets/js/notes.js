(() => {
  const csrf = document.body.dataset.csrf || '';
  const toastEl = document.getElementById('toast');
  const VIEW_KEY = 'portal-notes-view';

  let notes = [];
  let groups = [];
  let selectedId = null;
  let viewMode = 'list';
  let editorTags = [];
  let tagsLocked = false;

  function toast(msg, isError = false) {
    toastEl.textContent = msg;
    toastEl.classList.toggle('error', !!isError);
    toastEl.classList.add('show');
    clearTimeout(toast._t);
    toast._t = setTimeout(() => toastEl.classList.remove('show'), 2600);
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
    const data = await res.json();
    if (!res.ok || data.ok === false) throw new Error(data.error || 'Request failed');
    return data.data;
  }

  function esc(s) {
    return String(s ?? '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  function fmtWhen(s) {
    if (!s) return '';
    let raw = String(s).trim().replace(' ', 'T');
    // SQLite datetime('now') is UTC without offset
    if (!/[zZ]|[+-]\d{2}:?\d{2}$/.test(raw)) raw += 'Z';
    const d = new Date(raw);
    if (Number.isNaN(d.getTime())) return String(s);
    return d.toLocaleString(undefined, {
      month: 'short', day: 'numeric', year: 'numeric',
      hour: '2-digit', minute: '2-digit',
    });
  }

  function getView() {
    try {
      const v = localStorage.getItem(VIEW_KEY);
      return v === 'cards' ? 'cards' : 'list';
    } catch {
      return 'list';
    }
  }

  function setView(mode) {
    viewMode = mode === 'cards' ? 'cards' : 'list';
    try {
      localStorage.setItem(VIEW_KEY, viewMode);
    } catch { /* ignore */ }

    const main = document.getElementById('notesMain');
    main.dataset.view = viewMode;
    document.getElementById('notesSidebar').classList.toggle('hidden', viewMode === 'cards');
    document.getElementById('notesEditorPane').classList.toggle('hidden', viewMode === 'cards' && !selectedId);
    document.getElementById('notesCardsWrap').classList.toggle('hidden', viewMode !== 'cards');

    document.getElementById('notesViewList').classList.toggle('btn-primary', viewMode === 'list');
    document.getElementById('notesViewCards').classList.toggle('btn-primary', viewMode === 'cards');

    if (viewMode === 'cards') {
      renderCards();
      if (selectedId) {
        document.getElementById('notesEditorPane').classList.remove('hidden');
        document.getElementById('notesEditorPane').classList.add('notes-editor-overlay');
      } else {
        document.getElementById('notesEditorPane').classList.add('hidden');
        document.getElementById('notesEditorPane').classList.remove('notes-editor-overlay');
      }
    } else {
      document.getElementById('notesEditorPane').classList.remove('hidden', 'notes-editor-overlay');
      renderList();
      if (selectedId) showEditorFor(selectedId);
      else showEmpty();
    }
  }

  function fillGroups(selected = []) {
    const box = document.getElementById('noteGroups');
    box.innerHTML = groups.map((g) => `
      <label>
        <input type="checkbox" value="${g.id}" ${selected.map(String).includes(String(g.id)) ? 'checked' : ''}>
        ${esc(g.name)}
      </label>
    `).join('') || '<div style="color:var(--text-muted);font-size:0.85rem">No groups</div>';
  }

  function normalizeTag(raw) {
    let name = String(raw ?? '').trim().toLowerCase();
    if (name.startsWith('#')) name = name.replace(/^#+/, '');
    name = name.trim();
    if (!name || name.length > 40) return null;
    if (!/^[a-z0-9][a-z0-9_-]*$/.test(name)) return null;
    return name;
  }

  function renderEditorTags() {
    const box = document.getElementById('noteTagsChips');
    box.innerHTML = editorTags.map((t) => `
      <span class="notes-tag-chip" data-tag="${esc(t)}">
        #${esc(t)}
        ${tagsLocked ? '' : `<button type="button" class="notes-tag-remove" data-tag="${esc(t)}" aria-label="Remove #${esc(t)}">×</button>`}
      </span>
    `).join('');
    box.querySelectorAll('.notes-tag-remove').forEach((btn) => {
      btn.addEventListener('click', (e) => {
        e.preventDefault();
        e.stopPropagation();
        if (tagsLocked) return;
        const tag = btn.dataset.tag;
        editorTags = editorTags.filter((t) => t !== tag);
        renderEditorTags();
      });
    });
  }

  function setEditorTags(tags = []) {
    const seen = new Set();
    editorTags = [];
    for (const raw of tags) {
      const name = normalizeTag(raw);
      if (!name || seen.has(name)) continue;
      seen.add(name);
      editorTags.push(name);
      if (editorTags.length >= 20) break;
    }
    renderEditorTags();
  }

  function addEditorTag(raw) {
    const name = normalizeTag(raw);
    if (!name || tagsLocked) return false;
    if (editorTags.includes(name)) return false;
    if (editorTags.length >= 20) {
      toast('Maximum 20 hashtags', true);
      return false;
    }
    editorTags.push(name);
    renderEditorTags();
    return true;
  }

  function tagsHtml(tags = []) {
    if (!tags.length) return '';
    return `<span class="notes-tags-inline">${tags.map((t) =>
      `<span class="notes-tag-chip notes-tag-filter" data-tag="${esc(t)}">#${esc(t)}</span>`
    ).join('')}</span>`;
  }

  function getSearchQuery() {
    const list = document.getElementById('notesSearch');
    const cards = document.getElementById('notesCardsSearch');
    const q = (viewMode === 'cards' ? cards : list)?.value ?? list?.value ?? '';
    return String(q).trim();
  }

  function setSearchQuery(q) {
    const val = String(q ?? '');
    const list = document.getElementById('notesSearch');
    const cards = document.getElementById('notesCardsSearch');
    if (list) list.value = val;
    if (cards) cards.value = val;
  }

  function filterByTag(tag) {
    const name = normalizeTag(tag);
    if (!name) return;
    setSearchQuery(`#${name}`);
    loadNotes(`#${name}`).catch((err) => toast(err.message, true));
  }

  function bindTagFilters(root) {
    root.querySelectorAll('.notes-tag-filter').forEach((el) => {
      el.addEventListener('click', (e) => {
        e.preventDefault();
        e.stopPropagation();
        filterByTag(el.dataset.tag);
      });
    });
  }

  function syncVisibilityUi() {
    const share = document.getElementById('noteVisibility').value === 'share';
    document.getElementById('noteGroupsWrap').classList.toggle('hidden', !share);
  }

  document.getElementById('noteVisibility').addEventListener('change', syncVisibilityUi);

  const noteTagInput = document.getElementById('noteTagInput');
  noteTagInput.addEventListener('keydown', (e) => {
    if (tagsLocked) return;
    if (e.key === 'Enter' || e.key === ',') {
      e.preventDefault();
      const v = noteTagInput.value;
      if (addEditorTag(v)) noteTagInput.value = '';
      else if (v.trim()) toast('Invalid hashtag (letters, numbers, _ -)', true);
      return;
    }
    if (e.key === 'Backspace' && !noteTagInput.value && editorTags.length) {
      editorTags.pop();
      renderEditorTags();
    }
  });
  noteTagInput.addEventListener('blur', () => {
    if (tagsLocked) return;
    const v = noteTagInput.value;
    if (!v.trim()) return;
    if (addEditorTag(v)) noteTagInput.value = '';
  });
  document.getElementById('noteTags').addEventListener('click', () => {
    if (!tagsLocked) noteTagInput.focus();
  });

  function showEmpty() {
    document.getElementById('notesEmpty').classList.remove('hidden');
    document.getElementById('notesEditor').classList.add('hidden');
    selectedId = null;
    closeHistoryPanel();
    document.getElementById('noteHistory')?.classList.add('hidden');
  }

  function lockEditor(lock) {
    tagsLocked = !!lock;
    document.getElementById('noteTitle').disabled = !!lock;
    document.getElementById('noteBody').contentEditable = lock ? 'false' : 'true';
    document.getElementById('noteVisibility').disabled = !!lock;
    document.getElementById('noteSave').classList.toggle('hidden', !!lock);
    document.getElementById('noteTagInput').disabled = !!lock;
    document.getElementById('noteTags').classList.toggle('is-locked', !!lock);
    document.querySelectorAll('#notesToolbar button').forEach((b) => { b.disabled = !!lock; });
    const colorInput = document.getElementById('noteFontColor');
    if (colorInput) colorInput.disabled = !!lock;
    document.querySelectorAll('#noteGroups input').forEach((b) => { b.disabled = !!lock; });
    renderEditorTags();
  }

  function escapeHtml(s) {
    return String(s ?? '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  function insertCodeBlock() {
    const body = document.getElementById('noteBody');
    body.focus();
    const sel = window.getSelection();
    if (!sel || sel.rangeCount === 0) return;
    const range = sel.getRangeAt(0);
    if (!body.contains(range.commonAncestorContainer)) return;

    let text = range.toString();
    if (!text) text = '';
    const html = `<pre><code>${escapeHtml(text) || '<br>'}</code></pre><p><br></p>`;
    range.deleteContents();
    const tmp = document.createElement('div');
    tmp.innerHTML = html;
    const frag = document.createDocumentFragment();
    let node;
    let last = null;
    while ((node = tmp.firstChild)) {
      last = frag.appendChild(node);
    }
    range.insertNode(frag);
    if (last) {
      const code = last.previousSibling?.querySelector?.('code') || last.querySelector?.('code');
      if (code) {
        const r = document.createRange();
        r.selectNodeContents(code);
        r.collapse(false);
        sel.removeAllRanges();
        sel.addRange(r);
      }
    }
  }

  function applyFontColor(color) {
    const body = document.getElementById('noteBody');
    body.focus();
    try {
      document.execCommand('styleWithCSS', false, true);
    } catch { /* ignore */ }
    document.execCommand('foreColor', false, color);
  }

  function closeHistoryPanel() {
    document.getElementById('noteHistoryPanel')?.classList.add('hidden');
  }

  function renderHistoryList(versions, canEdit) {
    const box = document.getElementById('noteHistoryList');
    if (!versions.length) {
      box.innerHTML = '<div class="notes-history-empty">No previous versions yet. Save changes to create history.</div>';
      return;
    }
    box.innerHTML = versions.map((v) => `
      <div class="notes-history-item" data-version-id="${v.id}">
        <div class="notes-history-item-top">
          <div class="notes-history-item-main">
            <div class="notes-history-title">${esc(v.title || 'Untitled')}</div>
            <div class="notes-history-preview">${esc(v.preview || '—')}</div>
            <div class="notes-history-meta">
              ${esc(fmtWhen(v.created_at))}
              ${v.created_by_name ? ` · ${esc(v.created_by_name)}` : ''}
            </div>
          </div>
          <div class="notes-history-actions">
            <button type="button" class="btn btn-sm" data-preview-version="${v.id}">Preview</button>
            ${canEdit ? `<button type="button" class="btn btn-sm btn-primary" data-restore-version="${v.id}">Restore</button>` : ''}
          </div>
        </div>
        <div class="notes-history-body hidden" data-version-body="${v.id}"></div>
      </div>
    `).join('');

    box.querySelectorAll('[data-preview-version]').forEach((btn) => {
      btn.addEventListener('click', async () => {
        const vid = Number(btn.dataset.previewVersion);
        const bodyEl = box.querySelector(`[data-version-body="${vid}"]`);
        if (!bodyEl) return;
        if (!bodyEl.classList.contains('hidden') && bodyEl.dataset.loaded === '1') {
          bodyEl.classList.add('hidden');
          return;
        }
        try {
          const noteId = Number(document.getElementById('noteId').value);
          const version = await api(`/api/notes/versions.php?note_id=${noteId}&id=${vid}`);
          bodyEl.innerHTML = version.body_html || '<em style="color:var(--text-muted)">Empty</em>';
          bodyEl.dataset.loaded = '1';
          bodyEl.classList.remove('hidden');
        } catch (err) {
          toast(err.message, true);
        }
      });
    });

    box.querySelectorAll('[data-restore-version]').forEach((btn) => {
      btn.addEventListener('click', async () => {
        const vid = Number(btn.dataset.restoreVersion);
        if (!confirm('Restore this version? Current title and body will be saved to history first if they differ.')) return;
        try {
          const noteId = Number(document.getElementById('noteId').value);
          const saved = await api('/api/notes/versions.php', {
            method: 'POST',
            body: { note_id: noteId, version_id: vid },
          });
          toast('Version restored');
          await loadNotes(getSearchQuery());
          selectedId = saved.id;
          showEditorFor(saved.id);
          closeHistoryPanel();
        } catch (err) {
          toast(err.message, true);
        }
      });
    });
  }

  async function openHistoryPanel() {
    const noteId = Number(document.getElementById('noteId').value);
    if (!noteId) return;
    const panel = document.getElementById('noteHistoryPanel');
    const list = document.getElementById('noteHistoryList');
    panel.classList.remove('hidden');
    list.innerHTML = '<div class="notes-history-empty">Loading…</div>';
    const note = notes.find((n) => String(n.id) === String(noteId));
    const canEdit = !note || note.can_edit !== false;
    try {
      const versions = await api(`/api/notes/versions.php?note_id=${noteId}`);
      renderHistoryList(versions || [], canEdit);
    } catch (err) {
      list.innerHTML = `<div class="notes-history-empty">${esc(err.message)}</div>`;
    }
  }

  function loadNoteIntoEditor(note) {
    selectedId = note ? note.id : null;
    document.getElementById('notesEmpty').classList.add('hidden');
    document.getElementById('notesEditor').classList.remove('hidden');
    document.getElementById('noteId').value = note?.id || '';
    document.getElementById('noteTitle').value = note?.title || '';
    document.getElementById('noteBody').innerHTML = note?.body_html || '';
    document.getElementById('noteVisibility').value = note?.visibility === 'share' ? 'share' : 'private';
    fillGroups(note?.group_ids || []);
    setEditorTags(note?.tags || []);
    noteTagInput.value = '';
    syncVisibilityUi();
    closeHistoryPanel();

    const canEdit = !note || note.can_edit !== false;
    lockEditor(!canEdit);
    document.getElementById('noteDelete').classList.toggle('hidden', !(note && canEdit));
    document.getElementById('noteHistory').classList.toggle('hidden', !(note && note.id));

    const meta = [];
    if (note?.owner_name) meta.push(`Owner: ${note.owner_name}`);
    if (note?.updated_at) meta.push(`Updated ${fmtWhen(note.updated_at)}`);
    if (note && !canEdit) meta.push('Read only');
    document.getElementById('noteMetaInfo').textContent = meta.join(' · ');

    highlightSelection();
  }

  function showEditorFor(id) {
    const note = notes.find((n) => String(n.id) === String(id));
    if (!note) {
      showEmpty();
      return;
    }
    loadNoteIntoEditor(note);
    if (viewMode === 'cards') {
      document.getElementById('notesEditorPane').classList.remove('hidden');
      document.getElementById('notesEditorPane').classList.add('notes-editor-overlay');
    }
  }

  function highlightSelection() {
    document.querySelectorAll('.notes-list-item').forEach((el) => {
      el.classList.toggle('active', String(el.dataset.id) === String(selectedId));
    });
    document.querySelectorAll('.notes-card').forEach((el) => {
      el.classList.toggle('active', String(el.dataset.id) === String(selectedId));
    });
  }

  function renderList() {
    const box = document.getElementById('notesList');
    if (!notes.length) {
      box.innerHTML = '<div class="notes-list-empty">No notes yet</div>';
      return;
    }
    box.innerHTML = notes.map((n) => `
      <button type="button" class="notes-list-item ${String(n.id) === String(selectedId) ? 'active' : ''}" data-id="${n.id}">
        <span class="notes-list-title">${esc(n.title || 'Untitled')}</span>
        <span class="notes-list-preview">${esc(n.preview || '')}</span>
        ${tagsHtml(n.tags || [])}
        <span class="notes-list-meta">
          <span class="badge ${esc(n.visibility)}">${esc(n.visibility)}</span>
          <span>${esc(fmtWhen(n.updated_at))}</span>
        </span>
      </button>
    `).join('');

    box.querySelectorAll('.notes-list-item').forEach((btn) => {
      btn.addEventListener('click', () => showEditorFor(Number(btn.dataset.id)));
    });
    bindTagFilters(box);
  }

  function renderCards() {
    const box = document.getElementById('notesCards');
    if (!notes.length) {
      box.innerHTML = '<div class="empty-state">No notes yet. Click + Note to create one.</div>';
      return;
    }
    box.innerHTML = notes.map((n) => `
      <button type="button" class="notes-card ${String(n.id) === String(selectedId) ? 'active' : ''}" data-id="${n.id}">
        <div class="notes-card-title">${esc(n.title || 'Untitled')}</div>
        <div class="notes-card-preview">${esc(n.preview || '—')}</div>
        ${tagsHtml(n.tags || [])}
        <div class="notes-card-meta">
          <span class="badge ${esc(n.visibility)}">${esc(n.visibility)}</span>
          <span>${esc(fmtWhen(n.updated_at))}</span>
        </div>
      </button>
    `).join('');

    box.querySelectorAll('.notes-card').forEach((btn) => {
      btn.addEventListener('click', () => showEditorFor(Number(btn.dataset.id)));
    });
    bindTagFilters(box);
  }

  function renderAll() {
    if (viewMode === 'cards') renderCards();
    else renderList();
    highlightSelection();
  }

  async function loadNotes(q = '') {
    const qs = q ? `?q=${encodeURIComponent(q)}` : '';
    notes = await api(`/api/notes/notes.php${qs}`);
    renderAll();
    if (selectedId && !notes.some((n) => String(n.id) === String(selectedId))) {
      selectedId = null;
      if (viewMode === 'list') showEmpty();
      else {
        document.getElementById('notesEditorPane').classList.add('hidden');
        document.getElementById('notesEditorPane').classList.remove('notes-editor-overlay');
      }
    } else if (selectedId && viewMode === 'list') {
      showEditorFor(selectedId);
    }
  }

  let searchTimer = null;
  function onSearchInput(e) {
    const q = e.target.value;
    const list = document.getElementById('notesSearch');
    const cards = document.getElementById('notesCardsSearch');
    if (e.target !== list && list) list.value = q;
    if (e.target !== cards && cards) cards.value = q;
    clearTimeout(searchTimer);
    searchTimer = setTimeout(() => {
      loadNotes(String(q).trim()).catch((err) => toast(err.message, true));
    }, 250);
  }
  document.getElementById('notesSearch').addEventListener('input', onSearchInput);
  document.getElementById('notesCardsSearch').addEventListener('input', onSearchInput);

  document.getElementById('notesViewList').addEventListener('click', () => setView('list'));
  document.getElementById('notesViewCards').addEventListener('click', () => setView('cards'));

  document.getElementById('btnNewNote').addEventListener('click', () => {
    selectedId = null;
    loadNoteIntoEditor({
      id: '',
      title: '',
      body_html: '',
      visibility: 'private',
      group_ids: [],
      tags: [],
      can_edit: true,
    });
    document.getElementById('noteId').value = '';
    document.getElementById('noteDelete').classList.add('hidden');
    document.getElementById('noteMetaInfo').textContent = 'New note';
    if (viewMode === 'cards') {
      document.getElementById('notesEditorPane').classList.remove('hidden');
      document.getElementById('notesEditorPane').classList.add('notes-editor-overlay');
    }
    document.getElementById('noteTitle').focus();
    highlightSelection();
  });

  // Close overlay editor when clicking backdrop in cards mode
  document.getElementById('notesEditorPane').addEventListener('click', (e) => {
    if (viewMode !== 'cards') return;
    if (e.target.id === 'notesEditorPane') {
      document.getElementById('notesEditorPane').classList.add('hidden');
      document.getElementById('notesEditorPane').classList.remove('notes-editor-overlay');
      selectedId = null;
      highlightSelection();
    }
  });

  document.getElementById('notesToolbar').addEventListener('click', (e) => {
    const btn = e.target.closest('[data-cmd]');
    if (!btn || btn.disabled) return;
    e.preventDefault();
    const cmd = btn.dataset.cmd;
    const body = document.getElementById('noteBody');
    body.focus();

    if (cmd === 'createLink') {
      const url = prompt('Link URL', 'https://');
      if (!url) return;
      document.execCommand('createLink', false, url);
      return;
    }
    if (cmd === 'formatBlock') {
      document.execCommand('formatBlock', false, btn.dataset.value || 'p');
      return;
    }
    if (cmd === 'codeBlock') {
      insertCodeBlock();
      return;
    }
    document.execCommand(cmd, false, null);
  });

  const noteFontColor = document.getElementById('noteFontColor');
  const noteColorLabel = document.querySelector('.notes-color-label');
  noteFontColor?.addEventListener('input', (e) => {
    if (e.target.disabled) return;
    if (noteColorLabel) noteColorLabel.style.borderBottomColor = e.target.value;
    applyFontColor(e.target.value);
  });
  if (noteFontColor && noteColorLabel) {
    noteColorLabel.style.borderBottomColor = noteFontColor.value;
  }

  document.getElementById('noteHistory')?.addEventListener('click', () => {
    openHistoryPanel().catch((err) => toast(err.message, true));
  });
  document.getElementById('noteHistoryClose')?.addEventListener('click', closeHistoryPanel);

  document.getElementById('notesEditor').addEventListener('submit', async (e) => {
    e.preventDefault();
    const id = document.getElementById('noteId').value;
    const title = document.getElementById('noteTitle').value.trim();
    const body_html = document.getElementById('noteBody').innerHTML;
    const visibility = document.getElementById('noteVisibility').value;
    const group_ids = [...document.querySelectorAll('#noteGroups input:checked')].map((x) => Number(x.value));
    if (noteTagInput.value.trim()) {
      addEditorTag(noteTagInput.value);
      noteTagInput.value = '';
    }
    const tags = [...editorTags];

    const payload = { title, body_html, visibility, group_ids, tags };
    try {
      let saved;
      if (id) {
        payload.id = Number(id);
        saved = await api('/api/notes/notes.php', { method: 'PUT', body: payload });
        toast('Note saved');
      } else {
        saved = await api('/api/notes/notes.php', { method: 'POST', body: payload });
        toast('Note created');
      }
      await loadNotes(getSearchQuery());
      selectedId = saved.id;
      showEditorFor(saved.id);
    } catch (err) {
      toast(err.message, true);
    }
  });

  document.getElementById('noteDelete').addEventListener('click', async () => {
    const id = Number(document.getElementById('noteId').value);
    if (!id || !confirm('Delete this note?')) return;
    try {
      await api('/api/notes/notes.php', { method: 'DELETE', body: { id } });
      toast('Note deleted');
      selectedId = null;
      await loadNotes(getSearchQuery());
      if (viewMode === 'list') showEmpty();
      else {
        document.getElementById('notesEditorPane').classList.add('hidden');
        document.getElementById('notesEditorPane').classList.remove('notes-editor-overlay');
      }
    } catch (err) {
      toast(err.message, true);
    }
  });

  async function init() {
    try {
      const meta = await api('/api/notes/meta.php');
      groups = meta.groups || [];
      setView(getView());
      await loadNotes();
      showEmpty();
    } catch (err) {
      toast(err.message, true);
    }
  }

  init();
})();
