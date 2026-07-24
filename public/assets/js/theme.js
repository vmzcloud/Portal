(() => {
  const KEY = 'portal-theme';

  function normalize(theme) {
    return theme === 'light' ? 'light' : 'dark';
  }

  function getTheme() {
    try {
      return normalize(localStorage.getItem(KEY));
    } catch {
      return 'dark';
    }
  }

  function applyTheme(theme) {
    const t = normalize(theme);
    document.documentElement.setAttribute('data-theme', t);
    try {
      localStorage.setItem(KEY, t);
    } catch {
      /* ignore */
    }
    document.querySelectorAll('#themeToggle, [data-theme-toggle]').forEach((btn) => {
      const isLight = t === 'light';
      btn.textContent = isLight ? '☾' : '☀';
      btn.setAttribute('aria-label', isLight ? 'Switch to dark theme' : 'Switch to light theme');
      btn.title = isLight ? 'Dark theme' : 'Light theme';
    });
  }

  function toggleTheme() {
    applyTheme(getTheme() === 'light' ? 'dark' : 'light');
  }

  applyTheme(getTheme());

  document.addEventListener('click', (e) => {
    const btn = e.target.closest('#themeToggle, [data-theme-toggle]');
    if (!btn) return;
    e.preventDefault();
    toggleTheme();
  });

  window.PortalTheme = { get: getTheme, set: applyTheme, toggle: toggleTheme };
})();
