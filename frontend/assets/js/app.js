// ============================================================
// app.js — SugarCast Core Utilities
// ============================================================
const App = {
  BASE_URL: (() => {
    const path = window.location.pathname;
    if (path.includes('/frontend')) {
      return window.location.origin + path.replace(/\/frontend(\/.*)?$/, '/backend/');
    }
    if (path.startsWith('/backend') || path === '/' || path === '/index.html') {
      return window.location.origin + '/backend/';
    }
    return window.location.origin + path.replace(/\/pages\/.+$/, '/backend/');
  })(),

  theme: localStorage.getItem('theme') || 'light',

  initTheme() {
    document.documentElement.setAttribute('data-theme', this.theme);
    this.updateThemeBtn();
    this.applyThemeToCharts();
  },

  toggleTheme() {
    this.theme = this.theme === 'light' ? 'dark' : 'light';
    localStorage.setItem('theme', this.theme);
    document.documentElement.setAttribute('data-theme', this.theme);
    this.updateThemeBtn();
    this.applyThemeToCharts();
    document.dispatchEvent(new CustomEvent('theme:changed', { detail: { theme: this.theme } }));
  },

  applyThemeToCharts() {
    if (typeof Chart === 'undefined') return;
    applyChartTheme();
    try {
      const instances = Object.values(Chart.instances || {});
      instances.forEach(chart => chart?.update?.());
    } catch (e) {
      console.warn('Theme sync failed:', e);
    }
  },

  updateThemeBtn() {
    const btn = document.getElementById('theme-toggle');
    if (!btn) return;
    const moonSVG = `<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/></svg>`;
    const sunSVG  = `<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="5"/><line x1="12" y1="1" x2="12" y2="3"/><line x1="12" y1="21" x2="12" y2="23"/><line x1="4.22" y1="4.22" x2="5.64" y2="5.64"/><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"/><line x1="1" y1="12" x2="3" y2="12"/><line x1="21" y1="12" x2="23" y2="12"/><line x1="4.22" y1="19.78" x2="5.64" y2="18.36"/><line x1="18.36" y1="5.64" x2="19.78" y2="4.22"/></svg>`;
    btn.innerHTML = this.theme === 'dark'
      ? sunSVG  + ' <span>Light</span>'
      : moonSVG + ' <span>Dark</span>';
  },

  // Toast (SVG icons, no emojis)
  toast(message, type = 'info', duration = 3500) {
    const icons = {
      success: `<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>`,
      error:   `<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>`,
      warning: `<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>`,
      info:    `<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>`,
    };
    const container = document.getElementById('toast-container');
    if (!container) return;
    const t = document.createElement('div');
    t.className = `toast toast-${type}`;
    t.innerHTML = `<span class="toast-icon">${icons[type] || icons.info}</span><span>${message}</span>`;
    container.appendChild(t);
    setTimeout(() => { t.classList.add('removing'); setTimeout(() => t.remove(), 280); }, duration);
  },

  showLoading() { document.getElementById('loading-overlay')?.classList.add('active'); },
  hideLoading() { document.getElementById('loading-overlay')?.classList.remove('active'); },

  async api(endpoint, data = null, method = 'GET') {
    const options = { method, headers: {} };
    if (data) {
      if (data instanceof FormData) {
        options.body = data;
      } else if (method === 'GET') {
        endpoint += (endpoint.includes('?') ? '&' : '?') + new URLSearchParams(data).toString();
      } else {
        const fd = new FormData();
        Object.entries(data).forEach(([k, v]) => fd.append(k, v ?? ''));
        options.body = fd;
      }
    }
    // A request that never resolves (dropped connection, a PHP process that
    // hangs, etc.) would otherwise leave buttons/tables stuck on "Loading…"
    // forever with no way to recover except a page refresh. Aborting after
    // 25s guarantees every caller's try/finally still runs.
    const controller = new AbortController();
    const timeoutId = setTimeout(() => controller.abort(), 25000);
    options.signal = controller.signal;
    try {
      const res = await fetch(endpoint, options);
      const raw = await res.text();
      try { return JSON.parse(raw); }
      catch (_) {
        console.error('Non-JSON response:', raw.slice(0, 300));
        return { success: false, message: 'Server returned an invalid response.' };
      }
    } catch (e) {
      if (e.name === 'AbortError') {
        console.error('API Error: request timed out', endpoint);
        return { success: false, message: 'Request timed out. Please check your connection and try again.' };
      }
      console.error('API Error:', e);
      return { success: false, message: 'Network error. Please try again.' };
    } finally {
      clearTimeout(timeoutId);
    }
  },

  openModal(id)  { document.getElementById(id)?.classList.add('active'); },
  closeModal(id) { document.getElementById(id)?.classList.remove('active'); },

  initSidebar() {
    const toggle  = document.getElementById('sidebar-toggle');
    const overlay = document.getElementById('sidebar-overlay');
    const sidebar = document.getElementById('sidebar');
    toggle?.addEventListener('click', () => { sidebar?.classList.toggle('mobile-open'); overlay?.classList.toggle('active'); });
    overlay?.addEventListener('click', () => { sidebar?.classList.remove('mobile-open'); overlay?.classList.remove('active'); });
  },

  initNotifPanel() {
    const btn   = document.getElementById('notif-btn');
    const panel = document.getElementById('notif-panel');
    btn?.addEventListener('click', e => { e.stopPropagation(); panel?.classList.toggle('open'); });
    document.addEventListener('click', e => { if (!panel?.contains(e.target) && e.target !== btn) panel?.classList.remove('open'); });
  },

  fmtNum(n, decimals = 0)  { return Number(n).toLocaleString('en-US', { minimumFractionDigits: decimals, maximumFractionDigits: decimals }); },
  fmtDate(dateStr)          { if (!dateStr) return '—'; return new Date(dateStr).toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' }); },
  fmtCurrency(n)            { return 'Tsh ' + this.fmtNum(n, 2); },

  setActiveNav() {
    const path = window.location.pathname;
    document.querySelectorAll('.nav-item').forEach(item => {
      const href = item.getAttribute('data-href') || '';
      item.classList.toggle('active', path.includes(href) && !!href);
    });
  },

  confirm(message, onConfirm) {
    const overlay = document.createElement('div');
    overlay.className = 'modal-overlay active';
    overlay.innerHTML = `
      <div class="modal" style="max-width:400px">
        <div class="modal-header"><h3 class="modal-title">Confirm Action</h3></div>
        <div class="modal-body"><p style="color:var(--text-secondary);font-size:0.9rem;line-height:1.6">${message}</p></div>
        <div class="modal-footer">
          <button class="btn btn-secondary" id="c-cancel">Cancel</button>
          <button class="btn btn-danger"    id="c-ok">Confirm</button>
        </div>
      </div>`;
    document.body.appendChild(overlay);
    overlay.querySelector('#c-cancel').onclick = () => overlay.remove();
    overlay.querySelector('#c-ok').onclick     = () => { overlay.remove(); onConfirm(); };
    overlay.addEventListener('click', e => { if (e.target === overlay) overlay.remove(); });
  },

  init() {
    this.initTheme();
    this.initSidebar();
    this.initNotifPanel();
    this.setActiveNav();
    document.getElementById('theme-toggle')?.addEventListener('click', () => this.toggleTheme());
    document.querySelectorAll('.modal-overlay').forEach(o => {
      o.addEventListener('click', e => { if (e.target === o) o.classList.remove('active'); });
    });
  }
};

function applyChartTheme() {
  if (typeof Chart === 'undefined') return;
  const isDark = document.documentElement.getAttribute('data-theme') === 'dark';
  Chart.defaults.color       = isDark ? '#94aec4' : '#64748b';
  Chart.defaults.borderColor = isDark ? '#1e3045' : '#e2e8f0';
  Chart.defaults.font.family = "'DM Sans', sans-serif";
  Chart.defaults.font.size   = 12;
}

document.addEventListener('DOMContentLoaded', () => {
  App.init();
  applyChartTheme();
});
