(() => {
  const csrf = document.body.dataset.csrf || '';
  const toastEl = document.getElementById('toast');

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

  // Panels
  document.querySelectorAll('[data-panel]').forEach((btn) => {
    btn.addEventListener('click', () => {
      document.querySelectorAll('[data-panel]').forEach((b) => b.classList.remove('btn-primary'));
      btn.classList.add('btn-primary');
      const panel = btn.dataset.panel;
      document.getElementById('panel-users').classList.toggle('hidden', panel !== 'users');
      document.getElementById('panel-groups').classList.toggle('hidden', panel !== 'groups');
      document.getElementById('panel-events')?.classList.toggle('hidden', panel !== 'events');
      document.getElementById('panel-teamcal').classList.toggle('hidden', panel !== 'teamcal');
      document.getElementById('panel-notes')?.classList.toggle('hidden', panel !== 'notes');
      document.getElementById('panel-todo')?.classList.toggle('hidden', panel !== 'todo');
      if (panel === 'teamcal') loadTeamCal().catch((err) => toast(err.message, true));
      if (panel === 'events') loadAdminEventsPanel().catch((err) => toast(err.message, true));
      if (panel === 'notes') loadNotesSettings().catch((err) => toast(err.message, true));
      if (panel === 'todo') loadTodoSettings().catch((err) => toast(err.message, true));
    });
  });

  let users = [];
  let groups = [];
  let calMeta = { types: [], locations: [], users: [], groups: [] };
  let adminEvents = [];
  let adminEventsReady = false;

  const EVENT_COLORS = [
    '#4fc3f7', '#ab47bc', '#ef5350', '#66bb6a', '#ffa726', '#26c6da', '#ec407a',
  ];
  const HOUR_START = 9;

  function pad(n) {
    return String(n).padStart(2, '0');
  }

  function ymd(d) {
    return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
  }

  function toLocalInputValue(date) {
    return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}T${pad(date.getHours())}:${pad(date.getMinutes())}`;
  }

  function toApiDatetime(localValue) {
    if (!localValue) return '';
    return localValue.replace('T', ' ') + (localValue.length === 16 ? ':00' : '');
  }

  function parseApiDatetime(s) {
    const m = String(s || '').match(/^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})(?::(\d{2}))?/);
    if (!m) return new Date();
    return new Date(+m[1], +m[2] - 1, +m[3], +m[4], +m[5], +(m[6] || 0));
  }

  function defaultEventRange() {
    const now = new Date();
    const from = new Date(now.getFullYear(), now.getMonth(), 1);
    const to = new Date(now.getFullYear(), now.getMonth() + 3, 0);
    return { from: ymd(from), to: ymd(to) };
  }

  function fillSelect(el, items, { valueKey = null, labelKey = null, allLabel = 'All' } = {}) {
    if (!el) return;
    const opts = [`<option value="">${esc(allLabel)}</option>`];
    (items || []).forEach((item) => {
      if (valueKey) {
        opts.push(`<option value="${esc(item[valueKey])}">${esc(item[labelKey || valueKey])}</option>`);
      } else {
        opts.push(`<option value="${esc(item)}">${esc(item)}</option>`);
      }
    });
    el.innerHTML = opts.join('');
  }

  async function ensureCalMeta() {
    if (adminEventsReady) return calMeta;
    calMeta = await api('/api/teamcal/meta.php');
    fillSelect(document.getElementById('aevType'), calMeta.types || []);
    fillSelect(document.getElementById('aevLocation'), calMeta.locations || []);
    fillSelect(document.getElementById('aevOwner'), calMeta.users || [], { valueKey: 'id', labelKey: 'username' });
    fillSelect(document.getElementById('aevPerson'), calMeta.users || [], { valueKey: 'id', labelKey: 'username' });
    fillSelect(document.getElementById('aevGroup'), calMeta.groups || [], { valueKey: 'id', labelKey: 'name' });
    adminEventsReady = true;
    return calMeta;
  }

  function openEventModal() {
    document.getElementById('eventModal').classList.add('open');
  }

  function closeEventModal() {
    document.getElementById('eventModal').classList.remove('open');
  }

  document.querySelectorAll('[data-close-modal]').forEach((btn) => {
    btn.addEventListener('click', closeEventModal);
  });
  document.getElementById('eventModal')?.addEventListener('click', (e) => {
    if (e.target.id === 'eventModal') closeEventModal();
  });

  let pendingDeleteUserId = null;

  function openDeleteUserModal(id, username) {
    pendingDeleteUserId = id;
    const body = document.getElementById('deleteUserModalBody');
    body.innerHTML = `Delete <strong>${esc(username)}</strong>? Bookmarks will be removed. Choose what to do with their notes and todos.`;
    const keep = document.querySelector('input[name="deleteUserNotesAction"][value="keep"]');
    if (keep) keep.checked = true;
    const todoKeep = document.querySelector('input[name="deleteUserTodoAction"][value="keep"]');
    if (todoKeep) todoKeep.checked = true;
    document.getElementById('deleteUserModal').classList.add('open');
  }

  function closeDeleteUserModal() {
    pendingDeleteUserId = null;
    document.getElementById('deleteUserModal')?.classList.remove('open');
  }

  document.getElementById('deleteUserCancel')?.addEventListener('click', closeDeleteUserModal);
  document.getElementById('deleteUserModal')?.addEventListener('click', (e) => {
    if (e.target.id === 'deleteUserModal') closeDeleteUserModal();
  });
  document.getElementById('deleteUserConfirm')?.addEventListener('click', async () => {
    const id = pendingDeleteUserId;
    if (!id) return;
    const selected = document.querySelector('input[name="deleteUserNotesAction"]:checked');
    const notes_action = selected?.value || 'keep';
    const todoSelected = document.querySelector('input[name="deleteUserTodoAction"]:checked');
    const todo_action = todoSelected?.value || 'keep';
    const btn = document.getElementById('deleteUserConfirm');
    btn.disabled = true;
    try {
      const result = await api('/api/users.php', {
        method: 'DELETE',
        body: { id, notes_action, todo_action },
      });
      closeDeleteUserModal();
      const parts = ['User deleted'];
      if (notes_action === 'delete' && result?.notes_affected) {
        parts.push(`${result.notes_affected} note(s) deleted`);
      } else if (notes_action === 'reassign' && result?.notes_affected) {
        parts.push(`${result.notes_affected} note(s) reassigned`);
      }
      if (todo_action === 'delete' && result?.todo_affected) {
        parts.push(`${result.todo_affected} task(s) deleted`);
      } else if (todo_action === 'reassign' && result?.todo_affected) {
        parts.push(`${result.todo_affected} task(s) reassigned`);
      }
      if (result?.todo_assignee_cleared) {
        parts.push(`${result.todo_assignee_cleared} assignee link(s) cleared`);
      }
      if (result?.private_events_deleted) {
        parts.push(`${result.private_events_deleted} private event(s) removed`);
      }
      toast(parts.join(' · '));
      await refresh();
    } catch (err) {
      toast(err.message, true);
    } finally {
      btn.disabled = false;
    }
  });

  function fillMetaControls(selectedLocation = '', selectedPeople = [], selectedGroups = []) {
    const typeSel = document.getElementById('evType');
    const types = (calMeta.types && calMeta.types.length) ? calMeta.types : ['Other'];
    typeSel.innerHTML = types.map((t) => `<option value="${esc(t)}">${esc(t)}</option>`).join('');

    const locSel = document.getElementById('evLocationSelect');
    const locs = calMeta.locations || [];
    const inList = locs.includes(selectedLocation);
    locSel.innerHTML = [
      '<option value="">—</option>',
      ...locs.map((l) => `<option value="${esc(l)}">${esc(l)}</option>`),
      '<option value="__custom__">Other…</option>',
    ].join('');
    const custom = document.getElementById('evLocationCustom');
    if (selectedLocation && !inList) {
      locSel.value = '__custom__';
      custom.classList.remove('hidden');
      custom.value = selectedLocation;
    } else {
      locSel.value = selectedLocation || '';
      custom.classList.add('hidden');
      custom.value = '';
    }

    const peopleBox = document.getElementById('evPeople');
    peopleBox.innerHTML = (calMeta.users || []).map((u) => `
      <label>
        <input type="checkbox" value="${u.id}" ${selectedPeople.map(String).includes(String(u.id)) ? 'checked' : ''}>
        ${esc(u.username)}
      </label>
    `).join('') || '<div style="color:var(--text-muted);font-size:0.85rem">No users</div>';

    const groupsBox = document.getElementById('evGroups');
    groupsBox.innerHTML = (calMeta.groups || []).map((g) => `
      <label>
        <input type="checkbox" value="${g.id}" ${selectedGroups.map(String).includes(String(g.id)) ? 'checked' : ''}>
        ${esc(g.name)}
      </label>
    `).join('') || '<div style="color:var(--text-muted);font-size:0.85rem">No groups</div>';
  }

  document.getElementById('evLocationSelect')?.addEventListener('change', () => {
    const custom = document.getElementById('evLocationCustom');
    const isCustom = document.getElementById('evLocationSelect').value === '__custom__';
    custom.classList.toggle('hidden', !isCustom);
    if (isCustom) custom.focus();
  });

  document.getElementById('evVisibility')?.addEventListener('change', () => {
    const share = document.getElementById('evVisibility').value === 'share';
    document.getElementById('evGroupsWrap').classList.toggle('hidden', !share);
  });

  function getTimeMode() {
    return document.querySelector('input[name="evTimeMode"]:checked')?.value || 'timed';
  }

  function setTimeMode(mode) {
    document.querySelectorAll('input[name="evTimeMode"]').forEach((r) => {
      r.checked = r.value === mode;
    });
    syncTimeInputsDisabled();
  }

  function syncTimeInputsDisabled() {
    const mode = getTimeMode();
    const start = document.getElementById('evStart');
    const end = document.getElementById('evEnd');
    if (!start || !end) return;
    start.disabled = false;
    end.disabled = mode !== 'timed';
  }

  document.querySelectorAll('input[name="evTimeMode"]').forEach((r) => {
    r.addEventListener('change', syncTimeInputsDisabled);
  });

  function normalizeHexColor(c) {
    const s = String(c || '').trim().toLowerCase();
    return /^#[0-9a-f]{6}$/.test(s) ? s : '#4fc3f7';
  }

  function setEventColor(color) {
    const hex = normalizeHexColor(color);
    document.getElementById('evColor').value = hex;
    document.querySelectorAll('#evColorSwatches .cal-color-swatch').forEach((btn) => {
      const on = btn.dataset.color === hex;
      btn.classList.toggle('selected', on);
      btn.setAttribute('aria-checked', on ? 'true' : 'false');
    });
  }

  function initColorSwatches() {
    const root = document.getElementById('evColorSwatches');
    if (!root || root.dataset.ready === '1') return;
    root.innerHTML = EVENT_COLORS.map((c) => (
      `<button type="button" class="cal-color-swatch" role="radio" aria-checked="false"
        data-color="${c}" style="--swatch:${c}" title="${c}" aria-label="Color ${c}"></button>`
    )).join('');
    root.addEventListener('click', (e) => {
      const btn = e.target.closest('.cal-color-swatch');
      if (!btn || btn.disabled) return;
      setEventColor(btn.dataset.color);
    });
    root.dataset.ready = '1';
    setEventColor('#4fc3f7');
  }

  function resetEventForm(defaults = {}) {
    document.getElementById('evId').value = defaults.id || '';
    document.getElementById('eventModalTitle').textContent = defaults.id ? 'Edit event' : 'Add event';
    fillMetaControls(defaults.location || '', defaults.person_ids || [], defaults.group_ids || []);
    if (defaults.type) document.getElementById('evType').value = defaults.type;
    document.getElementById('evTitle').value = defaults.title || '';
    document.getElementById('evDescription').value = defaults.description || '';
    setEventColor(defaults.color || '#4fc3f7');

    let mode = 'timed';
    if (Number(defaults.all_day) === 1) mode = 'all_day';
    else if (defaults.period === 'am') mode = 'am';
    else if (defaults.period === 'pm') mode = 'pm';
    setTimeMode(mode);

    const start = defaults.starts_at ? parseApiDatetime(defaults.starts_at) : (defaults._startDate || new Date());
    const end = defaults.ends_at ? parseApiDatetime(defaults.ends_at) : new Date(start.getTime());
    if (!defaults.ends_at && mode === 'timed') {
      end.setHours(start.getHours() + 1);
    }
    document.getElementById('evStart').value = toLocalInputValue(start);
    document.getElementById('evEnd').value = toLocalInputValue(end);

    const vis = defaults.visibility || 'public';
    const visEl = document.getElementById('evVisibility');
    if ([...visEl.options].some((o) => o.value === vis)) visEl.value = vis;
    else visEl.value = 'public';
    document.getElementById('evGroupsWrap').classList.toggle('hidden', visEl.value !== 'share');

    const notifyEl = document.getElementById('evNotifyDayBefore');
    if (notifyEl) {
      notifyEl.checked = !!(defaults.notify_day_before === true
        || defaults.notify_day_before === 1
        || defaults.notify_day_before === '1');
    }

    document.getElementById('evDelete').classList.toggle('hidden', !defaults.id);
    document.getElementById('evSubmit').classList.remove('hidden');
    [...document.getElementById('eventForm').elements].forEach((el) => {
      if (el.type === 'button' || el.type === 'submit') return;
      el.disabled = false;
    });
    document.querySelectorAll('#evColorSwatches .cal-color-swatch').forEach((b) => { b.disabled = false; });
    syncTimeInputsDisabled();
  }

  function openCreateEvent() {
    const start = new Date();
    start.setMinutes(0, 0, 0);
    if (start.getHours() < HOUR_START) start.setHours(HOUR_START);
    resetEventForm({ _startDate: start, can_edit: true });
    openEventModal();
  }

  function openEditEvent(ev) {
    resetEventForm({ ...ev, can_edit: true });
    openEventModal();
  }

  function eventTimeModeLabel(ev) {
    if (Number(ev.all_day) === 1) return 'All day';
    if (ev.period === 'am') return 'AM';
    if (ev.period === 'pm') return 'PM';
    return 'Timed';
  }

  function fmtEventWhen(s) {
    if (!s) return '—';
    const d = parseApiDatetime(s);
    return d.toLocaleString(undefined, {
      year: 'numeric', month: 'short', day: 'numeric',
      hour: '2-digit', minute: '2-digit',
    });
  }

  function visibilityBadge(v) {
    const x = String(v || 'public');
    return `<span class="badge ${esc(x)}">${esc(x)}</span>`;
  }

  function renderAdminEventsTable() {
    const tbody = document.getElementById('adminEventsTable');
    if (!tbody) return;
    if (!adminEvents.length) {
      tbody.innerHTML = `<tr><td colspan="11" style="color:var(--text-muted)">No events match filters</td></tr>`;
      return;
    }
    tbody.innerHTML = adminEvents.map((ev) => {
      const people = (ev.persons || []).map((p) => p.username).join(', ') || '—';
      const color = normalizeHexColor(ev.color);
      return `
        <tr>
          <td><span class="admin-ev-dot" style="background:${esc(color)}"></span></td>
          <td style="white-space:nowrap">${esc(fmtEventWhen(ev.starts_at))}</td>
          <td style="white-space:nowrap">${esc(fmtEventWhen(ev.ends_at))}</td>
          <td>${esc(eventTimeModeLabel(ev))}</td>
          <td>${esc(ev.type || '—')}</td>
          <td><strong>${esc(ev.title || '(untitled)')}</strong></td>
          <td>${esc(ev.location || '—')}</td>
          <td>${esc(people)}</td>
          <td>${visibilityBadge(ev.visibility)}</td>
          <td>${esc(ev.owner_name || '—')}</td>
          <td style="white-space:nowrap">
            <button type="button" class="btn btn-sm" data-edit-ev="${ev.id}">Edit</button>
            <button type="button" class="btn btn-sm btn-danger" data-del-ev="${ev.id}">Delete</button>
          </td>
        </tr>`;
    }).join('');

    tbody.querySelectorAll('[data-edit-ev]').forEach((btn) => {
      btn.addEventListener('click', () => {
        const ev = adminEvents.find((x) => String(x.id) === btn.dataset.editEv);
        if (ev) openEditEvent(ev);
      });
    });
    tbody.querySelectorAll('[data-del-ev]').forEach((btn) => {
      btn.addEventListener('click', async () => {
        const id = Number(btn.dataset.delEv);
        if (!id || !confirm('Delete this event?')) return;
        try {
          await api('/api/teamcal/events.php', { method: 'DELETE', body: { id } });
          toast('Event deleted');
          await loadAdminEvents();
        } catch (err) {
          toast(err.message, true);
        }
      });
    });
  }

  function buildAdminEventsQuery() {
    const params = new URLSearchParams();
    params.set('admin', '1');
    const from = document.getElementById('aevFrom').value;
    const to = document.getElementById('aevTo').value;
    params.set('from', from ? `${from} 00:00:00` : '');
    params.set('to', to ? `${to} 23:59:59` : '');
    const q = document.getElementById('aevQ').value.trim();
    if (q) params.set('q', q);
    const type = document.getElementById('aevType').value;
    if (type) params.set('type', type);
    const location = document.getElementById('aevLocation').value;
    if (location) params.set('location', location);
    const visibility = document.getElementById('aevVisibility').value;
    if (visibility) params.set('visibility', visibility);
    const timeMode = document.getElementById('aevTimeMode').value;
    if (timeMode) params.set('time_mode', timeMode);
    const color = document.getElementById('aevColor').value;
    if (color) params.set('color', color);
    const owner = document.getElementById('aevOwner').value;
    if (owner) params.set('owner_id', owner);
    const person = document.getElementById('aevPerson').value;
    if (person) params.set('person_id', person);
    const group = document.getElementById('aevGroup').value;
    if (group) params.set('group_id', group);
    return params.toString();
  }

  async function loadAdminEvents() {
    const data = await api(`/api/teamcal/events.php?${buildAdminEventsQuery()}`);
    adminEvents = data.events || [];
    const countEl = document.getElementById('aevCount');
    const truncEl = document.getElementById('aevTruncated');
    if (countEl) countEl.textContent = `${data.count ?? adminEvents.length} event(s)`;
    if (truncEl) truncEl.classList.toggle('hidden', !data.truncated);
    renderAdminEventsTable();
  }

  async function loadAdminEventsPanel() {
    initColorSwatches();
    const range = defaultEventRange();
    const fromEl = document.getElementById('aevFrom');
    const toEl = document.getElementById('aevTo');
    if (fromEl && !fromEl.value) fromEl.value = range.from;
    if (toEl && !toEl.value) toEl.value = range.to;
    await ensureCalMeta();
    await loadAdminEvents();
  }

  function clearAdminEventFilters() {
    const range = defaultEventRange();
    document.getElementById('aevQ').value = '';
    document.getElementById('aevFrom').value = range.from;
    document.getElementById('aevTo').value = range.to;
    document.getElementById('aevType').value = '';
    document.getElementById('aevLocation').value = '';
    document.getElementById('aevVisibility').value = '';
    document.getElementById('aevTimeMode').value = '';
    document.getElementById('aevColor').value = '';
    document.getElementById('aevOwner').value = '';
    document.getElementById('aevPerson').value = '';
    document.getElementById('aevGroup').value = '';
  }

  document.getElementById('adminEvFilters')?.addEventListener('submit', async (e) => {
    e.preventDefault();
    try {
      await loadAdminEvents();
    } catch (err) {
      toast(err.message, true);
    }
  });

  document.getElementById('aevClear')?.addEventListener('click', async () => {
    clearAdminEventFilters();
    try {
      await loadAdminEvents();
    } catch (err) {
      toast(err.message, true);
    }
  });

  document.getElementById('adminEvNew')?.addEventListener('click', async () => {
    try {
      await ensureCalMeta();
      initColorSwatches();
      openCreateEvent();
    } catch (err) {
      toast(err.message, true);
    }
  });

  document.getElementById('eventForm')?.addEventListener('submit', async (e) => {
    e.preventDefault();
    const id = document.getElementById('evId').value;
    const mode = getTimeMode();
    const startVal = document.getElementById('evStart').value;
    let endVal = document.getElementById('evEnd').value;
    if (!startVal) return toast('Start is required', true);

    const startDate = startVal.slice(0, 10);
    let all_day = false;
    let period = 'none';
    let starts_at;
    let ends_at;

    if (mode === 'all_day') {
      all_day = true;
      const endDate = (endVal || startVal).slice(0, 10);
      starts_at = `${startDate} 00:00:00`;
      ends_at = `${endDate} 00:00:00`;
    } else if (mode === 'am') {
      period = 'am';
      starts_at = `${startDate} 00:00:00`;
      ends_at = `${startDate} 00:00:00`;
    } else if (mode === 'pm') {
      period = 'pm';
      starts_at = `${startDate} 00:00:00`;
      ends_at = `${startDate} 00:00:00`;
    } else {
      if (!endVal) endVal = startVal;
      starts_at = toApiDatetime(startVal);
      ends_at = toApiDatetime(endVal);
    }

    const locSel = document.getElementById('evLocationSelect').value;
    const location = locSel === '__custom__'
      ? document.getElementById('evLocationCustom').value.trim()
      : locSel;

    const payload = {
      title: document.getElementById('evTitle').value.trim(),
      type: document.getElementById('evType').value,
      description: document.getElementById('evDescription').value.trim(),
      location,
      color: document.getElementById('evColor').value,
      starts_at,
      ends_at,
      all_day,
      period,
      visibility: document.getElementById('evVisibility').value,
      person_ids: [...document.querySelectorAll('#evPeople input:checked')].map((x) => Number(x.value)),
      group_ids: [...document.querySelectorAll('#evGroups input:checked')].map((x) => Number(x.value)),
      notify_day_before: !!document.getElementById('evNotifyDayBefore')?.checked,
    };

    try {
      if (id) {
        payload.id = Number(id);
        await api('/api/teamcal/events.php', { method: 'PUT', body: payload });
        toast('Event updated');
      } else {
        await api('/api/teamcal/events.php', { method: 'POST', body: payload });
        toast('Event created');
      }
      closeEventModal();
      await loadAdminEvents();
    } catch (err) {
      toast(err.message, true);
    }
  });

  document.getElementById('evDelete')?.addEventListener('click', async () => {
    const id = Number(document.getElementById('evId').value);
    if (!id || !confirm('Delete this event?')) return;
    try {
      await api('/api/teamcal/events.php', { method: 'DELETE', body: { id } });
      toast('Event deleted');
      closeEventModal();
      await loadAdminEvents();
    } catch (err) {
      toast(err.message, true);
    }
  });

  async function loadUsers() {
    users = await api('/api/users.php');
    const tbody = document.getElementById('usersTable');
    tbody.innerHTML = users.map((u) => {
      const active = Number(u.is_active) === 1;
      const must = Number(u.must_change_password) === 1;
      return `
      <tr>
        <td>${u.id}</td>
        <td>${esc(u.username)}</td>
        <td>
          <select class="form-control" data-role="${u.id}" data-prev="${esc(u.role)}"
            style="width:auto;min-width:7rem;padding:4px 8px;font-size:0.85rem">
            <option value="user" ${u.role === 'user' ? 'selected' : ''}>user</option>
            <option value="admin" ${u.role === 'admin' ? 'selected' : ''}>admin</option>
          </select>
        </td>
        <td>
          <span class="badge ${active ? 'public' : ''}" style="${active ? '' : 'background:rgba(239,83,80,0.18);color:#ff8a80'}">
            ${active ? 'Active' : 'Inactive'}
          </span>
        </td>
        <td>
          <label style="display:inline-flex;align-items:center;gap:6px;margin:0;cursor:pointer">
            <input type="checkbox" data-must-change="${u.id}" ${must ? 'checked' : ''}>
            Next login
          </label>
        </td>
        <td>${esc(u.created_at)}</td>
        <td style="white-space:nowrap">
          <button class="btn btn-sm" data-toggle-active="${u.id}" data-active="${active ? '1' : '0'}">
            ${active ? 'Deactivate' : 'Activate'}
          </button>
          <button class="btn btn-sm" data-reset="${u.id}">Reset password</button>
          <button class="btn btn-sm btn-danger" data-del-user="${u.id}">Delete</button>
        </td>
      </tr>`;
    }).join('');

    tbody.querySelectorAll('[data-toggle-active]').forEach((btn) => {
      btn.addEventListener('click', async () => {
        const id = Number(btn.dataset.toggleActive);
        const currentlyActive = btn.dataset.active === '1';
        try {
          await api('/api/users.php', {
            method: 'PUT',
            body: { id, is_active: !currentlyActive },
          });
          toast(currentlyActive ? 'User deactivated' : 'User activated');
          await refresh();
        } catch (err) {
          toast(err.message, true);
        }
      });
    });

    tbody.querySelectorAll('[data-role]').forEach((sel) => {
      sel.addEventListener('change', async () => {
        const id = Number(sel.dataset.role);
        const role = sel.value;
        const prev = sel.dataset.prev || 'user';
        try {
          await api('/api/users.php', {
            method: 'PUT',
            body: { id, role },
          });
          sel.dataset.prev = role;
          toast(`Role updated to ${role}`);
          await refresh();
        } catch (err) {
          toast(err.message, true);
          sel.value = prev;
        }
      });
    });

    tbody.querySelectorAll('[data-must-change]').forEach((cb) => {
      cb.addEventListener('change', async () => {
        const id = Number(cb.dataset.mustChange);
        try {
          await api('/api/users.php', {
            method: 'PUT',
            body: { id, must_change_password: cb.checked },
          });
          toast(cb.checked
            ? 'User must change password on next login'
            : 'Force password change cleared');
          await refresh();
        } catch (err) {
          toast(err.message, true);
          cb.checked = !cb.checked;
        }
      });
    });

    tbody.querySelectorAll('[data-reset]').forEach((btn) => {
      btn.addEventListener('click', async () => {
        const id = Number(btn.dataset.reset);
        const password = prompt('New password (min 6 characters)');
        if (password == null) return;
        if (password.length < 6) return toast('Password must be at least 6 characters', true);
        const force = confirm('Also require password change on next login?');
        try {
          await api('/api/users.php', {
            method: 'PUT',
            body: { id, password, must_change_password: force },
          });
          toast('Password updated');
          await refresh();
        } catch (err) {
          toast(err.message, true);
        }
      });
    });
    tbody.querySelectorAll('[data-del-user]').forEach((btn) => {
      btn.addEventListener('click', () => {
        const id = Number(btn.dataset.delUser);
        const u = users.find((x) => Number(x.id) === id);
        openDeleteUserModal(id, u?.username || `user #${id}`);
      });
    });

    renderMemberChecks();
  }

  function renderMemberChecks(selected = []) {
    const box = document.getElementById('groupMembers');
    box.innerHTML = users.map((u) => `
      <label>
        <input type="checkbox" value="${u.id}" ${selected.map(String).includes(String(u.id)) ? 'checked' : ''}>
        ${esc(u.username)} <span class="badge ${u.role === 'admin' ? 'admin' : ''}">${esc(u.role)}</span>
      </label>
    `).join('') || '<div style="color:var(--text-muted)">No users</div>';
  }

  async function loadGroups() {
    groups = await api('/api/groups.php');
    const tbody = document.getElementById('groupsTable');
    tbody.innerHTML = groups.map((g) => `
      <tr>
        <td>${g.id}</td>
        <td>${esc(g.name)}</td>
        <td>${esc(g.description || '')}</td>
        <td>${(g.members || []).map((m) => esc(m.username)).join('、') || '—'}</td>
        <td>
          <button class="btn btn-sm" data-edit-group="${g.id}">Edit</button>
          <button class="btn btn-sm btn-danger" data-del-group="${g.id}">Delete</button>
        </td>
      </tr>
    `).join('');

    tbody.querySelectorAll('[data-edit-group]').forEach((btn) => {
      btn.addEventListener('click', () => {
        const g = groups.find((x) => String(x.id) === btn.dataset.editGroup);
        if (!g) return;
        document.getElementById('groupId').value = g.id;
        document.getElementById('groupName').value = g.name;
        document.getElementById('groupDesc').value = g.description || '';
        renderMemberChecks(g.member_ids || []);
        document.getElementById('groupSubmitBtn').textContent = 'Save group';
        document.getElementById('groupCancelBtn').classList.remove('hidden');
      });
    });
    tbody.querySelectorAll('[data-del-group]').forEach((btn) => {
      btn.addEventListener('click', async () => {
        const id = Number(btn.dataset.delGroup);
        if (!confirm('Delete this group?')) return;
        try {
          await api('/api/groups.php', { method: 'DELETE', body: { id } });
          toast('Group deleted');
          resetGroupForm();
          await loadGroups();
        } catch (err) {
          toast(err.message, true);
        }
      });
    });
  }

  function resetGroupForm() {
    document.getElementById('groupId').value = '';
    document.getElementById('groupName').value = '';
    document.getElementById('groupDesc').value = '';
    document.getElementById('groupSubmitBtn').textContent = 'Add group';
    document.getElementById('groupCancelBtn').classList.add('hidden');
    renderMemberChecks();
  }

  document.getElementById('groupCancelBtn').addEventListener('click', resetGroupForm);

  document.getElementById('userForm').addEventListener('submit', async (e) => {
    e.preventDefault();
    try {
      await api('/api/users.php', {
        method: 'POST',
        body: {
          username: document.getElementById('userUsername').value.trim(),
          password: document.getElementById('userPassword').value,
          role: document.getElementById('userRole').value,
          is_active: document.getElementById('userActive').checked,
          must_change_password: document.getElementById('userMustChange').checked,
        },
      });
      e.target.reset();
      document.getElementById('userActive').checked = true;
      document.getElementById('userMustChange').checked = false;
      toast('User added');
      await refresh();
    } catch (err) {
      toast(err.message, true);
    }
  });

  document.getElementById('groupForm').addEventListener('submit', async (e) => {
    e.preventDefault();
    const id = document.getElementById('groupId').value;
    const member_ids = [...document.querySelectorAll('#groupMembers input:checked')].map((x) => Number(x.value));
    const payload = {
      name: document.getElementById('groupName').value.trim(),
      description: document.getElementById('groupDesc').value.trim(),
      member_ids,
    };
    try {
      if (id) {
        payload.id = Number(id);
        await api('/api/groups.php', { method: 'PUT', body: payload });
        toast('Group updated');
      } else {
        await api('/api/groups.php', { method: 'POST', body: payload });
        toast('Group added');
      }
      resetGroupForm();
      await loadGroups();
    } catch (err) {
      toast(err.message, true);
    }
  });

  function setJsonField(id, value) {
    if (!Array.isArray(value)) return;
    document.getElementById(id).value = JSON.stringify(value, null, 2);
  }

  function fillPeriodRanges(ranges) {
    if (!ranges || typeof ranges !== 'object') return;
    const set = (id, val) => {
      const el = document.getElementById(id);
      if (el && val) el.value = String(val).slice(0, 5);
    };
    set('rangeAllDayStart', ranges.all_day?.start);
    set('rangeAllDayEnd', ranges.all_day?.end);
    set('rangeAmStart', ranges.am?.start);
    set('rangeAmEnd', ranges.am?.end);
    set('rangePmStart', ranges.pm?.start);
    set('rangePmEnd', ranges.pm?.end);
  }

  async function loadTeamCal() {
    const typesEl = document.getElementById('teamcalTypesJson');
    const locsEl = document.getElementById('teamcalLocationsJson');
    try {
      const settings = await api('/api/teamcal/settings.php');
      document.getElementById('teamcalEnabled').checked = !!settings.enabled;
      fillPeriodRanges(settings.period_ranges);
      if (Array.isArray(settings.types) && settings.types.length) {
        setJsonField('teamcalTypesJson', settings.types);
      }
      if (Array.isArray(settings.locations) && settings.locations.length) {
        setJsonField('teamcalLocationsJson', settings.locations);
      }
    } catch (err) {
      toast(err.message || 'Failed to load Team Calendar settings', true);
      return;
    }
    try {
      const meta = await api('/api/teamcal/meta.php');
      if (Array.isArray(meta.types) && meta.types.length) {
        setJsonField('teamcalTypesJson', meta.types);
      }
      if (Array.isArray(meta.locations) && meta.locations.length) {
        setJsonField('teamcalLocationsJson', meta.locations);
      }
    } catch (err) {
      // Keep SSR / settings values; only toast if fields are still empty
      if (!String(typesEl.value || '').trim() || !String(locsEl.value || '').trim()) {
        toast(err.message || 'Failed to load types/locations', true);
      }
    }
    try {
      const hol = await api('/api/teamcal/holidays.php');
      const el = document.getElementById('teamcalHolidayCount');
      if (el) el.textContent = `Holidays loaded: ${hol.count ?? 0}`;
    } catch {
      const el = document.getElementById('teamcalHolidayCount');
      if (el) el.textContent = 'Holidays loaded: —';
    }
  }

  document.getElementById('teamcalSaveEnabled').addEventListener('click', async () => {
    try {
      const enabled = document.getElementById('teamcalEnabled').checked;
      await api('/api/teamcal/settings.php', { method: 'PUT', body: { enabled } });
      toast(enabled ? 'Team Calendar enabled' : 'Team Calendar disabled');
    } catch (err) {
      toast(err.message, true);
    }
  });

  document.getElementById('teamcalSaveRanges')?.addEventListener('click', async () => {
    const period_ranges = {
      all_day: {
        start: document.getElementById('rangeAllDayStart').value,
        end: document.getElementById('rangeAllDayEnd').value,
      },
      am: {
        start: document.getElementById('rangeAmStart').value,
        end: document.getElementById('rangeAmEnd').value,
      },
      pm: {
        start: document.getElementById('rangePmStart').value,
        end: document.getElementById('rangePmEnd').value,
      },
    };
    try {
      const data = await api('/api/teamcal/settings.php', {
        method: 'PUT',
        body: { period_ranges },
      });
      fillPeriodRanges(data.period_ranges);
      toast('Period ranges saved');
    } catch (err) {
      toast(err.message, true);
    }
  });

  document.getElementById('teamcalSaveJson').addEventListener('click', async () => {
    let types;
    let locations;
    try {
      types = JSON.parse(document.getElementById('teamcalTypesJson').value);
      locations = JSON.parse(document.getElementById('teamcalLocationsJson').value);
    } catch {
      toast('Invalid JSON', true);
      return;
    }
    if (!Array.isArray(types) || !Array.isArray(locations)) {
      toast('types and locations must be JSON arrays', true);
      return;
    }
    try {
      const data = await api('/api/teamcal/meta.php', {
        method: 'PUT',
        body: { types, locations },
      });
      document.getElementById('teamcalTypesJson').value = JSON.stringify(data.types || [], null, 2);
      document.getElementById('teamcalLocationsJson').value = JSON.stringify(data.locations || [], null, 2);
      toast('Types & locations saved');
    } catch (err) {
      toast(err.message, true);
    }
  });

  document.getElementById('teamcalUploadHolidays')?.addEventListener('click', async () => {
    const input = document.getElementById('teamcalHolidayFile');
    const file = input?.files?.[0];
    if (!file) return toast('Choose an .ics file first', true);
    const fd = new FormData();
    fd.append('file', file);
    try {
      const res = await fetch('/api/teamcal/holidays.php', {
        method: 'POST',
        headers: { 'X-CSRF-Token': csrf },
        body: fd,
      });
      const data = await res.json();
      if (!res.ok || data.ok === false) throw new Error(data.error || 'Upload failed');
      const count = data.data?.count ?? 0;
      document.getElementById('teamcalHolidayCount').textContent = `Holidays loaded: ${count}`;
      input.value = '';
      toast(`Loaded ${count} holiday date(s)`);
    } catch (err) {
      toast(err.message, true);
    }
  });

  document.getElementById('teamcalClearHolidays')?.addEventListener('click', async () => {
    if (!confirm('Clear all holiday dates?')) return;
    try {
      await api('/api/teamcal/holidays.php', { method: 'DELETE', body: {} });
      document.getElementById('teamcalHolidayCount').textContent = 'Holidays loaded: 0';
      toast('Holidays cleared');
    } catch (err) {
      toast(err.message, true);
    }
  });

  async function loadNotesSettings() {
    const data = await api('/api/notes/settings.php');
    const el = document.getElementById('notesEnabled');
    if (el) el.checked = !!data.enabled;
  }

  document.getElementById('notesSaveEnabled')?.addEventListener('click', async () => {
    try {
      const enabled = document.getElementById('notesEnabled').checked;
      await api('/api/notes/settings.php', { method: 'PUT', body: { enabled } });
      toast(enabled ? 'Notes enabled' : 'Notes disabled');
    } catch (err) {
      toast(err.message, true);
    }
  });

  async function loadTodoSettings() {
    const data = await api('/api/todo/settings.php');
    const el = document.getElementById('todoEnabled');
    if (el) el.checked = !!data.enabled;

    const box = document.getElementById('todoTaskViewers');
    if (!box) return;
    if (!users.length) {
      await loadUsers();
    }
    const selected = new Set((data.task_viewer_ids || []).map(Number));
    const active = users.filter((u) => Number(u.is_active) === 1);
    if (!active.length) {
      box.innerHTML = '<div class="form-hint">No active users</div>';
      return;
    }
    box.innerHTML = active.map((u) => `
      <label class="checkbox-item" style="display:flex;gap:8px;align-items:center;margin:0 0 6px;cursor:pointer">
        <input type="checkbox" value="${u.id}"${selected.has(Number(u.id)) ? ' checked' : ''}>
        <span>${esc(u.username)}${u.role === 'admin' ? ' <span class="badge admin">admin</span>' : ''}</span>
      </label>
    `).join('');
  }

  document.getElementById('todoSaveEnabled')?.addEventListener('click', async () => {
    try {
      const enabled = document.getElementById('todoEnabled').checked;
      await api('/api/todo/settings.php', { method: 'PUT', body: { enabled } });
      toast(enabled ? 'Todo enabled' : 'Todo disabled');
    } catch (err) {
      toast(err.message, true);
    }
  });

  document.getElementById('todoSaveViewers')?.addEventListener('click', async () => {
    try {
      const task_viewer_ids = [...document.querySelectorAll('#todoTaskViewers input:checked')]
        .map((el) => Number(el.value))
        .filter((id) => id > 0);
      await api('/api/todo/settings.php', { method: 'PUT', body: { task_viewer_ids } });
      toast(`Task viewers saved (${task_viewer_ids.length})`);
    } catch (err) {
      toast(err.message, true);
    }
  });

  async function refresh() {
    await loadUsers();
    await loadGroups();
  }

  refresh().catch((err) => toast(err.message, true));
})();
