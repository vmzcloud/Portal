(() => {
  const csrf = document.body.dataset.csrf || '';
  const myId = Number(document.body.dataset.userId || 0);
  const toastEl = document.getElementById('toast');
  const VIEW_KEY = 'portal-todo-view';

  let tasks = [];
  let users = [];
  let groups = [];
  let dragId = null;
  let viewMode = 'board';

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

  function todayYmd() {
    const d = new Date();
    const p = (n) => String(n).padStart(2, '0');
    return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())}`;
  }

  function isOverdue(due, status) {
    if (!due || status === 'done') return false;
    return String(due) < todayYmd();
  }

  function fmtDue(due) {
    if (!due) return '';
    try {
      const [y, m, day] = String(due).split('-').map(Number);
      const d = new Date(y, m - 1, day);
      return d.toLocaleDateString(undefined, { month: 'short', day: 'numeric' });
    } catch {
      return String(due);
    }
  }

  function fmtWhen(s) {
    if (!s) return '';
    let raw = String(s).trim().replace(' ', 'T');
    if (!/[zZ]|[+-]\d{2}:?\d{2}$/.test(raw)) raw += 'Z';
    const d = new Date(raw);
    if (Number.isNaN(d.getTime())) return String(s);
    return d.toLocaleString(undefined, {
      month: 'short',
      day: 'numeric',
      hour: '2-digit',
      minute: '2-digit',
    });
  }

  function openModal() {
    document.getElementById('taskModal').classList.add('open');
  }

  function closeModal() {
    document.getElementById('taskModal').classList.remove('open');
  }

  function fillUsersSelect(selected) {
    const sel = document.getElementById('taskAssignee');
    const cur = selected == null || selected === '' ? '' : String(selected);
    sel.innerHTML = '<option value="">Unassigned</option>' + users.map((u) =>
      `<option value="${u.id}"${String(u.id) === cur ? ' selected' : ''}>${esc(u.username)}</option>`
    ).join('');
  }

  function fillGroups(selectedIds) {
    const box = document.getElementById('taskGroups');
    const set = new Set((selectedIds || []).map(Number));
    if (!groups.length) {
      box.innerHTML = '<div class="form-hint">No groups available</div>';
      return;
    }
    box.innerHTML = groups.map((g) => `
      <label class="checkbox-item">
        <input type="checkbox" value="${g.id}"${set.has(g.id) ? ' checked' : ''}>
        <span>${esc(g.name)}</span>
      </label>
    `).join('');
  }

  function setFormLocked(lock, statusOnly) {
    const fields = ['taskTitle', 'taskDescription', 'taskDue', 'taskAssignee', 'taskVisibility'];
    fields.forEach((id) => {
      const el = document.getElementById(id);
      if (el) el.disabled = !!lock;
    });
    document.querySelectorAll('#taskGroups input').forEach((el) => {
      el.disabled = !!lock;
    });
    document.getElementById('taskStatus').disabled = !!lock && !statusOnly;
    document.getElementById('taskSave').classList.toggle('hidden', lock && !statusOnly);
    document.getElementById('taskSave').disabled = lock && !statusOnly;
    if (statusOnly && lock) {
      document.getElementById('taskSave').classList.remove('hidden');
      document.getElementById('taskSave').disabled = false;
      document.getElementById('taskStatus').disabled = false;
    }
  }

  function toggleGroupsVisibility() {
    const share = document.getElementById('taskVisibility').value === 'share';
    document.getElementById('taskGroupsWrap').classList.toggle('hidden', !share);
  }

  function applyViewMode() {
    const board = viewMode === 'board';
    document.getElementById('todoBoard').classList.toggle('hidden', !board);
    document.getElementById('todoArchive').classList.toggle('hidden', board);
    document.getElementById('todoViewBoard').classList.toggle('btn-primary', board);
    document.getElementById('todoViewArchive').classList.toggle('btn-primary', !board);
    document.getElementById('btnNewTask').classList.toggle('hidden', !board);
    document.getElementById('btnArchiveAllDone').classList.toggle('hidden', !board);
    try {
      localStorage.setItem(VIEW_KEY, viewMode);
    } catch {
      /* ignore */
    }
  }

  function setView(mode) {
    viewMode = mode === 'archive' ? 'archive' : 'board';
    applyViewMode();
    loadTasks().catch((err) => toast(err.message, true));
  }

  function openCreate() {
    if (viewMode !== 'board') setView('board');
    document.getElementById('taskModalTitle').textContent = 'New task';
    document.getElementById('taskId').value = '';
    document.getElementById('taskTitle').value = '';
    document.getElementById('taskDescription').value = '';
    document.getElementById('taskStatus').value = 'todo';
    document.getElementById('taskDue').value = '';
    fillUsersSelect('');
    document.getElementById('taskVisibility').value = 'private';
    fillGroups([]);
    toggleGroupsVisibility();
    document.getElementById('taskMetaInfo').textContent = '';
    document.getElementById('taskDelete').classList.add('hidden');
    document.getElementById('taskArchive').classList.add('hidden');
    document.getElementById('taskUnarchive').classList.add('hidden');
    setFormLocked(false, false);
    openModal();
    document.getElementById('taskTitle').focus();
  }

  function openEdit(task) {
    const archived = !!task.archived;
    document.getElementById('taskModalTitle').textContent = archived
      ? 'Archived task'
      : (task.can_edit ? 'Edit task' : 'Task');
    document.getElementById('taskId').value = String(task.id);
    document.getElementById('taskTitle').value = task.title || '';
    document.getElementById('taskDescription').value = task.description || '';
    document.getElementById('taskStatus').value = task.status || 'todo';
    document.getElementById('taskDue').value = task.due_date || '';
    fillUsersSelect(task.assignee_id);
    document.getElementById('taskVisibility').value = task.visibility || 'private';
    fillGroups(task.group_ids || []);
    toggleGroupsVisibility();

    const bits = [];
    if (task.owner_name) bits.push(`Owner: ${task.owner_name}`);
    if (task.assignee_name) bits.push(`Assignee: ${task.assignee_name}`);
    if (archived && task.archived_at) bits.push(`Archived: ${fmtWhen(task.archived_at)}`);
    document.getElementById('taskMetaInfo').textContent = bits.join(' · ');

    const del = document.getElementById('taskDelete');
    // Delete: owner/admin even when archived (can_edit is false for archived)
    const canDelete = Number(task.owner_id) === myId || document.body.dataset.admin === '1';
    del.classList.toggle('hidden', !canDelete);

    const showArchive = !archived && task.status === 'done' && !!task.can_archive;
    const showUnarchive = archived && !!task.can_archive;
    document.getElementById('taskArchive').classList.toggle('hidden', !showArchive);
    document.getElementById('taskUnarchive').classList.toggle('hidden', !showUnarchive);

    if (archived) {
      setFormLocked(true, false);
      document.getElementById('taskSave').classList.add('hidden');
      document.getElementById('taskStatus').disabled = true;
    } else {
      setFormLocked(!task.can_edit, !!task.can_status);
      if (!task.can_edit && task.can_status) {
        document.getElementById('taskStatus').disabled = false;
      }
    }
    openModal();
  }

  function cardHtml(t, opts = {}) {
    const archiveView = !!opts.archiveView;
    const overdue = isOverdue(t.due_date, t.status);
    const drag = !archiveView && t.can_status ? 'true' : 'false';
    const assignee = t.assignee_name
      ? `<span class="todo-chip">${esc(t.assignee_name)}</span>`
      : '<span class="todo-chip muted">Unassigned</span>';
    const due = t.due_date
      ? `<span class="todo-due${overdue ? ' overdue' : ''}">${esc(fmtDue(t.due_date))}</span>`
      : '';
    const mine = Number(t.owner_id) === myId ? '' : `<span class="todo-chip muted">${esc(t.owner_name || '')}</span>`;
    const archivedAt = archiveView && t.archived_at
      ? `<span class="todo-due">Archived ${esc(fmtWhen(t.archived_at))}</span>`
      : '';
    const archiveBtn = !archiveView && t.status === 'done' && t.can_archive
      ? `<button type="button" class="btn btn-sm todo-card-archive" data-archive-id="${t.id}" title="Archive">Archive</button>`
      : '';
    const unarchiveBtn = archiveView && t.can_archive
      ? `<button type="button" class="btn btn-sm todo-card-unarchive" data-unarchive-id="${t.id}" title="Unarchive">Unarchive</button>`
      : '';
    return `
      <article class="todo-card${overdue ? ' is-overdue' : ''}${archiveView ? ' is-archived' : ''}" draggable="${drag}" data-id="${t.id}" data-status="${esc(t.status)}">
        <div class="todo-card-title">${esc(t.title || 'Untitled')}</div>
        ${t.description ? `<div class="todo-card-desc">${esc(t.description).slice(0, 120)}</div>` : ''}
        <div class="todo-card-meta">
          ${assignee}
          ${mine}
          ${due}
          ${archivedAt}
          ${archiveBtn}
          ${unarchiveBtn}
        </div>
      </article>
    `;
  }

  function renderBoard() {
    const byStatus = { todo: [], in_progress: [], done: [] };
    tasks.forEach((t) => {
      const s = byStatus[t.status] ? t.status : 'todo';
      byStatus[s].push(t);
    });

    ['todo', 'in_progress', 'done'].forEach((status) => {
      const col = document.querySelector(`.todo-column-body[data-drop="${status}"]`);
      const list = byStatus[status] || [];
      col.innerHTML = list.length
        ? list.map((t) => cardHtml(t)).join('')
        : '<div class="todo-empty">No tasks</div>';
      const countEl = document.querySelector(`[data-count="${status}"]`);
      if (countEl) countEl.textContent = String(list.length);
    });

    const archivable = tasks.filter((t) => t.status === 'done' && t.can_archive);
    const bulk = document.getElementById('btnArchiveAllDone');
    bulk.classList.toggle('hidden', viewMode !== 'board' || archivable.length === 0);
    bulk.textContent = archivable.length ? `Archive done (${archivable.length})` : 'Archive done';

    bindCards();
  }

  function renderArchive() {
    const list = document.getElementById('todoArchiveList');
    const countEl = document.getElementById('todoArchiveCount');
    countEl.textContent = String(tasks.length);
    list.innerHTML = tasks.length
      ? tasks.map((t) => cardHtml(t, { archiveView: true })).join('')
      : '<div class="todo-empty">No archived tasks</div>';
    bindCards();
  }

  function render() {
    if (viewMode === 'archive') {
      renderArchive();
    } else {
      renderBoard();
    }
  }

  async function setArchived(id, archived) {
    const updated = await api('/api/todo/tasks.php', {
      method: 'PUT',
      body: { id, archived },
    });
    return updated;
  }

  function bindCards() {
    document.querySelectorAll('.todo-card').forEach((card) => {
      card.addEventListener('click', (e) => {
        if (e.target.closest('[data-archive-id], [data-unarchive-id]')) return;
        const id = Number(card.dataset.id);
        const t = tasks.find((x) => x.id === id);
        if (t) openEdit(t);
      });

      const archBtn = card.querySelector('[data-archive-id]');
      if (archBtn) {
        archBtn.addEventListener('click', async (e) => {
          e.stopPropagation();
          const id = Number(archBtn.dataset.archiveId);
          try {
            await setArchived(id, true);
            toast('Task archived');
            await loadTasks();
          } catch (err) {
            toast(err.message, true);
          }
        });
      }

      const unBtn = card.querySelector('[data-unarchive-id]');
      if (unBtn) {
        unBtn.addEventListener('click', async (e) => {
          e.stopPropagation();
          const id = Number(unBtn.dataset.unarchiveId);
          try {
            await setArchived(id, false);
            toast('Task restored to board');
            await loadTasks();
          } catch (err) {
            toast(err.message, true);
          }
        });
      }

      if (card.getAttribute('draggable') !== 'true') return;

      card.addEventListener('dragstart', (e) => {
        dragId = Number(card.dataset.id);
        card.classList.add('dragging');
        e.dataTransfer.effectAllowed = 'move';
        e.dataTransfer.setData('text/plain', String(dragId));
      });
      card.addEventListener('dragend', () => {
        card.classList.remove('dragging');
        dragId = null;
        document.querySelectorAll('.todo-column-body').forEach((c) => c.classList.remove('drag-over'));
      });
    });
  }

  function bindColumns() {
    document.querySelectorAll('.todo-column-body').forEach((col) => {
      col.addEventListener('dragover', (e) => {
        e.preventDefault();
        e.dataTransfer.dropEffect = 'move';
        col.classList.add('drag-over');
      });
      col.addEventListener('dragleave', () => col.classList.remove('drag-over'));
      col.addEventListener('drop', async (e) => {
        e.preventDefault();
        col.classList.remove('drag-over');
        const id = Number(e.dataTransfer.getData('text/plain') || dragId);
        const status = col.dataset.drop;
        if (!id || !status) return;
        const t = tasks.find((x) => x.id === id);
        if (!t || !t.can_status || t.status === status) return;
        try {
          const updated = await api('/api/todo/tasks.php', {
            method: 'PUT',
            body: { id, status },
          });
          const idx = tasks.findIndex((x) => x.id === id);
          if (idx >= 0) tasks[idx] = updated;
          render();
        } catch (err) {
          toast(err.message, true);
        }
      });
    });
  }

  async function loadMeta() {
    const meta = await api('/api/todo/meta.php');
    users = Array.isArray(meta.users) ? meta.users : [];
    groups = Array.isArray(meta.groups) ? meta.groups : [];
  }

  async function loadTasks() {
    const q = document.getElementById('todoSearch').value.trim();
    const filter = document.getElementById('todoFilter').value;
    const params = new URLSearchParams();
    if (q) params.set('q', q);
    if (filter) params.set('filter', filter);
    if (viewMode === 'archive') params.set('archived', '1');
    const qs = params.toString() ? `?${params}` : '';
    tasks = await api(`/api/todo/tasks.php${qs}`);
    if (!Array.isArray(tasks)) tasks = [];
    render();
  }

  document.getElementById('btnNewTask').addEventListener('click', openCreate);
  document.getElementById('taskCancel').addEventListener('click', closeModal);
  document.getElementById('taskModal').addEventListener('click', (e) => {
    if (e.target.id === 'taskModal') closeModal();
  });
  document.getElementById('taskVisibility').addEventListener('change', toggleGroupsVisibility);

  document.getElementById('todoViewBoard').addEventListener('click', () => setView('board'));
  document.getElementById('todoViewArchive').addEventListener('click', () => setView('archive'));

  document.getElementById('btnArchiveAllDone').addEventListener('click', async () => {
    const list = tasks.filter((t) => t.status === 'done' && t.can_archive && !t.archived);
    if (!list.length) return;
    if (!confirm(`Archive ${list.length} done task(s)?`)) return;
    let ok = 0;
    let fail = 0;
    for (const t of list) {
      try {
        await setArchived(t.id, true);
        ok += 1;
      } catch {
        fail += 1;
      }
    }
    toast(fail ? `Archived ${ok}, failed ${fail}` : `Archived ${ok} task(s)`, !!fail);
    await loadTasks().catch((err) => toast(err.message, true));
  });

  document.getElementById('taskArchive').addEventListener('click', async () => {
    const id = Number(document.getElementById('taskId').value || 0);
    if (!id) return;
    try {
      await setArchived(id, true);
      closeModal();
      toast('Task archived');
      await loadTasks();
    } catch (err) {
      toast(err.message, true);
    }
  });

  document.getElementById('taskUnarchive').addEventListener('click', async () => {
    const id = Number(document.getElementById('taskId').value || 0);
    if (!id) return;
    try {
      await setArchived(id, false);
      closeModal();
      toast('Task restored to board');
      await loadTasks();
    } catch (err) {
      toast(err.message, true);
    }
  });

  let searchTimer = null;
  document.getElementById('todoSearch').addEventListener('input', () => {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(() => {
      loadTasks().catch((err) => toast(err.message, true));
    }, 250);
  });
  document.getElementById('todoFilter').addEventListener('change', () => {
    loadTasks().catch((err) => toast(err.message, true));
  });

  document.getElementById('taskForm').addEventListener('submit', async (e) => {
    e.preventDefault();
    const id = Number(document.getElementById('taskId').value || 0);
    const existing = id ? tasks.find((t) => t.id === id) : null;
    if (existing?.archived) return;

    try {
      let saved;
      if (existing && !existing.can_edit && existing.can_status) {
        saved = await api('/api/todo/tasks.php', {
          method: 'PUT',
          body: { id, status: document.getElementById('taskStatus').value },
        });
      } else {
        const group_ids = [...document.querySelectorAll('#taskGroups input:checked')].map((x) => Number(x.value));
        const assigneeVal = document.getElementById('taskAssignee').value;
        const payload = {
          title: document.getElementById('taskTitle').value.trim(),
          description: document.getElementById('taskDescription').value.trim(),
          status: document.getElementById('taskStatus').value,
          due_date: document.getElementById('taskDue').value || null,
          assignee_id: assigneeVal ? Number(assigneeVal) : null,
          visibility: document.getElementById('taskVisibility').value,
          group_ids,
        };
        if (id) {
          payload.id = id;
          saved = await api('/api/todo/tasks.php', { method: 'PUT', body: payload });
        } else {
          saved = await api('/api/todo/tasks.php', { method: 'POST', body: payload });
        }
      }
      closeModal();
      toast(id ? 'Task saved' : 'Task created');
      await loadTasks();
      if (saved) {
        /* refreshed */
      }
    } catch (err) {
      toast(err.message, true);
    }
  });

  document.getElementById('taskDelete').addEventListener('click', async () => {
    const id = Number(document.getElementById('taskId').value || 0);
    if (!id || !confirm('Delete this task?')) return;
    try {
      await api('/api/todo/tasks.php', { method: 'DELETE', body: { id } });
      closeModal();
      toast('Task deleted');
      await loadTasks();
    } catch (err) {
      toast(err.message, true);
    }
  });

  bindColumns();

  try {
    const saved = localStorage.getItem(VIEW_KEY);
    if (saved === 'archive' || saved === 'board') viewMode = saved;
  } catch {
    /* ignore */
  }
  applyViewMode();

  (async () => {
    try {
      await loadMeta();
      await loadTasks();
    } catch (err) {
      toast(err.message, true);
    }
  })();
})();
