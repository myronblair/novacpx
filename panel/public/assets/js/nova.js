/**
 * NovaCPX — Shared JS utilities
 */

window.Nova = (() => {
  // ── Activity bar (thin top-of-page progress stripe for every API call) ────
  let _barEl = null, _barPct = 0, _barTimer = null, _barActive = 0;
  function _barShow() {
    _barActive++;
    if (!_barEl) {
      _barEl = document.createElement('div');
      _barEl.style.cssText = [
        'position:fixed;top:0;left:0;z-index:999999',
        'height:3px;width:0%;background:var(--primary,#6366f1)',
        'transition:width .2s ease,opacity .3s ease',
        'box-shadow:0 0 8px var(--primary,#6366f1)',
        'pointer-events:none',
      ].join(';');
      document.body.appendChild(_barEl);
    }
    _barEl.style.opacity = '1';
    _barPct = 10;
    _barEl.style.width = _barPct + '%';
    clearInterval(_barTimer);
    _barTimer = setInterval(() => {
      if (_barEl && _barPct < 85) { _barPct += (_barPct < 50 ? 8 : _barPct < 70 ? 4 : 1); _barEl.style.width = _barPct + '%'; }
    }, 200);
  }
  function _barDone() {
    _barActive = Math.max(0, _barActive - 1);
    if (_barActive > 0) return;
    clearInterval(_barTimer);
    if (_barEl) {
      _barEl.style.width = '100%';
      setTimeout(() => { if (_barEl) { _barEl.style.opacity = '0'; setTimeout(() => { _barEl?.remove(); _barEl = null; }, 300); } }, 200);
    }
  }

  // ── API ───────────────────────────────────────────────────────────────────
  async function api(endpoint, action, opts = {}) {
    const { method = 'GET', body, params } = opts;
    let url = `/api/${endpoint}/${action}`;
    if (params) url += '?' + new URLSearchParams(params);
    _barShow();
    let res;
    try {
      res = await fetch(url, {
        method,
        credentials: 'include',
        headers: body ? { 'Content-Type': 'application/json' } : {},
        body: body ? JSON.stringify(body) : undefined,
      });
    } catch (e) {
      _barDone();
      console.error(`Nova.api network error [${endpoint}/${action}]:`, e);
      return { success: false, message: 'Network error — check your connection' };
    }
    _barDone();
    if (res.status === 401) {
      const text401 = await res.text();
      try {
        const j = JSON.parse(text401);
        if (endpoint === 'auth' && action === 'login') return j;
        return { success: false, message: j.message || 'Session expired — please log in again' };
      } catch { return { success: false, message: 'Session expired — please log in again' }; }
    }
    if (res.status === 429) {
      const reset = res.headers.get('X-RateLimit-Reset');
      const wait  = reset ? Math.max(0, Math.ceil(Number(reset) - Date.now() / 1000)) : 60;
      return { success: false, message: `Rate limited — try again in ${wait}s` };
    }
    const text = await res.text();
    try {
      return JSON.parse(text);
    } catch {
      console.error(`Nova.api non-JSON from [${endpoint}/${action}] (HTTP ${res.status}):`, text.slice(0, 500));
      return { success: false, message: `Server error (HTTP ${res.status}) — see browser console` };
    }
  }

  // ── Toast ─────────────────────────────────────────────────────────────────
  let toastEl = null;
  function toast(msg, type = 'info', duration = 3500) {
    if (!toastEl) {
      toastEl = document.createElement('div');
      toastEl.style.cssText = 'position:fixed;bottom:1.5rem;right:1.5rem;z-index:9999;display:flex;flex-direction:column;gap:.5rem;max-width:380px';
      document.body.appendChild(toastEl);
    }
    const el = document.createElement('div');
    el.className = `alert alert-${type}`;
    el.style.cssText = 'animation:fadeIn .2s;cursor:pointer;box-shadow:var(--shadow)';
    el.textContent = msg;
    el.addEventListener('click', () => el.remove());
    toastEl.appendChild(el);
    setTimeout(() => el.remove(), duration);
  }

  // ── Modal ─────────────────────────────────────────────────────────────────
  function modal(title, bodyHtml, footerHtml = '') {
    const ov = document.createElement('div');
    ov.className = 'modal-overlay open';
    ov.innerHTML = `<div class="modal">
      <div class="modal-header">
        <span class="modal-title">${title}</span>
        <button class="modal-close" onclick="this.closest('.modal-overlay').remove()">&times;</button>
      </div>
      <div class="modal-body">${bodyHtml}</div>
      ${footerHtml ? `<div class="modal-footer">${footerHtml}</div>` : ''}
    </div>`;
    ov.addEventListener('click', e => { if (e.target === ov) ov.remove(); });
    document.body.appendChild(ov);
    return ov;
  }

  // ── Confirm dialog ────────────────────────────────────────────────────────
  function confirm(msg, onYes, danger = false) {
    const ov = modal('Confirm', `<p>${msg}</p>`,
      `<button class="btn btn-ghost" onclick="this.closest('.modal-overlay').remove()">Cancel</button>
       <button class="btn btn-${danger ? 'red' : 'primary'}" id="confirm-yes">Confirm</button>`
    );
    ov.querySelector('#confirm-yes').onclick = () => { ov.remove(); onYes(); };
  }

  // ── Sidebar navigation ────────────────────────────────────────────────────
  function initNav(pages) {
    document.querySelectorAll('[data-page]').forEach(link => {
      link.addEventListener('click', e => {
        e.preventDefault();
        const page = link.dataset.page;
        document.querySelectorAll('[data-page]').forEach(l => l.classList.remove('active'));
        link.classList.add('active');
        const titleEl = document.getElementById('page-title');
        if (titleEl) titleEl.textContent = link.textContent.trim();
        loadPage(page, pages);
      });
    });
  }

  function loadPage(page, pages) {
    const content = document.getElementById('page-content');
    if (!content) return;
    const fn = pages[page];
    if (fn) {
      content.innerHTML = '<div style="padding:2rem;color:var(--text-muted);text-align:center">Loading…</div>';
      Promise.resolve(fn()).then(html => { if (html) content.innerHTML = html; });
    } else {
      content.innerHTML = `<div class="card"><div class="card-body"><p class="text-muted">Page "${page}" coming soon.</p></div></div>`;
    }
  }

  // ── Progress bar helper ───────────────────────────────────────────────────
  function progressBar(pct) {
    const color = pct >= 90 ? 'red' : pct >= 70 ? 'yellow' : 'green';
    return `<div class="progress"><div class="progress-bar ${color}" style="width:${pct}%"></div></div>`;
  }

  // ── Format helpers ────────────────────────────────────────────────────────
  function bytes(n) {
    if (n >= 1073741824) return (n / 1073741824).toFixed(1) + ' GB';
    if (n >= 1048576)    return (n / 1048576).toFixed(1) + ' MB';
    if (n >= 1024)       return (n / 1024).toFixed(1) + ' KB';
    return n + ' B';
  }
  function relTime(dateStr) {
    const diff = (Date.now() - new Date(dateStr)) / 1000;
    if (diff < 60)    return 'just now';
    if (diff < 3600)  return Math.floor(diff / 60) + 'm ago';
    if (diff < 86400) return Math.floor(diff / 3600) + 'h ago';
    return Math.floor(diff / 86400) + 'd ago';
  }
  function badge(text, type = 'blue') {
    return `<span class="badge badge-${type}">${text}</span>`;
  }
  function serviceDot(status) {
    const cls = status === 'active' ? 'active' : status === 'inactive' ? 'inactive' : 'unknown';
    return `<span class="service-dot ${cls}"></span>`;
  }

  function escHtml(str) {
    return String(str ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#39;');
  }

  // ── Loading overlay ───────────────────────────────────────────────────────
  let _loadingEl = null;
  let _loadingCount = 0;
  function loading(msg = 'Working…') {
    _loadingCount++;
    if (!_loadingEl) {
      _loadingEl = document.createElement('div');
      _loadingEl.id = 'nova-loading-overlay';
      _loadingEl.style.cssText = [
        'position:fixed;inset:0;z-index:99999',
        'background:rgba(0,0,0,.55)',
        'display:flex;flex-direction:column;align-items:center;justify-content:center',
        'gap:1rem;animation:fadeIn .15s',
      ].join(';');
      _loadingEl.innerHTML = `
        <div style="width:48px;height:48px;border:4px solid rgba(255,255,255,.2);border-top-color:#fff;border-radius:50%;animation:ncpxSpin 0.7s linear infinite"></div>
        <div id="nova-loading-msg" style="color:#fff;font-size:1rem;font-weight:500;text-shadow:0 1px 3px rgba(0,0,0,.6)">${escHtml(msg)}</div>`;
      document.body.appendChild(_loadingEl);
    } else {
      document.getElementById('nova-loading-msg').textContent = msg;
    }
  }
  function loadingDone() {
    _loadingCount = Math.max(0, _loadingCount - 1);
    if (_loadingCount === 0 && _loadingEl) {
      _loadingEl.remove();
      _loadingEl = null;
    }
  }

  // Inject global CSS animations
  const style = document.createElement('style');
  style.textContent = [
    '@keyframes fadeIn{from{opacity:0;transform:translateY(8px)}to{opacity:1;transform:none}}',
    '@keyframes ncpxSpin{to{transform:rotate(360deg)}}',
  ].join('');
  document.head.appendChild(style);

  return { api, toast, modal, confirm, initNav, loadPage, progressBar, bytes, relTime, badge, serviceDot, escHtml, loading, loadingDone };
})();

// #26 Mobile sidebar toggle — shared across all panels
document.addEventListener('DOMContentLoaded', () => {
  const toggle  = document.getElementById('sidebar-toggle');
  const sidebar = document.getElementById('sidebar');
  const overlay = document.getElementById('sidebar-overlay');
  if (!toggle || !sidebar) return;

  const open  = () => { sidebar.classList.add('open');  overlay?.classList.add('open');  document.body.style.overflow = 'hidden'; };
  const close = () => { sidebar.classList.remove('open'); overlay?.classList.remove('open'); document.body.style.overflow = ''; };

  toggle.addEventListener('click', () => sidebar.classList.contains('open') ? close() : open());
  overlay?.addEventListener('click', close);

  // Close when a nav link is clicked on mobile
  sidebar.querySelectorAll('.sidebar-link').forEach(link =>
    link.addEventListener('click', () => { if (window.innerWidth <= 768) close(); })
  );
});
