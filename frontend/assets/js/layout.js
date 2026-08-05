// ============================================================
// layout.js — Sidebar + topbar injection (SVG icons only)
// ============================================================

const IC = {
  dashboard:      `<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>`,
  data:           `<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>`,
  forecast:       `<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>`,
  recommendation: `<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>`,
  reports:        `<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg>`,
  users:          `<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>`,
  settings:       `<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>`,
  profile:        `<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>`,
  logout:         `<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>`,
  bell:           `<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>`,
  moon:           `<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/></svg>`,
  sun:            `<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="5"/><line x1="12" y1="1" x2="12" y2="3"/><line x1="12" y1="21" x2="12" y2="23"/><line x1="4.22" y1="4.22" x2="5.64" y2="5.64"/><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"/><line x1="1" y1="12" x2="3" y2="12"/><line x1="21" y1="12" x2="23" y2="12"/><line x1="4.22" y1="19.78" x2="5.64" y2="18.36"/><line x1="18.36" y1="5.64" x2="19.78" y2="4.22"/></svg>`,
  menu:           `<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></svg>`,
  chevron:        `<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="6 9 12 15 18 9"/></svg>`,
  brand:          `<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 3v18h18"/><path d="M18 9l-5 5-4-4-3 3"/></svg>`,
  lock:           `<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>`,
};

