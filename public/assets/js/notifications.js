(() => {
  const toastEl = document.getElementById('toast');
  const PN = window.PortalNotifications || {};

  function toast(msg, isError = false) {
    if (!toastEl) return;
    toastEl.textContent = msg;
    toastEl.classList.toggle('error', !!isError);
    toastEl.classList.add('show');
    clearTimeout(toast._t);
    toast._t = setTimeout(() => toastEl.classList.remove('show'), 2600);
  }

  const api = PN.api || (async () => {
    throw new Error('API unavailable');
  });
  const esc = PN.esc || ((s) => String(s ?? ''));

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

  function typeLabel(type) {
    if (type === 'todo_assigned') return 'Todo';
    if (type === 'note_shared') return 'Notes';
    if (type === 'event_day_before') return 'Calendar';
    return 'Info';
  }

  function typeClass(type) {
    if (type === 'todo_assigned') return 'is-todo';
    if (type === 'note_shared') return 'is-notes';
    if (type === 'event_day_before') return 'is-cal';
    return '';
  }

  function render(items, unread) {
    const list = document.getElementById('notifList');
    const summary = document.getElementById('notifSummary');
    if (summary) {
      summary.textContent = items.length
        ? `${items.length} notification(s)${unread ? ` · ${unread} unread` : ''}`
        : 'No notifications yet.';
    }
    if (!list) return;
    if (!items.length) {
      list.innerHTML = '<div class="notif-empty">You have no notifications.</div>';
      return;
    }
    list.innerHTML = items.map((n) => `
      <article class="notif-item${n.is_read ? '' : ' is-unread'}" data-id="${n.id}" data-link="${esc(n.link_url || '')}">
        <div class="notif-item-type ${typeClass(n.type)}">${esc(typeLabel(n.type))}</div>
        <div class="notif-item-main">
          <div class="notif-item-title">${esc(n.title)}</div>
          <div class="notif-item-body">${esc(n.body)}</div>
          <div class="notif-item-meta">
            ${n.actor_name ? `<span>${esc(n.actor_name)}</span>` : ''}
            <span>${esc(fmtWhen(n.created_at))}</span>
          </div>
        </div>
        <div class="notif-item-actions">
          ${n.is_read ? '' : '<button type="button" class="btn btn-sm" data-read>Mark read</button>'}
          <button type="button" class="btn btn-sm btn-ghost" data-del title="Dismiss">×</button>
        </div>
      </article>
    `).join('');

    list.querySelectorAll('.notif-item').forEach((row) => {
      row.addEventListener('click', async (e) => {
        if (e.target.closest('[data-read], [data-del]')) return;
        const id = Number(row.dataset.id);
        const link = row.dataset.link || '';
        try {
          if (row.classList.contains('is-unread')) {
            const data = await api('/api/notifications.php', { method: 'PUT', body: { id } });
            if (PN.setBadge) PN.setBadge(data.unread);
          }
        } catch {
          /* still navigate */
        }
        if (link) window.location.href = link;
        else load().catch((err) => toast(err.message, true));
      });

      row.querySelector('[data-read]')?.addEventListener('click', async (e) => {
        e.stopPropagation();
        const id = Number(row.dataset.id);
        try {
          const data = await api('/api/notifications.php', { method: 'PUT', body: { id } });
          if (PN.setBadge) PN.setBadge(data.unread);
          await load();
        } catch (err) {
          toast(err.message, true);
        }
      });

      row.querySelector('[data-del]')?.addEventListener('click', async (e) => {
        e.stopPropagation();
        const id = Number(row.dataset.id);
        try {
          const data = await api('/api/notifications.php', { method: 'DELETE', body: { id } });
          if (PN.setBadge) PN.setBadge(data.unread);
          await load();
        } catch (err) {
          toast(err.message, true);
        }
      });
    });
  }

  async function load() {
    const data = await api('/api/notifications.php');
    const items = Array.isArray(data.items) ? data.items : [];
    const unread = data.unread ?? 0;
    if (PN.setBadge) PN.setBadge(unread);
    render(items, unread);
  }

  document.getElementById('notifMarkAll')?.addEventListener('click', async () => {
    try {
      const data = await api('/api/notifications.php', { method: 'PUT', body: { all: true } });
      if (PN.setBadge) PN.setBadge(data.unread);
      toast('All marked as read');
      await load();
    } catch (err) {
      toast(err.message, true);
    }
  });

  load().catch((err) => toast(err.message, true));
})();
