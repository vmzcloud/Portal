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
      document.getElementById('panel-teamcal').classList.toggle('hidden', panel !== 'teamcal');
      if (panel === 'teamcal') loadTeamCal().catch((err) => toast(err.message, true));
    });
  });

  let users = [];
  let groups = [];

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
        <td><span class="badge ${u.role === 'admin' ? 'admin' : ''}">${esc(u.role)}</span></td>
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
      btn.addEventListener('click', async () => {
        const id = Number(btn.dataset.delUser);
        if (!confirm('Delete this user? Their bookmarks will also be deleted.')) return;
        try {
          await api('/api/users.php', { method: 'DELETE', body: { id } });
          toast('User deleted');
          await refresh();
        } catch (err) {
          toast(err.message, true);
        }
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

  async function refresh() {
    await loadUsers();
    await loadGroups();
  }

  refresh().catch((err) => toast(err.message, true));
})();
