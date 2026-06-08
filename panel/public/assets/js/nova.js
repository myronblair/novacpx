/**
 * NovaCPX — Shared JS utilities
 */

window.Nova = (() => {
  // ── API ───────────────────────────────────────────────────────────────────
  async function api(endpoint, action, opts = {}) {
    const { method = 'GET', body, params } = opts;
    let url = `/api/${endpoint}/${action}`;
    if (params) url += '?' + new URLSearchParams(params);
    let res;
    try {
      res = await fetch(url, {
        method,
        credentials: 'include',
        headers: body ? { 'Content-Type': 'application/json' } : {},
        body: body ? JSON.stringify(body) : undefined,
      });
    } catch (e) {
      console.error(`Nova.api network error [${endpoint}/${action}]:`, e);
      return { success: false, message: 'Network error — check your connection' };
    }
    if (res.status === 401) { location.href = '/?redirect=' + encodeURIComponent(location.pathname); return null; }
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
    return String(str ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
  }

  // Inject global CSS animation
  const style = document.createElement('style');
  style.textContent = '@keyframes fadeIn{from{opacity:0;transform:translateY(8px)}to{opacity:1;transform:none}}';
  document.head.appendChild(style);

  return { api, toast, modal, confirm, initNav, loadPage, progressBar, bytes, relTime, badge, serviceDot, escHtml };
})();
