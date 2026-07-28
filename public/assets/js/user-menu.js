(() => {
  const csrf = document.body?.dataset?.csrf || '';

  function esc(s) {
    return String(s ?? '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
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

  function setBadge(n) {
    const count = Math.max(0, Number(n) || 0);
    const label = count > 99 ? '99+' : String(count);
    ['notifBadge', 'notifMenuBadge'].forEach((id) => {
      const el = document.getElementById(id);
      if (!el) return;
      el.textContent = label;
      el.classList.toggle('hidden', count <= 0);
    });
  }

  async function refreshUnread() {
    if (!document.getElementById('notifBell')) return;
    try {
      const data = await api('/api/notifications.php?count=1');
      setBadge(data?.unread ?? 0);
    } catch {
      /* ignore */
    }
  }

  function closeMenu() {
    const dd = document.getElementById('userMenuDropdown');
    const trigger = document.getElementById('userMenuTrigger');
    if (dd) dd.classList.add('hidden');
    if (trigger) trigger.setAttribute('aria-expanded', 'false');
  }

  function toggleMenu() {
    const dd = document.getElementById('userMenuDropdown');
    const trigger = document.getElementById('userMenuTrigger');
    if (!dd || !trigger) return;
    const open = dd.classList.contains('hidden');
    dd.classList.toggle('hidden', !open);
    trigger.setAttribute('aria-expanded', open ? 'true' : 'false');
  }

  document.getElementById('userMenuTrigger')?.addEventListener('click', (e) => {
    e.preventDefault();
    e.stopPropagation();
    toggleMenu();
  });

  document.addEventListener('click', (e) => {
    const menu = document.getElementById('userMenu');
    if (!menu || menu.contains(e.target)) return;
    closeMenu();
  });

  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') closeMenu();
  });

  window.PortalNotifications = {
    refreshUnread,
    setBadge,
    api,
    esc,
  };

  refreshUnread();
  setInterval(refreshUnread, 60000);
  document.addEventListener('visibilitychange', () => {
    if (document.visibilityState === 'visible') refreshUnread();
  });
})();
