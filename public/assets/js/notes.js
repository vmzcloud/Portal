(() => {
  const csrf = document.body.dataset.csrf || '';
  const toastEl = document.getElementById('toast');
  const VIEW_KEY = 'portal-notes-view';

  let notes = [];
  let groups = [];
  let selectedId = null;
  let viewMode = 'list';

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
    const d = new Date(String(s).replace(' ', 'T'));
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
    document.getElementById('notesCards').classList.toggle('hidden', viewMode !== 'cards');

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

  function syncVisibilityUi() {
    const share = document.getElementById('noteVisibility').value === 'share';
    document.getElementById('noteGroupsWrap').classList.toggle('hidden', !share);
  }

  document.getElementById('noteVisibility').addEventListener('change', syncVisibilityUi);

  function showEmpty() {
    document.getElementById('notesEmpty').classList.remove('hidden');
    document.getElementById('notesEditor').classList.add('hidden');
    selectedId = null;
  }

  function lockEditor(lock) {
    document.getElementById('noteTitle').disabled = !!lock;
    document.getElementById('noteBody').contentEditable = lock ? 'false' : 'true';
    document.getElementById('noteVisibility').disabled = !!lock;
    document.getElementById('noteSave').classList.toggle('hidden', !!lock);
    document.querySelectorAll('#notesToolbar button').forEach((b) => { b.disabled = !!lock; });
    document.querySelectorAll('#noteGroups input').forEach((b) => { b.disabled = !!lock; });
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
    syncVisibilityUi();

    const canEdit = !note || note.can_edit !== false;
    lockEditor(!canEdit);
    document.getElementById('noteDelete').classList.toggle('hidden', !(note && canEdit));

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
        <span class="notes-list-meta">
          <span class="badge ${esc(n.visibility)}">${esc(n.visibility)}</span>
          <span>${esc(fmtWhen(n.updated_at))}</span>
        </span>
      </button>
    `).join('');

    box.querySelectorAll('.notes-list-item').forEach((btn) => {
      btn.addEventListener('click', () => showEditorFor(Number(btn.dataset.id)));
    });
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
        <div class="notes-card-meta">
          <span class="badge ${esc(n.visibility)}">${esc(n.visibility)}</span>
          <span>${esc(fmtWhen(n.updated_at))}</span>
        </div>
      </button>
    `).join('');

    box.querySelectorAll('.notes-card').forEach((btn) => {
      btn.addEventListener('click', () => showEditorFor(Number(btn.dataset.id)));
    });
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
  document.getElementById('notesSearch').addEventListener('input', (e) => {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(() => {
      loadNotes(e.target.value.trim()).catch((err) => toast(err.message, true));
    }, 250);
  });

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
    document.execCommand(cmd, false, null);
  });

  document.getElementById('notesEditor').addEventListener('submit', async (e) => {
    e.preventDefault();
    const id = document.getElementById('noteId').value;
    const title = document.getElementById('noteTitle').value.trim();
    const body_html = document.getElementById('noteBody').innerHTML;
    const visibility = document.getElementById('noteVisibility').value;
    const group_ids = [...document.querySelectorAll('#noteGroups input:checked')].map((x) => Number(x.value));

    const payload = { title, body_html, visibility, group_ids };
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
      await loadNotes(document.getElementById('notesSearch').value.trim());
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
      await loadNotes(document.getElementById('notesSearch').value.trim());
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
