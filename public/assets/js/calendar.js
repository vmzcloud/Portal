(() => {
  const csrf = document.body.dataset.csrf || '';
  const isAuth = document.body.dataset.auth === '1';
  const isAdmin = document.body.dataset.admin === '1';
  const toastEl = document.getElementById('toast');

  const HOUR_START = 9;

  let meta = { types: [], locations: [], users: [], groups: [] };
  let events = [];
  /** @type {Record<string, string>} */
  let holidays = {};
  let weekStart = startOfWeek(new Date()); // Sunday

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

  function startOfWeek(d) {
    const x = new Date(d.getFullYear(), d.getMonth(), d.getDate());
    x.setHours(0, 0, 0, 0);
    x.setDate(x.getDate() - x.getDay()); // Sunday = 0
    return x;
  }

  function addDays(d, n) {
    const x = new Date(d.getTime());
    x.setDate(x.getDate() + n);
    return x;
  }

  function pad(n) {
    return String(n).padStart(2, '0');
  }

  function toLocalInputValue(date) {
    return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}T${pad(date.getHours())}:${pad(date.getMinutes())}`;
  }

  function toApiDatetime(localValue) {
    if (!localValue) return '';
    return localValue.length === 16 ? `${localValue}:00`.replace('T', ' ') : localValue.replace('T', ' ');
  }

  function parseApiDatetime(s) {
    if (!s) return null;
    const m = String(s).match(/^(\d{4})-(\d{2})-(\d{2})[T ](\d{2}):(\d{2})(?::(\d{2}))?/);
    if (!m) return new Date(s);
    return new Date(+m[1], +m[2] - 1, +m[3], +m[4], +m[5], +(m[6] || 0));
  }

  function fmtDayHeader(d) {
    const names = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
    return `${names[d.getDay()]} ${d.getMonth() + 1}/${d.getDate()}`;
  }

  function ymd(d) {
    return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
  }

  function fmtWeekLabel(start) {
    const end = addDays(start, 6);
    const opts = { month: 'short', day: 'numeric', year: 'numeric' };
    return `${start.toLocaleDateString(undefined, opts)} – ${end.toLocaleDateString(undefined, opts)}`;
  }

  function weekRangeIso() {
    const from = weekStart;
    const to = addDays(weekStart, 7);
    const f = `${from.getFullYear()}-${pad(from.getMonth() + 1)}-${pad(from.getDate())} 00:00:00`;
    const t = `${to.getFullYear()}-${pad(to.getMonth() + 1)}-${pad(to.getDate())} 00:00:00`;
    return { from: f, to: t };
  }

  function openModal() {
    document.getElementById('eventModal').classList.add('open');
  }

  function closeModal() {
    document.getElementById('eventModal').classList.remove('open');
  }

  document.querySelectorAll('[data-close-modal]').forEach((btn) => {
    btn.addEventListener('click', closeModal);
  });
  document.getElementById('eventModal').addEventListener('click', (e) => {
    if (e.target.id === 'eventModal') closeModal();
  });

  function fillMetaControls(selectedLocation = '', selectedPeople = [], selectedGroups = []) {
    const typeSel = document.getElementById('evType');
    const types = meta.types.length ? meta.types : ['Other'];
    typeSel.innerHTML = types.map((t) => `<option value="${esc(t)}">${esc(t)}</option>`).join('');

    const locSel = document.getElementById('evLocationSelect');
    const locs = meta.locations || [];
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
    const users = meta.users || [];
    peopleBox.innerHTML = users.map((u) => `
      <label>
        <input type="checkbox" value="${u.id}" ${selectedPeople.map(String).includes(String(u.id)) ? 'checked' : ''}>
        ${esc(u.username)}
      </label>
    `).join('') || '<div style="color:var(--text-muted);font-size:0.85rem">No users</div>';

    const groupsBox = document.getElementById('evGroups');
    const groups = meta.groups || [];
    groupsBox.innerHTML = groups.map((g) => `
      <label>
        <input type="checkbox" value="${g.id}" ${selectedGroups.map(String).includes(String(g.id)) ? 'checked' : ''}>
        ${esc(g.name)}
      </label>
    `).join('') || '<div style="color:var(--text-muted);font-size:0.85rem">No groups</div>';
  }

  document.getElementById('evLocationSelect').addEventListener('change', () => {
    const custom = document.getElementById('evLocationCustom');
    const isCustom = document.getElementById('evLocationSelect').value === '__custom__';
    custom.classList.toggle('hidden', !isCustom);
    if (isCustom) custom.focus();
  });

  document.getElementById('evVisibility').addEventListener('change', () => {
    const share = document.getElementById('evVisibility').value === 'share';
    document.getElementById('evGroupsWrap').classList.toggle('hidden', !share);
  });

  function setTimeMode(mode) {
    document.querySelectorAll('input[name="evTimeMode"]').forEach((r) => {
      r.checked = r.value === mode;
    });
    syncTimeInputsDisabled();
  }

  function getTimeMode() {
    return document.querySelector('input[name="evTimeMode"]:checked')?.value || 'timed';
  }

  function syncTimeInputsDisabled() {
    const mode = getTimeMode();
    const start = document.getElementById('evStart');
    const end = document.getElementById('evEnd');
    // Keep date part editable; for non-timed we still use start date
    start.disabled = false;
    end.disabled = mode !== 'timed';
    if (mode !== 'timed') {
      // force date-only style by zeroing time display on change is handled at save
    }
  }

  document.querySelectorAll('input[name="evTimeMode"]').forEach((r) => {
    r.addEventListener('change', syncTimeInputsDisabled);
  });

  function resetForm(defaults = {}) {
    document.getElementById('evId').value = defaults.id || '';
    document.getElementById('eventModalTitle').textContent = defaults.id ? 'Edit event' : 'Add event';
    fillMetaControls(defaults.location || '', defaults.person_ids || [], defaults.group_ids || []);
    if (defaults.type) document.getElementById('evType').value = defaults.type;
    document.getElementById('evTitle').value = defaults.title || '';
    document.getElementById('evDescription').value = defaults.description || '';
    document.getElementById('evColor').value = defaults.color || '#4fc3f7';

    let mode = 'timed';
    if (Number(defaults.all_day) === 1) mode = 'all_day';
    else if (defaults.period === 'am') mode = 'am';
    else if (defaults.period === 'pm') mode = 'pm';
    setTimeMode(mode);

    const start = defaults.starts_at ? parseApiDatetime(defaults.starts_at) : (defaults._startDate || new Date());
    const end = defaults.ends_at ? parseApiDatetime(defaults.ends_at) : addDays(start, 0);
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

    const canEdit = defaults.can_edit !== false;
    const isEdit = !!defaults.id;
    document.getElementById('evDelete').classList.toggle('hidden', !(isEdit && defaults.can_edit));
    document.getElementById('evSubmit').classList.toggle('hidden', isEdit && !canEdit);
    [...document.getElementById('eventForm').elements].forEach((el) => {
      if (el.id === 'evDelete' || el.hasAttribute('data-close-modal')) return;
      if (el.type === 'button' || el.type === 'submit') return;
      el.disabled = isEdit && !canEdit && el.id !== 'evDelete';
    });
    if (!isEdit) {
      [...document.getElementById('eventForm').elements].forEach((el) => {
        if (el.id !== 'evEnd' || getTimeMode() === 'timed') el.disabled = false;
      });
      syncTimeInputsDisabled();
    }
  }

  function openCreate(atDate) {
    const start = atDate ? new Date(atDate.getTime()) : new Date();
    if (!atDate) {
      start.setMinutes(0, 0, 0);
      if (start.getHours() < HOUR_START) start.setHours(HOUR_START);
    }
    resetForm({ _startDate: start, can_edit: true });
    openModal();
  }

  function openEdit(ev) {
    resetForm(ev);
    openModal();
  }

  document.getElementById('btnNewEvent').addEventListener('click', () => openCreate());

  document.getElementById('eventForm').addEventListener('submit', async (e) => {
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

    // Server applies admin-configured ranges for all_day / am / pm
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

    const person_ids = [...document.querySelectorAll('#evPeople input:checked')].map((x) => Number(x.value));
    const group_ids = [...document.querySelectorAll('#evGroups input:checked')].map((x) => Number(x.value));

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
      person_ids,
      group_ids,
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
      closeModal();
      await loadEvents();
      renderWeek();
    } catch (err) {
      toast(err.message, true);
    }
  });

  document.getElementById('evDelete').addEventListener('click', async () => {
    const id = Number(document.getElementById('evId').value);
    if (!id || !confirm('Delete this event?')) return;
    try {
      await api('/api/teamcal/events.php', { method: 'DELETE', body: { id } });
      toast('Event deleted');
      closeModal();
      await loadEvents();
      renderWeek();
    } catch (err) {
      toast(err.message, true);
    }
  });

  async function loadMeta() {
    meta = await api('/api/teamcal/meta.php');
  }

  async function loadEvents() {
    const { from, to } = weekRangeIso();
    events = await api(`/api/teamcal/events.php?from=${encodeURIComponent(from)}&to=${encodeURIComponent(to)}`);
  }

  async function loadHolidays() {
    const from = ymd(weekStart);
    const to = ymd(addDays(weekStart, 6));
    try {
      const data = await api(
        `/api/teamcal/holidays.php?from=${encodeURIComponent(from)}&to=${encodeURIComponent(to)}`
      );
      holidays = data.holidays || {};
    } catch {
      holidays = {};
    }
  }

  async function uploadIcs(url, file) {
    const fd = new FormData();
    fd.append('file', file);
    const res = await fetch(url, {
      method: 'POST',
      headers: { 'X-CSRF-Token': csrf },
      body: fd,
    });
    const data = await res.json();
    if (!res.ok || data.ok === false) throw new Error(data.error || 'Upload failed');
    return data.data;
  }

  if (isAdmin) {
    const icsInput = document.getElementById('icsFileInput');
    const holInput = document.getElementById('holidayFileInput');
    document.getElementById('btnImportIcs')?.addEventListener('click', () => icsInput?.click());
    document.getElementById('btnImportHolidays')?.addEventListener('click', () => holInput?.click());

    icsInput?.addEventListener('change', async () => {
      const file = icsInput.files?.[0];
      icsInput.value = '';
      if (!file) return;
      try {
        const result = await uploadIcs('/api/teamcal/import.php', file);
        const parts = [`Imported ${result.imported || 0}`];
        if (result.skipped) parts.push(`skipped ${result.skipped}`);
        if ((result.errors || []).length) parts.push(`${result.errors.length} errors`);
        toast(parts.join(', '));
        await loadEvents();
        renderWeek();
      } catch (err) {
        toast(err.message, true);
      }
    });

    holInput?.addEventListener('change', async () => {
      const file = holInput.files?.[0];
      holInput.value = '';
      if (!file) return;
      try {
        const result = await uploadIcs('/api/teamcal/holidays.php', file);
        toast(`Loaded ${result.count || 0} holiday date(s)`);
        await loadHolidays();
        renderWeek();
      } catch (err) {
        toast(err.message, true);
      }
    });
  }

  function eventOverlapsDay(ev, day) {
    const dayStart = new Date(day.getFullYear(), day.getMonth(), day.getDate(), 0, 0, 0);
    const dayEnd = new Date(day.getFullYear(), day.getMonth(), day.getDate() + 1, 0, 0, 0);
    const s = parseApiDatetime(ev.starts_at);
    const e = parseApiDatetime(ev.ends_at);
    return s < dayEnd && e >= dayStart;
  }

  function eventStartLabel(ev) {
    if (Number(ev.all_day) === 1) return 'All day';
    if (ev.period === 'am') return 'AM';
    if (ev.period === 'pm') return 'PM';
    const s = parseApiDatetime(ev.starts_at);
    if (!s || Number.isNaN(s.getTime())) return '';
    return `${pad(s.getHours())}:${pad(s.getMinutes())}`;
  }

  function eventPeopleLabel(ev) {
    const persons = Array.isArray(ev.persons) ? ev.persons : [];
    const names = persons
      .map((p) => (p && p.username ? String(p.username) : ''))
      .filter(Boolean);
    return names.join(', ');
  }

  function renderEventChip(ev) {
    const type = String(ev.type || '').trim();
    const title = String(ev.title || '').trim() || '(untitled)';
    const start = eventStartLabel(ev);
    const location = String(ev.location || '').trim();
    const people = eventPeopleLabel(ev);

    const metaParts = [];
    if (start) metaParts.push(esc(start));
    if (location) metaParts.push(esc(location));

    return `<button type="button" class="cal-chip" data-id="${ev.id}" style="--ev:${esc(ev.color || '#4fc3f7')}">
      ${type ? `<span class="cal-chip-type">${esc(type)}</span>` : ''}
      <span class="cal-chip-title">${esc(title)}</span>
      ${metaParts.length ? `<span class="cal-chip-meta">${metaParts.join(' · ')}</span>` : ''}
      ${people ? `<span class="cal-chip-people">${esc(people)}</span>` : ''}
    </button>`;
  }

  function renderWeek() {
    document.getElementById('calWeekLabel').textContent = fmtWeekLabel(weekStart);
    const root = document.getElementById('calWeek');
    const days = Array.from({ length: 7 }, (_, i) => addDays(weekStart, i));

    let html = '<div class="cal-grid">';
    days.forEach((d) => {
      const today = new Date();
      const isToday = d.toDateString() === today.toDateString();
      const isSunday = d.getDay() === 0;
      const dateKey = ymd(d);
      const holidayName = holidays[dateKey] || '';
      const isHoliday = !!holidayName;
      const classes = ['cal-day-head'];
      if (isToday) classes.push('is-today');
      if (isSunday) classes.push('is-sunday');
      if (isHoliday) classes.push('is-holiday');
      const tip = holidayName || (isSunday ? 'Sunday' : '');
      html += `<div class="${classes.join(' ')}"${tip ? ` title="${esc(tip)}"` : ''}>
        <span class="cal-day-head-main">${esc(fmtDayHeader(d))}</span>
        ${holidayName ? `<span class="cal-day-head-holiday">${esc(holidayName)}</span>` : ''}
      </div>`;
    });

    days.forEach((d, di) => {
      const dayEvents = events
        .filter((ev) => eventOverlapsDay(ev, d))
        .slice()
        .sort((a, b) => String(a.starts_at).localeCompare(String(b.starts_at)));

      const dateKey = ymd(d);
      const colClasses = ['cal-day-col'];
      if (d.getDay() === 0) colClasses.push('is-sunday');
      if (holidays[dateKey]) colClasses.push('is-holiday');
      html += `<div class="${colClasses.join(' ')}" data-day="${di}">`;
      dayEvents.forEach((ev) => {
        html += renderEventChip(ev);
      });
      html += '</div>';
    });

    html += '</div>';
    root.innerHTML = html;

    root.querySelectorAll('.cal-chip').forEach((el) => {
      el.addEventListener('click', (e) => {
        e.stopPropagation();
        const id = Number(el.dataset.id);
        const ev = events.find((x) => Number(x.id) === id);
        if (ev) openEdit(ev);
      });
    });

    root.querySelectorAll('.cal-day-col').forEach((col) => {
      col.addEventListener('click', (e) => {
        if (e.target.closest('.cal-chip')) return;
        const di = Number(col.dataset.day);
        const d = addDays(weekStart, di);
        d.setHours(9, 0, 0, 0);
        openCreate(d);
      });
    });
  }

  async function reloadWeek() {
    await Promise.all([loadEvents(), loadHolidays()]);
    renderWeek();
  }

  document.getElementById('calPrev').addEventListener('click', async () => {
    weekStart = addDays(weekStart, -7);
    await reloadWeek();
  });
  document.getElementById('calNext').addEventListener('click', async () => {
    weekStart = addDays(weekStart, 7);
    await reloadWeek();
  });
  document.getElementById('calToday').addEventListener('click', async () => {
    weekStart = startOfWeek(new Date());
    await reloadWeek();
  });

  async function init() {
    try {
      await loadMeta();
      await reloadWeek();
    } catch (err) {
      toast(err.message, true);
    }
  }

  init();
})();