const Layout = {
  user: JSON.parse(localStorage.getItem('sc_user') || '{}'),

  getInitials(name = '') {
    return name.split(' ').map(w => w[0]).join('').toUpperCase().slice(0, 2) || 'SC';
  },

  getRoleColor(role) {
    return { admin: '#7c3aed', trader: '#0f766e', supplier: '#d97706' }[role] || '#64748b';
  },

  getAvatarHtml(name = '', picture) {
    if (picture) {
      return `<img src="${picture}" alt="${name || 'User'}">`;
    }
    const initials = (name || '').split(' ').map(w => w[0]).join('').toUpperCase().slice(0, 2) || 'SC';
    return initials;
  },

  // Persists the new avatar (or full_name, when just the initials changed)
  // to localStorage and instantly repaints every avatar on the page —
  // sidebar, topbar, profile dropdown, and the big profile-hero avatar —
  // so nothing waits for a refresh or a page navigation to catch up.
  updateProfileAvatars(picture) {
    this.user.avatar = picture;
    localStorage.setItem('sc_user', JSON.stringify(this.user));
    const selectors = ['.profile-topbar-avatar', '.user-avatar', '.profile-dd-avatar', '.profile-avatar-large'];
    selectors.forEach(selector => {
      document.querySelectorAll(selector).forEach(el => {
        if (!el) return;
        el.innerHTML = this.getAvatarHtml(this.user.full_name, picture);
      });
    });
  },

  inject(pageTitle = 'Dashboard', pageSubtitle = '') {
    if (!this.user.username) { window.location.href = '../index.html'; return; }

    const navItems = [
      { href: 'dashboard.html',      icon: IC.dashboard,      label: I18n.t('nav_dashboard'),       section: 'main'      },
      { href: 'data.html',           icon: IC.data,           label: I18n.t('nav_data'), section: 'main'      },
      { href: 'prediction.html',     icon: IC.forecast,       label: I18n.t('nav_demand'), section: 'main'      },
      { href: 'recommendation.html', icon: IC.recommendation, label: I18n.t('nav_rec'), section: 'main'      },
      { href: 'reports.html',        icon: IC.reports,        label: I18n.t('nav_reports'),         section: 'analytics' },
      ...(this.user.role === 'admin' ? [
        { href: 'users.html',        icon: IC.users,          label: I18n.t('nav_users'), section: 'admin'     },
      ] : []),
      { href: 'settings.html',       icon: IC.settings,       label: I18n.t('nav_settings'),        section: 'account'   },
      { href: 'profile.html',        icon: IC.profile,        label: I18n.t('nav_profile'),      section: 'account'   },
      { href: '../index.html',       icon: IC.logout,         label: I18n.t('nav_signout'),        section: 'account', id: 'logout-btn' },
    ];

    const sections = { main: I18n.t('nav_navigation'), analytics: I18n.t('nav_analytics'), admin: I18n.t('nav_admin'), account: I18n.t('nav_account') };
    let navHTML = '', lastSection = '';
    navItems.forEach(item => {
      if (item.section !== lastSection) {
        navHTML += `<div class="nav-section-label">${sections[item.section]}</div>`;
        lastSection = item.section;
      }
      const active  = window.location.pathname.includes(item.href.replace('..', '')) ? 'active' : '';
      const onclick = item.id === 'logout-btn' ? 'Layout.logout()' : `window.location='${item.href}'`;
      navHTML += `<div class="nav-item ${active}" data-href="${item.href}" id="${item.id||''}" onclick="${onclick}">
        <span class="nav-icon">${item.icon}</span><span>${item.label}</span></div>`;
    });

    const rc = this.getRoleColor(this.user.role);

    const profileMenu = `
      <div class="profile-dropdown" id="profile-dropdown">
        <div class="profile-dropdown-header">
          <div class="profile-dd-avatar" style="background:linear-gradient(135deg,${rc},#0f172a)">${this.getAvatarHtml(this.user.full_name, this.user.avatar)}</div>
          <div>
            <div class="profile-dd-name">${this.user.full_name || this.user.username}</div>
            <div class="profile-dd-role">${this.user.role || 'User'}</div>
          </div>
        </div>
        <div class="profile-dd-divider"></div>
        <a class="profile-dd-item" href="profile.html">${IC.profile} &nbsp;${I18n.t('nav_profile')}</a>
        <a class="profile-dd-item" href="settings.html">${IC.settings} &nbsp;${I18n.t('nav_settings')}</a>
        ${this.user.role === 'admin' ? `<a class="profile-dd-item" href="users.html">${IC.users} &nbsp;${I18n.t('nav_users')}</a>` : ''}
        <div class="profile-dd-divider"></div>
        <a class="profile-dd-item danger" href="#" onclick="Layout.logout();return false;">${IC.logout} &nbsp;${I18n.t('nav_signout')}</a>
      </div>`;

    document.body.innerHTML = `
      <div class="loading-overlay" id="loading-overlay"><div class="spinner"></div></div>
      <div id="toast-container"></div>
      <div class="app-shell">
        <aside class="sidebar" id="sidebar">
          <div class="sidebar-brand">
            <div class="brand-icon">${IC.brand}</div>
            <div><div class="brand-name">SugarCast</div><div class="brand-tagline">Mbeya Markets</div></div>
          </div>
          <nav class="sidebar-nav">${navHTML}</nav>
          <div class="sidebar-footer">
            <div class="user-pill" onclick="window.location='profile.html'">
              <div class="user-avatar" style="background:linear-gradient(135deg,${rc},#0f172a)">${this.getAvatarHtml(this.user.full_name, this.user.avatar)}</div>
              <div class="user-info">
                <div class="user-name">${this.user.full_name || this.user.username}</div>
                <div class="user-role">${this.user.role || 'User'}</div>
              </div>
              <span style="color:var(--text-muted);margin-left:auto">${IC.settings}</span>
            </div>
          </div>
        </aside>
        <div class="sidebar-overlay" id="sidebar-overlay"></div>
        <header class="topbar">
          <button class="sidebar-toggle" id="sidebar-toggle">${IC.menu}</button>
          <div class="topbar-title">
            ${pageTitle}
            ${pageSubtitle ? `<div class="topbar-subtitle">${pageSubtitle}</div>` : ''}
          </div>
          <div class="topbar-actions">
            <button class="theme-toggle" id="lang-toggle" onclick="I18n.toggle()" title="Switch language / Badilisha lugha" style="gap:5px">
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/></svg> SW
            </button>
            <div style="position:relative">
              <button class="icon-btn" id="notif-btn" title="Notifications">
                ${IC.bell}<span class="notif-dot" id="notif-dot" style="display:none"></span>
              </button>
              <div class="notif-panel" id="notif-panel">
                <div class="notif-header">
                  <span class="notif-title" data-i18n="topbar_notifs">Notifications</span>
                  <button class="btn btn-sm btn-secondary" id="notif-mark-read-btn" style="padding:3px 8px;font-size:0.7rem" data-i18n="topbar_mark_read">Mark all read</button>
                </div>
                <div id="notif-list">
                  <div class="notif-item"><div class="notif-text text-muted" style="padding:16px;text-align:center">${I18n.t('topbar_notifs_loading')}</div></div>
                </div>
              </div>
            </div>
            <div style="position:relative" id="profile-btn-wrap">
              <button class="profile-topbar-btn profile-topbar-btn-round" id="profile-btn" title="Account" aria-label="Account">
                <div class="profile-topbar-avatar" style="background:linear-gradient(135deg,${rc},#0f172a)">${this.getAvatarHtml(this.user.full_name, this.user.avatar)}</div>
              </button>
              ${profileMenu}
            </div>
          </div>
        </header>
        <main class="main-content" id="main-content"></main>
      </div>`;

    App.init();
    if (typeof I18n !== 'undefined') I18n.init();
    this._initProfileDropdown();
    this._initNotifications();
  },

  _initProfileDropdown() {
    const btn = document.getElementById('profile-btn');
    const dd  = document.getElementById('profile-dropdown');
    if (!btn || !dd) return;
    btn.addEventListener('click', e => { e.stopPropagation(); dd.classList.toggle('open'); document.getElementById('notif-panel')?.classList.remove('open'); });
    document.addEventListener('click', e => { if (!dd.contains(e.target) && e.target !== btn) dd.classList.remove('open'); });
    document.addEventListener('keydown', e => { if (e.key === 'Escape') dd.classList.remove('open'); });
  },

  // ── Real notifications ──────────────────────────────────────
  // Replaces the old static 4-item demo list: fetches from
  // notifications.php, repaints the bell's unread dot, and wires
  // "Mark all read". Polls periodically and refreshes on open so it stays
  // current without the user having to reload the page.
  _notifTypeClass: { error: 'danger', warning: 'warning', success: 'success', info: 'info' },

  _initNotifications() {
    const btn = document.getElementById('notif-btn');
    const panel = document.getElementById('notif-panel');
    const markBtn = document.getElementById('notif-mark-read-btn');
    if (!btn) return;

    this.loadNotifications();
    btn.addEventListener('click', e => {
      e.stopPropagation();
      panel?.classList.toggle('open');
      document.getElementById('profile-dropdown')?.classList.remove('open');
      this.loadNotifications();
    });
    markBtn?.addEventListener('click', e => { e.stopPropagation(); this.markAllRead(); });
    document.addEventListener('click', e => {
      if (!panel?.contains(e.target) && e.target !== btn) panel?.classList.remove('open');
    });

    // Light polling so a notification raised by another action (a
    // prediction, a CSV import, an admin event) shows up without the user
    // needing to refresh the page.
    if (this._notifPollId) clearInterval(this._notifPollId);
    this._notifPollId = setInterval(() => this.loadNotifications(), 45000);
  },

  async loadNotifications() {
    if (typeof App === 'undefined') return;
    try {
      const res = await App.api(App.BASE_URL + 'notifications.php', { action: 'list' }, 'GET');
      if (!res.success) {
        this.renderNotifications(null, 0, res.message);
        return;
      }
      this.renderNotifications(res.data || [], res.unread_count || 0);
    } catch (err) {
      console.error('loadNotifications failed:', err);
      this.renderNotifications(null, 0);
    }
  },

  renderNotifications(items, unreadCount, errorMessage) {
    const list = document.getElementById('notif-list');
    const dot  = document.getElementById('notif-dot');
    if (dot) dot.style.display = unreadCount > 0 ? 'block' : 'none';
    if (!list) return;

    const visibleItems = (items || []).filter(n => Number(n.is_read) !== 1);

    if (items === null) {
      // Guarantees the panel never gets stuck on the initial "Loading…"
      // placeholder if the fetch fails — same guarantee as the rest of
      // the app's try/catch/finally hardening.
      list.innerHTML = `<div class="notif-item"><div class="notif-text text-muted" style="padding:16px;text-align:center">
        ${errorMessage || I18n.t('topbar_notifs_error')}
        <br><a href="#" onclick="Layout.loadNotifications();return false;" style="color:var(--brand-primary);font-weight:600">${I18n.t('topbar_notifs_retry')}</a>
      </div></div>`;
      return;
    }

    if (!visibleItems.length) {
      list.innerHTML = `<div class="notif-item"><div class="notif-text text-muted" style="padding:16px;text-align:center">${I18n.t('topbar_notifs_empty')}</div></div>`;
      return;
    }

    list.innerHTML = visibleItems.map(n => {
      const cls = this._notifTypeClass[n.type] || 'info';
      return `<div class="notif-item unread">
        <div class="notif-dot-type ${cls}"></div>
        <div>
          <div class="notif-title-text">${n.title}</div>
          <div class="notif-text">${n.message}</div>
          <div class="text-xs text-muted" style="margin-top:2px">${this.timeAgo(n.created_at)}</div>
        </div>
      </div>`;
    }).join('');
  },

  async markAllRead() {
    if (typeof App === 'undefined') return;
    const res = await App.api(App.BASE_URL + 'notifications.php', { action: 'mark_read' }, 'POST');
    if (res.success) {
      this.renderNotifications([], 0, '');
      App.toast(I18n.t('topbar_notifs_marked_read'), 'success');
      this.loadNotifications();
    }
  },

  timeAgo(dateStr) {
    if (!dateStr) return '';
    const seconds = Math.floor((Date.now() - new Date(dateStr.replace(' ', 'T'))) / 1000);
    if (seconds < 60) return I18n.t('time_just_now');
    const mins = Math.floor(seconds / 60);
    if (mins < 60) return `${mins} ${I18n.t('time_min_ago')}`;
    const hrs = Math.floor(mins / 60);
    if (hrs < 24) return `${hrs} ${I18n.t('time_hr_ago')}`;
    const days = Math.floor(hrs / 24);
    if (days < 7) return `${days} ${I18n.t('time_day_ago')}`;
    return App.fmtDate(dateStr);
  },

  logout() {
    App.confirm(I18n.t('logout_confirm'), () => {
      localStorage.removeItem('sc_user');
      window.location.href = '../index.html';
    });
  },

  // Register a function to be called when language changes
  onLanguageChange(callback) {
    if (typeof callback !== 'function') return;
    window.addEventListener('i18n:changed', callback);
  }
};
