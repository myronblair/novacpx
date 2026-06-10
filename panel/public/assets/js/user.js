/**
 * NovaCPX User Panel JS — all pages
 */

/* ── Auth guard ──────────────────────────────────────────────────────────── */
let _user = null;

async function initUser() {
  const res = await Nova.api('auth', 'me');
  if (!res || !res.success) {
    document.getElementById('auth-check').innerHTML = renderLogin();
    document.getElementById('main-layout').style.display = 'none';
    return false;
  }
  _user = res.data;
  document.getElementById('user-name').textContent = _user.username || 'User';
  document.getElementById('auth-check').style.display = 'none';
  document.getElementById('main-layout').style.display = '';

  // Show impersonation banner if an admin/reseller is acting as this user
  if (_user.impersonated_by) {
    const imp = _user.impersonated_by;
    const returnUrl = imp.role === 'reseller'
      ? location.href.replace(/:\d+/, ':8881')
      : location.href.replace(/:\d+/, ':8882');
    const banner = document.createElement('div');
    banner.id = 'impersonation-banner';
    banner.style.cssText = [
      'position:fixed;top:0;left:0;right:0;z-index:99998',
      'background:linear-gradient(135deg,#f59e0b,#d97706)',
      'color:#fff;font-size:.82rem;font-weight:600',
      'display:flex;align-items:center;justify-content:center;gap:1rem',
      'padding:.45rem 1rem',
      'box-shadow:0 2px 8px rgba(0,0,0,.25)',
    ].join(';');
    banner.innerHTML = `
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
      Acting as <strong>${Nova.escHtml(_user.username)}</strong> — logged in as ${Nova.escHtml(imp.username)} (${imp.role})
      <button onclick="exitImpersonation()" style="background:rgba(0,0,0,.25);border:none;color:#fff;padding:.2rem .75rem;border-radius:4px;cursor:pointer;font-weight:600;font-size:.8rem">
        ← Return to ${imp.role === 'reseller' ? 'Reseller' : 'Admin'} Panel
      </button>`;
    document.body.prepend(banner);
    // Push content down so the fixed banner doesn't overlap
    const layout = document.getElementById('main-layout');
    if (layout) layout.style.marginTop = '36px';
  }

  return true;
}

window.exitImpersonation = async () => {
  Nova.loading('Returning…');
  const res = await Nova.api('auth', 'unimpersonate', { method: 'POST' });
  Nova.loadingDone();
  if (res?.success && res.data?.portal_url) {
    window.location.href = res.data.portal_url;
  } else {
    Nova.toast(res?.message || 'Could not return', 'error');
  }
};

function renderLogin() {
  return `<div class="login-wrap">
    <div class="login-card">
      <div style="text-align:center;margin-bottom:2rem">
        <img src="/assets/img/nova-logo.svg" style="height:42px;margin-bottom:.5rem">
        <div style="color:var(--muted);font-size:.85rem">User Portal · Port 8880</div>
      </div>
      <div class="form-group">
        <label class="form-label">Username</label>
        <input id="li-user" type="text" class="form-control" placeholder="username" autocomplete="username">
      </div>
      <div class="form-group">
        <label class="form-label">Password</label>
        <input id="li-pass" type="password" class="form-control" placeholder="password" autocomplete="current-password">
      </div>
      <button class="btn btn-primary" style="width:100%" onclick="doLogin()">Sign In</button>
      <div id="li-err" style="color:var(--red);margin-top:.75rem;text-align:center;display:none"></div>
    </div>
  </div>`;
}

async function doLogin() {
  const u = document.getElementById('li-user')?.value;
  const p = document.getElementById('li-pass')?.value;
  const err = document.getElementById('li-err');
  Nova.loading('Signing in…');
  const res = await Nova.api('auth', 'login', { method: 'POST', body: { username: u, password: p } });
  Nova.loadingDone();
  if (res?.success) {
    if (res.data?.portal_url && !res.data.portal_url.includes(':8880')) {
      location.href = res.data.portal_url;
    } else {
      location.reload();
    }
  } else {
    if (err) { err.textContent = res?.message || 'Login failed'; err.style.display = 'block'; }
  }
}
window.doLogin = doLogin;

/* ── Pages ───────────────────────────────────────────────────────────────── */

const userPages = {
  dashboard,
  domains,
  email,
  databases,
  ftp,
  ssl,
  php: phpPage,
  cron,
  files,
  stats: statsPage,
  backups,
  docker: dockerPage,
  'change-password': changePasswordPage,
};

/* ── Dashboard ───────────────────────────────────────────────────────────── */
const _quickIcons = {
  domains:   '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="24" height="24"><circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/></svg>',
  email:     '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="24" height="24"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>',
  databases: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="24" height="24"><ellipse cx="12" cy="5" rx="9" ry="3"/><path d="M21 12c0 1.66-4 3-9 3s-9-1.34-9-3"/><path d="M3 5v14c0 1.66 4 3 9 3s9-1.34 9-3V5"/></svg>',
  ftp:       '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="24" height="24"><path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/></svg>',
  ssl:       '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="24" height="24"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>',
  php:       '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="24" height="24"><polyline points="16 18 22 12 16 6"/><polyline points="8 6 2 12 8 18"/></svg>',
  cron:      '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="24" height="24"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>',
  files:     '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="24" height="24"><path d="M13 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V9z"/><polyline points="13 2 13 9 20 9"/></svg>',
};

async function dashboard(el) {
  el.innerHTML = `<div class="page-header"><h2 class="page-title">Dashboard</h2></div>
    <div id="dash-rings" class="stats-grid">
      ${['Disk','Databases','Email Accts','FTP Accts'].map(l => `<div class="stat-card"><div class="stat-label">${l}</div><div class="stat-value">—</div></div>`).join('')}
    </div>
    <div class="card" style="margin-top:1.5rem">
      <div class="card-header"><span class="card-title">Quick Access</span></div>
      <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(130px,1fr));gap:1rem;padding:1.25rem">
        ${[
          ['domains','Domains'],['email','Email'],['databases','Databases'],['ftp','FTP'],
          ['ssl','SSL'],['php','PHP'],['cron','Cron Jobs'],['files','File Manager'],
        ].map(([page, label]) => `
          <button class="btn" style="display:flex;flex-direction:column;align-items:center;gap:.5rem;padding:1rem;background:var(--bg3);border:1px solid var(--border);border-radius:var(--radius);color:var(--primary)" onclick="userNav('${page}')">
            ${_quickIcons[page] || ''}
            <span style="font-size:.8rem;color:var(--text)">${label}</span>
          </button>`).join('')}
      </div>
    </div>`;

  const res = await Nova.api('stats', 'account');
  if (res?.success) {
    const d = res.data;
    const rings = document.getElementById('dash-rings');
    rings.innerHTML = [
      { label: 'Disk', used: d.disk_mb, limit: d.disk_limit, unit: 'MB' },
      { label: 'Databases', used: d.databases, limit: d.db_limit, unit: '' },
      { label: 'Email Accts', used: d.emails, limit: d.email_limit, unit: '' },
      { label: 'FTP Accts', used: d.ftp, limit: d.ftp_limit, unit: '' },
    ].map(item => {
      const pct = item.limit > 0 ? Math.min(100, Math.round(item.used / item.limit * 100)) : 0;
      const r = 26, circ = 2 * Math.PI * r;
      const dash = circ - (pct / 100) * circ;
      const color = pct > 85 ? 'var(--red)' : pct > 65 ? 'var(--yellow)' : 'var(--primary)';
      return `<div class="stat-card" style="text-align:center">
        <svg width="72" height="72" viewBox="0 0 72 72" style="margin:0 auto .5rem">
          <circle cx="36" cy="36" r="${r}" fill="none" stroke="var(--border)" stroke-width="5"/>
          <circle cx="36" cy="36" r="${r}" fill="none" stroke="${color}" stroke-width="5"
            stroke-dasharray="${circ}" stroke-dashoffset="${dash}"
            stroke-linecap="round" transform="rotate(-90 36 36)"/>
          <text x="36" y="40" text-anchor="middle" fill="var(--text)" font-size="14" font-weight="600">${pct}%</text>
        </svg>
        <div style="font-size:.75rem;color:var(--muted)">${item.label}</div>
        <div style="font-size:.85rem">${item.used}${item.unit} / ${item.limit > 0 ? item.limit + item.unit : '∞'}</div>
      </div>`;
    }).join('');
  }
}

/* ── Domains ────────────────────────────────────────────────────────────── */
async function domains(el) {
  el.innerHTML = `<div class="page-header">
    <h2 class="page-title">Domains</h2>
    <div style="display:flex;gap:.5rem">
      <button class="btn btn-primary btn-sm" onclick="addDomain('addon')">+ Addon Domain</button>
      <button class="btn btn-sm" onclick="addDomain('subdomain')">+ Subdomain</button>
      <button class="btn btn-sm" onclick="addDomain('alias')">+ Alias</button>
    </div>
  </div>
  <div class="card"><div id="domains-list"><div class="loading">Loading…</div></div></div>`;

  await loadDomainsList();
}

async function loadDomainsList() {
  const el = document.getElementById('domains-list');
  if (!el) return;
  const res = await Nova.api('domains', 'list');
  if (!res?.success) { el.innerHTML = '<div class="empty">No domains</div>'; return; }
  const rows = res.data;
  el.innerHTML = `<table class="table"><thead><tr><th>Domain</th><th>Type</th><th>SSL</th><th>Actions</th></tr></thead><tbody>
    ${rows.map(d => `<tr>
      <td><strong>${d.domain}</strong></td>
      <td>${Nova.badge(d.type, d.is_primary ? 'primary' : 'default')}</td>
      <td>${d.ssl_enabled ? Nova.badge('SSL','green') : `<button class="btn btn-xs" onclick="issueSSL(${d.id},'${d.domain}')">Get SSL</button>`}</td>
      <td>
        ${!d.is_primary ? `<button class="btn btn-xs btn-danger" onclick="removeDomain(${d.id},'${d.domain}')">Remove</button>` : ''}
      </td>
    </tr>`).join('')}
  </tbody></table>`;
}
window.loadDomainsList = loadDomainsList;

window.addDomain = (type) => {
  const fields = type === 'subdomain'
    ? `<input id="md-sub" class="form-control" placeholder="subdomain prefix (e.g. blog)">`
    : `<input id="md-domain" class="form-control" placeholder="domain.com">`;
  Nova.modal(`Add ${type.charAt(0).toUpperCase()+type.slice(1)}`, `
    <div class="form-group"><label class="form-label">${type === 'subdomain' ? 'Subdomain' : 'Domain'}</label>${fields}</div>`,
    `<button class="btn btn-primary" onclick="submitAddDomain('${type}')">Add</button>`
  );
};

window.submitAddDomain = async (type) => {
  let body = { type };
  if (type === 'subdomain') body.subdomain = document.getElementById('md-sub')?.value;
  else body.domain = document.getElementById('md-domain')?.value;

  const action = type === 'subdomain' ? 'add-subdomain' : type === 'alias' ? 'add-alias' : 'add-addon';
  const res = await Nova.api('domains', action, { method: 'POST', body });
  if (res?.success) { Nova.toast(res.message,'success'); document.querySelector('.modal-overlay')?.remove(); loadDomainsList(); }
  else Nova.toast(res?.message || 'Failed','error');
};

window.removeDomain = (id, domain) => {
  Nova.confirm(`Remove domain ${domain}? This deletes the vhost and DNS zone.`, async () => {
    const res = await Nova.api('domains', 'remove', { method: 'POST', body: { id } });
    if (res?.success) { Nova.toast('Domain removed','success'); loadDomainsList(); }
    else Nova.toast(res?.message || 'Failed','error');
  }, true);
};

function _sslStream(params, onSuccess) {
  const termId = 'ssl-term-' + Date.now();
  Nova.modal(`SSL: ${params.domain}`, `
    <div id="${termId}" style="background:#1a1a2e;color:#e0e0e0;font-family:monospace;font-size:.82rem;
         padding:1rem;border-radius:6px;height:260px;overflow-y:auto;white-space:pre-wrap;line-height:1.5">
      <span style="color:#7ec8e3">Requesting certificate…</span>\n
    </div>`,
    `<button class="btn btn-ghost" id="ssl-term-close" onclick="this.closest('.modal-overlay').remove()">Close</button>`);
  const term   = document.getElementById(termId);
  const append = t => { term.textContent += t; term.scrollTop = term.scrollHeight; };
  fetch('/api/ssl/issue', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(params),
    credentials: 'same-origin',
  }).then(resp => {
    if (!resp.ok) { append(`\nHTTP error ${resp.status}`); return; }
    const reader = resp.body.getReader();
    const dec = new TextDecoder();
    let buf = '';
    const read = () => reader.read().then(({ done, value }) => {
      if (done) { append('\n[done]'); return; }
      buf += dec.decode(value, { stream: true });
      const parts = buf.split('\n\n');
      buf = parts.pop();
      for (const part of parts) {
        const m = part.match(/^data: (.+)$/m);
        if (!m) continue;
        try {
          const obj = JSON.parse(m[1]);
          if (obj.line) { append(obj.line); }
          else if (obj.done) {
            const btn = document.getElementById('ssl-term-close');
            if (btn) {
              btn.textContent = obj.success ? 'Done ✓' : 'Close';
              btn.className   = obj.success ? 'btn btn-primary' : 'btn btn-ghost';
              if (obj.success) btn.onclick = () => { document.querySelector('.modal-overlay')?.remove(); if (onSuccess) onSuccess(); };
            }
          }
        } catch(e) {}
      }
      read();
    }).catch(err => append(`\n[error: ${err.message}]`));
    read();
  }).catch(err => append(`\n[error: ${err.message}]`));
}

window.issueSSL = (domainId, domain) => _sslStream({ domain }, () => loadDomainsList());
window.issueSSL = window.issueSSL;

/* ── Email ──────────────────────────────────────────────────────────────── */
async function email(el) {
  el.innerHTML = `<div class="page-header">
    <h2 class="page-title">Email Accounts</h2>
    <button class="btn btn-primary btn-sm" onclick="addEmailAccount()">+ Add Account</button>
  </div>
  <div class="card"><div id="email-list"><div class="loading">Loading…</div></div></div>
  <div class="page-header" style="margin-top:1.5rem">
    <h3 class="page-title" style="font-size:1rem">Forwarders</h3>
    <button class="btn btn-sm" onclick="addForwarder()">+ Add Forwarder</button>
  </div>
  <div class="card"><div id="forwarder-list"><div class="loading">Loading…</div></div></div>`;

  loadEmailList();
  loadForwarderList();
}

async function loadEmailList() {
  const el = document.getElementById('email-list');
  if (!el) return;
  const res = await Nova.api('email', 'list');
  if (!res?.success || !res.data.length) { el.innerHTML = '<div class="empty">No email accounts yet.</div>'; return; }
  el.innerHTML = `<table class="table"><thead><tr><th>Email</th><th>Quota</th><th>Status</th><th>Actions</th></tr></thead><tbody>
    ${res.data.map(a => `<tr>
      <td>${a.email}</td>
      <td>${a.quota_mb > 0 ? a.quota_mb + 'MB' : 'Unlimited'}</td>
      <td>${Nova.badge(a.status, a.status === 'active' ? 'green' : 'yellow')}</td>
      <td style="display:flex;gap:.25rem">
        <a href="#" onclick="openWebmail('${a.email}')" class="btn btn-xs">Webmail</a>
        <button class="btn btn-xs" onclick="changeEmailPass(${a.id})">Passwd</button>
        <button class="btn btn-xs btn-danger" onclick="deleteEmail(${a.id},'${a.email}')">Del</button>
      </td>
    </tr>`).join('')}
  </tbody></table>`;
}
window.loadEmailList = loadEmailList;

window.addEmailAccount = async () => {
  const dr = await Nova.api('domains', 'list');
  const domains = (dr?.data || []).map(d => d.domain).filter(Boolean);
  const domainOpts = domains.length
    ? domains.map(d => `<option value="${Nova.escHtml(d)}">${Nova.escHtml(d)}</option>`).join('')
    : '<option value="" disabled>No domains on this account</option>';
  Nova.modal('Add Email Account', `
    <div class="form-group">
      <label class="form-label">Email Address</label>
      <div style="display:flex;align-items:center;gap:.4rem">
        <input id="em-local" class="form-control" placeholder="username" style="flex:1;min-width:0">
        <span style="color:var(--text-muted);font-size:1rem;flex-shrink:0">@</span>
        <select id="em-domain" class="form-control" style="flex:1.2;min-width:0">${domainOpts}</select>
      </div>
    </div>
    <div class="form-group"><label class="form-label">Password</label><input id="em-pass" type="password" class="form-control"></div>
    <div class="form-group"><label class="form-label">Quota (MB, 0=unlimited)</label><input id="em-quota" type="number" class="form-control" value="0"></div>`,
    `<button class="btn btn-primary" onclick="submitAddEmail()">Create</button>`
  );
};

window.submitAddEmail = async () => {
  const local  = (document.getElementById('em-local')?.value || '').trim();
  const domain = document.getElementById('em-domain')?.value || '';
  if (!local)  { Nova.toast('Enter a username', 'error'); return; }
  if (!domain) { Nova.toast('Select a domain', 'error'); return; }
  const res = await Nova.api('email', 'create', { method: 'POST', body: {
    email: `${local}@${domain}`,
    password: document.getElementById('em-pass')?.value,
    quota_mb: parseInt(document.getElementById('em-quota')?.value || '0'),
  }});
  if (res?.success) { Nova.toast('Email account created','success'); document.querySelector('.modal-overlay')?.remove(); loadEmailList(); }
  else Nova.toast(res?.message || 'Failed','error');
};

window.changeEmailPass = (id) => {
  Nova.modal('Change Email Password', `<div class="form-group"><label class="form-label">New Password</label><input id="ep-pass" type="password" class="form-control"></div>`,
    `<button class="btn btn-primary" onclick="submitEmailPass(${id})">Update</button>`);
};
window.submitEmailPass = async (id) => {
  const res = await Nova.api('email', 'change-password', { method: 'POST', body: { id, password: document.getElementById('ep-pass')?.value }});
  if (res?.success) { Nova.toast('Password updated','success'); document.querySelector('.modal-overlay')?.remove(); }
  else Nova.toast(res?.message || 'Failed','error');
};

window.deleteEmail = (id, addr) => {
  Nova.confirm(`Delete ${addr}?`, async () => {
    const res = await Nova.api('email', 'delete', { method: 'POST', body: { id }});
    if (res?.success) { Nova.toast('Email deleted','success'); loadEmailList(); }
  }, true);
};

window.openWebmail = (email) => {
  Nova.api('webmail', 'url').then(res => {
    if (res?.success) window.open(res.data.url, '_blank');
  });
};

async function loadForwarderList() {
  const el = document.getElementById('forwarder-list');
  if (!el) return;
  const res = await Nova.api('email', 'forwarders');
  if (!res?.success || !res.data.length) { el.innerHTML = '<div class="empty">No forwarders yet.</div>'; return; }
  el.innerHTML = `<table class="table"><thead><tr><th>From</th><th>To</th><th></th></tr></thead><tbody>
    ${res.data.map(f => `<tr><td>${f.source}</td><td>${f.destination}</td>
      <td><button class="btn btn-xs btn-danger" onclick="deleteFwd(${f.id})">Del</button></td></tr>`).join('')}
  </tbody></table>`;
}

window.addForwarder = () => {
  Nova.modal('Add Forwarder', `
    <div class="form-group"><label class="form-label">From</label><input id="fw-from" class="form-control" placeholder="from@yourdomain.com"></div>
    <div class="form-group"><label class="form-label">To</label><input id="fw-to" class="form-control" placeholder="to@example.com"></div>`,
    `<button class="btn btn-primary" onclick="submitFwd()">Add</button>`);
};
window.submitFwd = async () => {
  const res = await Nova.api('email', 'add-forwarder', { method: 'POST', body: { source: document.getElementById('fw-from')?.value, destination: document.getElementById('fw-to')?.value }});
  if (res?.success) { Nova.toast('Forwarder added','success'); document.querySelector('.modal-overlay')?.remove(); loadForwarderList(); }
  else Nova.toast(res?.message || 'Failed','error');
};
window.deleteFwd = async (id) => {
  const res = await Nova.api('email', 'delete-forwarder', { method: 'POST', body: { id }});
  if (res?.success) { Nova.toast('Deleted','success'); loadForwarderList(); }
};

/* ── Databases ──────────────────────────────────────────────────────────── */
async function databases(el) {
  el.innerHTML = `<div class="page-header">
    <h2 class="page-title">Databases</h2>
    <div style="display:flex;gap:.5rem">
      <button class="btn btn-primary btn-sm" onclick="addDB('mysql')">+ MySQL</button>
      <button class="btn btn-sm" onclick="addDB('postgresql')">+ PostgreSQL</button>
    </div>
  </div>
  <div class="card"><div id="db-list"><div class="loading">Loading…</div></div></div>`;
  loadDBList();
}

async function loadDBList() {
  const el = document.getElementById('db-list');
  if (!el) return;
  const res = await Nova.api('databases', 'list');
  if (!res?.success || !res.data.length) { el.innerHTML = '<div class="empty">No databases yet.</div>'; return; }
  el.innerHTML = `<table class="table"><thead><tr><th>Database</th><th>User</th><th>Type</th><th>Size</th><th>Actions</th></tr></thead><tbody>
    ${res.data.map(d => `<tr>
      <td><strong>${d.db_name}</strong></td>
      <td>${d.db_user}</td>
      <td>${Nova.badge(d.db_type,'default')}</td>
      <td>${d.size || '—'}</td>
      <td style="display:flex;gap:.25rem">
        <button class="btn btn-xs" onclick="changeDBPass(${d.id})">Passwd</button>
        <button class="btn btn-xs btn-danger" onclick="dropDB(${d.id},'${d.db_name}')">Drop</button>
      </td>
    </tr>`).join('')}
  </tbody></table>`;
}
window.loadDBList = loadDBList;

window.addDB = (type) => {
  Nova.modal(`Create ${type.toUpperCase()} Database`, `
    <div class="form-group"><label class="form-label">Database Name (auto-prefixed)</label><input id="dbn-name" class="form-control" placeholder="mydb"></div>
    <div class="form-group"><label class="form-label">DB Username (auto-prefixed)</label><input id="dbn-user" class="form-control" placeholder="myuser"></div>
    <div class="form-group"><label class="form-label">Password</label><input id="dbn-pass" type="password" class="form-control"></div>`,
    `<button class="btn btn-primary" onclick="submitAddDB('${type}')">Create</button>`);
};
window.submitAddDB = async (type) => {
  const res = await Nova.api('databases', 'create', { method:'POST', body: { db_type: type, db_name: document.getElementById('dbn-name')?.value, db_user: document.getElementById('dbn-user')?.value, db_pass: document.getElementById('dbn-pass')?.value }});
  if (res?.success) { Nova.toast('Database created','success'); document.querySelector('.modal-overlay')?.remove(); loadDBList(); }
  else Nova.toast(res?.message || 'Failed','error');
};
window.changeDBPass = (id) => {
  Nova.modal('Change DB Password', `<div class="form-group"><label class="form-label">New Password</label><input id="dbp-pass" type="password" class="form-control"></div>`,
    `<button class="btn btn-primary" onclick="submitDBPass(${id})">Update</button>`);
};
window.submitDBPass = async (id) => {
  const res = await Nova.api('databases', 'change-password', { method:'POST', body:{ id, password: document.getElementById('dbp-pass')?.value }});
  if (res?.success) { Nova.toast('Password updated','success'); document.querySelector('.modal-overlay')?.remove(); }
  else Nova.toast(res?.message,'error');
};
window.dropDB = (id, name) => {
  Nova.confirm(`Drop database ${name}? All data will be permanently deleted.`, async () => {
    const res = await Nova.api('databases', 'drop', { method:'POST', body:{ id }});
    if (res?.success) { Nova.toast('Database dropped','success'); loadDBList(); }
    else Nova.toast(res?.message,'error');
  }, true);
};

/* ── FTP ────────────────────────────────────────────────────────────────── */
async function ftp(el) {
  el.innerHTML = `<div class="page-header">
    <h2 class="page-title">FTP Accounts</h2>
    <button class="btn btn-primary btn-sm" onclick="addFTP()">+ Add FTP Account</button>
  </div>
  <div class="card"><div id="ftp-list"><div class="loading">Loading…</div></div></div>`;
  loadFTPList();
}

async function loadFTPList() {
  const el = document.getElementById('ftp-list');
  if (!el) return;
  const res = await Nova.api('ftp', 'list');
  if (!res?.success || !res.data.length) { el.innerHTML = '<div class="empty">No FTP accounts yet.</div>'; return; }
  el.innerHTML = `<table class="table"><thead><tr><th>Username</th><th>Directory</th><th>Quota</th><th>Actions</th></tr></thead><tbody>
    ${res.data.map(f => `<tr>
      <td>${f.username}</td>
      <td><small>${f.home_dir}</small></td>
      <td>${f.quota_mb > 0 ? f.quota_mb+'MB' : 'Unlimited'}</td>
      <td style="display:flex;gap:.25rem">
        <button class="btn btn-xs" onclick="changeFTPPass(${f.id})">Passwd</button>
        <button class="btn btn-xs btn-danger" onclick="deleteFTP(${f.id},'${f.username}')">Del</button>
      </td>
    </tr>`).join('')}
  </tbody></table>`;
}
window.loadFTPList = loadFTPList;

window.addFTP = () => {
  Nova.modal('Add FTP Account', `
    <div class="form-group"><label class="form-label">Username</label><input id="ftp-user" class="form-control"></div>
    <div class="form-group"><label class="form-label">Password</label><input id="ftp-pass" type="password" class="form-control"></div>
    <div class="form-group"><label class="form-label">Directory (leave blank for public_html)</label><input id="ftp-dir" class="form-control" placeholder="/public_html"></div>`,
    `<button class="btn btn-primary" onclick="submitAddFTP()">Create</button>`);
};
window.submitAddFTP = async () => {
  const res = await Nova.api('ftp', 'create', { method:'POST', body:{ username: document.getElementById('ftp-user')?.value, password: document.getElementById('ftp-pass')?.value, home_dir: document.getElementById('ftp-dir')?.value || null }});
  if (res?.success) { Nova.toast('FTP account created','success'); document.querySelector('.modal-overlay')?.remove(); loadFTPList(); }
  else Nova.toast(res?.message||'Failed','error');
};
window.changeFTPPass = (id) => {
  Nova.modal('Change FTP Password', `<div class="form-group"><label class="form-label">New Password</label><input id="ftpp" type="password" class="form-control"></div>`,
    `<button class="btn btn-primary" onclick="Nova.api('ftp','change-password',{method:'POST',body:{id:${id},password:document.getElementById('ftpp').value}}).then(r=>{ if(r?.success){Nova.toast('Updated','success');document.querySelector('.modal-overlay').remove();}else Nova.toast(r?.message,'error'); })">Update</button>`);
};
window.deleteFTP = (id, user) => {
  Nova.confirm(`Delete FTP account ${user}?`, async () => {
    const res = await Nova.api('ftp', 'delete', { method:'POST', body:{id}});
    if (res?.success) { Nova.toast('Deleted','success'); loadFTPList(); }
  }, true);
};

/* ── SSL ────────────────────────────────────────────────────────────────── */
async function ssl(el) {
  el.innerHTML = `<div class="page-header">
    <h2 class="page-title">SSL / TLS Certificates</h2>
    <button class="btn btn-primary btn-sm" onclick="issueNewSSL()">+ Issue Let's Encrypt SSL</button>
  </div>
  <div class="card"><div id="ssl-list"><div class="loading">Loading…</div></div></div>`;
  loadSSLList();
}

async function loadSSLList() {
  const el = document.getElementById('ssl-list');
  if (!el) return;
  const res = await Nova.api('ssl', 'list');
  if (!res?.success || !res.data.length) { el.innerHTML = '<div class="empty">No SSL certificates yet.</div>'; return; }
  el.innerHTML = `<table class="table"><thead><tr><th>Domain</th><th>Type</th><th>Expires</th><th>Status</th><th>Actions</th></tr></thead><tbody>
    ${res.data.map(c => {
      const days = c.days_remaining;
      const status = !days ? 'unknown' : days < 7 ? 'critical' : days < 30 ? 'warning' : 'ok';
      const badge = days !== null ? `${days}d` : c.status;
      const badgeType = status === 'critical' ? 'red' : status === 'warning' ? 'yellow' : 'green';
      return `<tr>
        <td>${c.domain}</td>
        <td>${Nova.badge(c.type,'default')}</td>
        <td>${c.expires_at || '—'}</td>
        <td>${Nova.badge(badge, badgeType)}</td>
        <td style="display:flex;gap:.25rem">
          <button class="btn btn-xs" onclick="renewCert(${c.id})">Renew</button>
          <button class="btn btn-xs btn-danger" onclick="deleteCert(${c.id},'${c.domain}')">Del</button>
        </td>
      </tr>`;
    }).join('')}
  </tbody></table>`;
}
window.loadSSLList = loadSSLList;

window.issueNewSSL = () => {
  Nova.api('domains','list').then(res => {
    const opts = (res?.data || []).map(d => `<option value="${d.domain}">${d.domain}</option>`).join('');
    Nova.modal("Issue Let's Encrypt SSL", `
      <div class="form-group"><label class="form-label">Domain</label><select id="ssl-dom" class="form-control">${opts}</select></div>
      <div class="form-group"><label class="form-label">Contact Email</label><input id="ssl-email" type="email" class="form-control" placeholder="admin@yourdomain.com"></div>`,
      `<button class="btn btn-primary" onclick="submitIssueSSL()">Issue SSL</button>`);
  });
};
window.submitIssueSSL = () => {
  const domain = document.getElementById('ssl-dom')?.value;
  const email  = document.getElementById('ssl-email')?.value;
  document.querySelector('.modal-overlay')?.remove();
  _sslStream({ domain, email }, () => loadSSLList());
};
window.renewCert = async (id) => {
  Nova.toast('Renewing…','info');
  const res = await Nova.api('ssl', 'renew', { method:'POST', body:{cert_id:id}});
  if (res?.success) { Nova.toast('Renewed','success'); loadSSLList(); }
  else Nova.toast(res?.message,'error');
};
window.deleteCert = (id, domain) => {
  Nova.confirm(`Remove SSL cert for ${domain}?`, async () => {
    const res = await Nova.api('ssl', 'delete', { method:'POST', body:{cert_id:id}});
    if (res?.success) { Nova.toast('Removed','success'); loadSSLList(); }
  }, true);
};

/* ── PHP Manager ────────────────────────────────────────────────────────── */
async function phpPage(el) {
  el.innerHTML = `<div class="page-header"><h2 class="page-title">PHP Configuration</h2></div>
  <div style="display:grid;grid-template-columns:1fr 1fr;gap:1.5rem">
    <div class="card">
      <div class="card-header"><span class="card-title">PHP Version</span></div>
      <div id="php-versions" style="padding:1.25rem"><div class="loading">Loading…</div></div>
    </div>
    <div class="card">
      <div class="card-header"><span class="card-title">PHP Settings</span></div>
      <div id="php-settings" style="padding:1.25rem"><div class="loading">Loading…</div></div>
    </div>
  </div>`;

  const [versRes, cfgRes] = await Promise.all([
    Nova.api('php', 'versions'),
    Nova.api('php', 'config'),
  ]);

  if (versRes?.success) {
    document.getElementById('php-versions').innerHTML = (versRes.data?.versions || []).map(v => `
      <div style="display:flex;align-items:center;justify-content:space-between;padding:.75rem 0;border-bottom:1px solid var(--border)">
        <div>
          <strong>PHP ${v.version}</strong>
          ${v.is_default ? Nova.badge('default','primary') : ''}
          ${!v.installed ? Nova.badge('not installed','muted') : ''}
        </div>
        ${v.installed ? `<button class="btn btn-sm ${cfgRes?.data?.php_version === v.version ? 'btn-primary' : ''}" onclick="switchPHP('${v.version}')">
          ${cfgRes?.data?.php_version === v.version ? 'Active' : 'Use'}
        </button>` : ''}
      </div>`).join('');
  }

  if (cfgRes?.success) {
    const c = cfgRes.data;
    document.getElementById('php-settings').innerHTML = `
      <div class="form-group"><label class="form-label">Memory Limit</label><input id="php-mem" class="form-control" value="${c.memory_limit}"></div>
      <div class="form-group"><label class="form-label">Max Execution Time (s)</label><input id="php-exec" type="number" class="form-control" value="${c.max_execution_time}"></div>
      <div class="form-group"><label class="form-label">Upload Max Filesize</label><input id="php-upload" class="form-control" value="${c.upload_max_filesize}"></div>
      <div class="form-group"><label class="form-label">Post Max Size</label><input id="php-post" class="form-control" value="${c.post_max_size}"></div>
      <button class="btn btn-primary" onclick="savePHPSettings()">Save Settings</button>`;
  }
}

window.switchPHP = async (ver) => {
  Nova.loading(`Switching to PHP ${ver}…`);
  const res = await Nova.api('php', 'switch-version', { method:'POST', body:{ version: ver }});
  Nova.loadingDone();
  if (res?.success) { Nova.toast(`Switched to PHP ${ver}`,'success'); phpPage(document.getElementById('page-content')); }
  else Nova.toast(res?.message,'error');
};
window.savePHPSettings = async () => {
  Nova.loading('Saving PHP settings…');
  const res = await Nova.api('php', 'update-config', { method:'POST', body:{
    memory_limit: document.getElementById('php-mem')?.value,
    max_execution_time: document.getElementById('php-exec')?.value,
    upload_max_filesize: document.getElementById('php-upload')?.value,
    post_max_size: document.getElementById('php-post')?.value,
  }});
  Nova.loadingDone();
  if (res?.success) Nova.toast('PHP settings saved','success');
  else Nova.toast(res?.message,'error');
};

/* ── Cron Jobs ──────────────────────────────────────────────────────────── */
async function cron(el) {
  el.innerHTML = `<div class="page-header">
    <h2 class="page-title">Cron Jobs</h2>
    <button class="btn btn-primary btn-sm" onclick="addCron()">+ Add Cron Job</button>
  </div>
  <div class="card"><div id="cron-list"><div class="loading">Loading…</div></div></div>`;
  loadCronList();
}

async function loadCronList() {
  const el = document.getElementById('cron-list');
  if (!el) return;
  const res = await Nova.api('cron', 'list');
  if (!res?.success || !res.data.length) { el.innerHTML = '<div class="empty">No cron jobs yet.</div>'; return; }
  el.innerHTML = `<table class="table"><thead><tr><th>Schedule</th><th>Command</th><th>Status</th><th>Actions</th></tr></thead><tbody>
    ${res.data.map(j => `<tr>
      <td><code>${j.minute} ${j.hour} ${j.day} ${j.month} ${j.weekday}</code></td>
      <td><small>${j.command}</small></td>
      <td>
        <label class="toggle-switch" title="${j.is_active ? 'Active' : 'Disabled'}">
          <input type="checkbox" ${j.is_active ? 'checked' : ''} onchange="toggleCron(${j.id})">
          <span class="toggle-slider"></span>
        </label>
      </td>
      <td style="display:flex;gap:.25rem">
        <button class="btn btn-xs btn-danger" onclick="deleteCron(${j.id})">Del</button>
      </td>
    </tr>`).join('')}
  </tbody></table>`;
}
window.loadCronList = loadCronList;

window.addCron = () => {
  Nova.modal('Add Cron Job', `
    <div class="form-group"><label class="form-label">Command</label><input id="cr-cmd" class="form-control" placeholder="/usr/bin/php /home/user/public_html/cron.php"></div>
    <div style="display:grid;grid-template-columns:repeat(5,1fr);gap:.5rem;margin-top:.75rem">
      ${['minute','hour','day','month','weekday'].map(f => `<div class="form-group"><label class="form-label" style="font-size:.75rem">${f.charAt(0).toUpperCase()+f.slice(1)}</label><input id="cr-${f}" class="form-control" value="*"></div>`).join('')}
    </div>
    <div style="color:var(--muted);font-size:.8rem">* = every | */5 = every 5 | 0 = midnight/Jan/Mon</div>`,
    `<button class="btn btn-primary" onclick="submitCron()">Add</button>`);
};
window.submitCron = async () => {
  const res = await Nova.api('cron', 'create', { method:'POST', body:{
    command: document.getElementById('cr-cmd')?.value,
    minute:  document.getElementById('cr-minute')?.value || '*',
    hour:    document.getElementById('cr-hour')?.value   || '*',
    day:     document.getElementById('cr-day')?.value    || '*',
    month:   document.getElementById('cr-month')?.value  || '*',
    weekday: document.getElementById('cr-weekday')?.value|| '*',
  }});
  if (res?.success) { Nova.toast('Cron job added','success'); document.querySelector('.modal-overlay')?.remove(); loadCronList(); }
  else Nova.toast(res?.message,'error');
};
window.toggleCron = async (id) => {
  await Nova.api('cron', 'toggle', { method:'POST', body:{id}});
  loadCronList();
};
window.deleteCron = (id) => {
  Nova.confirm('Delete this cron job?', async () => {
    const res = await Nova.api('cron', 'delete', { method:'POST', body:{id}});
    if (res?.success) { Nova.toast('Deleted','success'); loadCronList(); }
  }, true);
};

/* ── File Manager ───────────────────────────────────────────────────────── */
let _fmPath = '/public_html';

async function files(el) {
  el.innerHTML = `<div class="page-header">
    <h2 class="page-title">File Manager</h2>
    <div style="display:flex;gap:.5rem">
      <button class="btn btn-sm" onclick="fmMkdir()">+ Folder</button>
      <button class="btn btn-sm" onclick="fmUpload()">↑ Upload</button>
    </div>
  </div>
  <div class="card">
    <div style="padding:.75rem 1rem;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:.5rem">
      <button class="btn btn-xs" onclick="fmNav('/')">Home</button>
      <span id="fm-path" style="font-family:monospace;font-size:.85rem;color:var(--muted)">${_fmPath}</span>
    </div>
    <div id="fm-list"><div class="loading">Loading…</div></div>
  </div>
  <div id="fm-editor" style="display:none;margin-top:1rem"></div>`;

  loadFMList(_fmPath);
}

async function loadFMList(path) {
  _fmPath = path;
  const pathEl = document.getElementById('fm-path');
  if (pathEl) pathEl.textContent = path;
  const el = document.getElementById('fm-list');
  if (!el) return;
  const res = await Nova.api('files', 'list', { params: { path }});
  if (!res?.success) { el.innerHTML = `<div class="empty">${res?.message || 'Error loading directory'}</div>`; return; }

  const parentPath = path.includes('/') ? path.replace(/\/[^/]+$/, '') || '/' : '/';
  el.innerHTML = `<table class="table"><thead><tr><th>Name</th><th>Size</th><th>Perms</th><th>Modified</th><th>Actions</th></tr></thead><tbody>
    ${path !== '/' && path !== '/public_html' ? `<tr><td colspan="5"><a href="#" onclick="fmNav('${parentPath}')" style="color:var(--primary)">← ..</a></td></tr>` : ''}
    ${res.data.items.map(f => `<tr>
      <td>
        ${f.type === 'dir'
          ? `<a href="#" onclick="fmNav('${f.path}')" style="color:var(--sky)">📁 ${f.name}</a>`
          : `<span>📄 ${f.name}</span>`}
      </td>
      <td>${f.size || '—'}</td>
      <td><code style="font-size:.8rem">${f.perms}</code></td>
      <td style="font-size:.8rem">${f.modified}</td>
      <td style="display:flex;gap:.2rem">
        ${f.type === 'file' ? `<button class="btn btn-xs" onclick="fmEdit('${f.path}','${f.name}')">Edit</button>` : ''}
        <button class="btn btn-xs" onclick="fmRename('${f.path}','${f.name}')">Ren</button>
        <button class="btn btn-xs" onclick="fmChmod('${f.path}','${f.perms}')">Perm</button>
        <button class="btn btn-xs btn-danger" onclick="fmDelete('${f.path}','${f.name}')">Del</button>
      </td>
    </tr>`).join('')}
  </tbody></table>`;
}
window.fmNav = (p) => loadFMList(p);

window.fmEdit = async (path, name) => {
  const res = await Nova.api('files', 'read', { params: { path }});
  if (!res?.success) { Nova.toast(res?.message || 'Cannot read file','error'); return; }
  const edEl = document.getElementById('fm-editor');
  edEl.style.display = 'block';
  edEl.innerHTML = `<div class="card">
    <div class="card-header"><span class="card-title">Editing: ${name}</span>
      <div style="display:flex;gap:.5rem">
        <button class="btn btn-sm btn-primary" onclick="fmSave('${path}')">Save</button>
        <button class="btn btn-sm" onclick="document.getElementById('fm-editor').style.display='none'">Close</button>
      </div>
    </div>
    <textarea id="fm-code" style="width:100%;min-height:400px;font-family:monospace;font-size:.85rem;padding:1rem;background:var(--bg);color:var(--text);border:none;resize:vertical">${res.data.content.replace(/</g,'&lt;')}</textarea>
  </div>`;
};
window.fmSave = async (path) => {
  const content = document.getElementById('fm-code')?.value || '';
  const res = await Nova.api('files', 'write', { method:'POST', body:{ path, content }});
  if (res?.success) Nova.toast('Saved','success');
  else Nova.toast(res?.message || 'Save failed','error');
};
window.fmDelete = (path, name) => {
  Nova.confirm(`Delete ${name}?`, async () => {
    const res = await Nova.api('files', 'delete', { method:'POST', body:{ path }});
    if (res?.success) { Nova.toast('Deleted','success'); loadFMList(_fmPath); }
    else Nova.toast(res?.message,'error');
  }, true);
};
window.fmMkdir = () => {
  Nova.modal('New Folder', `<div class="form-group"><label class="form-label">Folder Name</label><input id="fm-dname" class="form-control"></div>`,
    `<button class="btn btn-primary" onclick="Nova.api('files','mkdir',{method:'POST',body:{path:'${_fmPath}/'+document.getElementById('fm-dname').value}}).then(r=>{if(r?.success){Nova.toast('Created','success');document.querySelector('.modal-overlay').remove();loadFMList('${_fmPath}');}else Nova.toast(r?.message,'error');})">Create</button>`);
};
window.fmRename = (path, name) => {
  const dir = path.replace(/\/[^/]+$/, '');
  Nova.modal('Rename', `<div class="form-group"><label class="form-label">New Name</label><input id="fm-newname" class="form-control" value="${name}"></div>`,
    `<button class="btn btn-primary" onclick="Nova.api('files','rename',{method:'POST',body:{from:'${path}',to:'${dir}/'+document.getElementById('fm-newname').value}}).then(r=>{if(r?.success){Nova.toast('Renamed','success');document.querySelector('.modal-overlay').remove();loadFMList('${_fmPath}');}else Nova.toast(r?.message,'error');})">Rename</button>`);
};
window.fmChmod = (path, current) => {
  Nova.modal('Change Permissions', `<div class="form-group"><label class="form-label">Permissions (octal)</label><input id="fm-perms" class="form-control" value="${current}" maxlength="4"></div>`,
    `<button class="btn btn-primary" onclick="Nova.api('files','chmod',{method:'POST',body:{path:'${path}',perms:document.getElementById('fm-perms').value}}).then(r=>{if(r?.success){Nova.toast('Updated','success');document.querySelector('.modal-overlay').remove();loadFMList('${_fmPath}');}else Nova.toast(r?.message,'error');})">Update</button>`);
};
window.fmUpload = () => {
  Nova.modal('Upload File', `
    <div class="form-group"><label class="form-label">Select File</label><input id="fm-upfile" type="file" class="form-control"></div>`,
    `<button class="btn btn-primary" onclick="submitFMUpload()">Upload</button>`);
};
window.submitFMUpload = async () => {
  const fileInput = document.getElementById('fm-upfile');
  if (!fileInput?.files[0]) return;
  const fd = new FormData();
  fd.append('file', fileInput.files[0]);
  fd.append('path', _fmPath);
  const res = await fetch(`/api/files/upload?path=${encodeURIComponent(_fmPath)}`, { method:'POST', credentials:'include', body: fd }).then(r => r.json());
  if (res?.success) { Nova.toast('Uploaded','success'); document.querySelector('.modal-overlay')?.remove(); loadFMList(_fmPath); }
  else Nova.toast(res?.message || 'Upload failed','error');
};

/* ── Stats ──────────────────────────────────────────────────────────────── */
async function statsPage(el) {
  el.innerHTML = `<div class="page-header"><h2 class="page-title">Usage Statistics</h2></div>
  <div id="stats-grid" class="stats-grid"><div class="loading">Loading…</div></div>`;

  const res = await Nova.api('stats', 'account');
  if (!res?.success) return;
  const d = res.data;
  document.getElementById('stats-grid').innerHTML = [
    { label: 'Disk Used', val: d.disk_mb + ' MB', limit: d.disk_limit > 0 ? `/ ${d.disk_limit} MB` : '', pct: d.disk_limit > 0 ? Math.min(100,(d.disk_mb/d.disk_limit*100)) : 0 },
    { label: 'Databases', val: d.databases, limit: d.db_limit > 0 ? `/ ${d.db_limit}` : '', pct: d.db_limit > 0 ? Math.min(100,d.databases/d.db_limit*100) : 0 },
    { label: 'Email Accounts', val: d.emails, limit: d.email_limit > 0 ? `/ ${d.email_limit}` : '', pct: d.email_limit > 0 ? Math.min(100,d.emails/d.email_limit*100) : 0 },
    { label: 'FTP Accounts', val: d.ftp, limit: d.ftp_limit > 0 ? `/ ${d.ftp_limit}` : '', pct: d.ftp_limit > 0 ? Math.min(100,d.ftp/d.ftp_limit*100) : 0 },
    { label: 'Domains', val: d.domains, limit: '', pct: 0 },
    { label: 'Inodes', val: d.inodes.toLocaleString(), limit: '', pct: 0 },
  ].map(item => `<div class="stat-card">
    <div class="stat-label">${item.label}</div>
    <div class="stat-value">${item.val} <span style="font-size:.75rem;color:var(--muted)">${item.limit}</span></div>
    ${item.pct > 0 ? `<div style="margin-top:.5rem">${Nova.progressBar(Math.round(item.pct))}</div>` : ''}
  </div>`).join('');
}

/* ── Backups ────────────────────────────────────────────────────────────── */
async function backups(el) {
  el.innerHTML = `
<div class="page-header">
  <h2 class="page-title">Backups</h2>
  <button class="btn btn-primary btn-sm" onclick="createBackup()">+ Create Backup</button>
</div>
<div class="card">
  <div id="backup-list"><div style="padding:2rem;text-align:center;color:var(--text-muted)">Loading…</div></div>
</div>`;
  await loadBackupList();
}

async function loadBackupList() {
  const el = document.getElementById('backup-list');
  if (!el) return;
  const res = await Nova.api('backup', 'list');
  const list = res?.data?.backups || [];
  if (!list.length) {
    el.innerHTML = `<div style="padding:2.5rem;text-align:center;color:var(--text-muted)">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" width="48" height="48" style="opacity:.35;margin-bottom:.75rem">
        <polyline points="23 4 23 10 17 10"/><polyline points="1 20 1 14 7 14"/>
        <path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/>
      </svg>
      <div style="margin-bottom:.25rem">No backups yet.</div>
      <div style="font-size:.82rem">Click <strong>+ Create Backup</strong> to create your first backup.</div>
    </div>`;
    return;
  }
  el.innerHTML = `<div style="overflow-x:auto"><table class="table">
    <thead><tr><th>Date</th><th>Type</th><th>Size</th><th>Status</th><th>Actions</th></tr></thead>
    <tbody>
      ${list.map(b => `<tr>
        <td>${Nova.relTime(b.created_at)}</td>
        <td>${Nova.badge(b.type, 'blue')}</td>
        <td>${b.size ? Nova.bytes(parseInt(b.size)) : '—'}</td>
        <td>${Nova.badge(b.status, b.status==='complete'?'green':b.status==='running'?'yellow':'red')}</td>
        <td>
          ${b.status === 'complete'
            ? `<a href="/api/?endpoint=backup&action=download&id=${b.id}" class="btn btn-xs btn-ghost">Download</a>`
            : ''}
        </td>
      </tr>`).join('')}
    </tbody>
  </table></div>`;
}

window.createBackup = () => {
  Nova.modal('Create Backup',
    `<div class="form-group">
      <label class="form-label">Backup Type</label>
      <select id="bk-type" class="form-control">
        <option value="full">Full backup — files + all databases</option>
        <option value="files">Files only</option>
        <option value="database">Databases only</option>
      </select>
    </div>
    <p style="font-size:.82rem;color:var(--text-muted);margin-top:.5rem">Backups run on the server and may take a few minutes for large accounts.</p>`,
    `<button class="btn btn-ghost" onclick="this.closest('.modal-overlay').remove()">Cancel</button>
     <button class="btn btn-primary" onclick="submitCreateBackup()">Create Backup</button>`
  );
};

window.submitCreateBackup = async () => {
  const type = document.getElementById('bk-type')?.value || 'full';
  document.querySelector('.modal-overlay')?.remove();
  Nova.loading('Creating backup… this may take a few minutes');
  const res = await Nova.api('backup', 'create', { method: 'POST', body: { type } });
  Nova.loadingDone();
  if (res?.success) {
    Nova.toast('Backup created successfully', 'success');
    loadBackupList();
  } else {
    Nova.toast(res?.message || 'Backup failed', 'error');
  }
};

/* ── Navigation ─────────────────────────────────────────────────────────── */
const navGroups = [
  { label: 'Overview', items: [
    { id: 'dashboard', label: 'Dashboard',
      svg: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/></svg>' },
  ]},
  { label: 'Hosting', items: [
    { id: 'domains', label: 'Domains',
      svg: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/></svg>' },
    { id: 'email', label: 'Email',
      svg: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>' },
    { id: 'databases', label: 'Databases',
      svg: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><ellipse cx="12" cy="5" rx="9" ry="3"/><path d="M21 12c0 1.66-4 3-9 3s-9-1.34-9-3"/><path d="M3 5v14c0 1.66 4 3 9 3s9-1.34 9-3V5"/></svg>' },
    { id: 'ftp', label: 'FTP',
      svg: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/></svg>' },
    { id: 'ssl', label: 'SSL / TLS',
      svg: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>' },
  ]},
  { label: 'Management', items: [
    { id: 'php', label: 'PHP',
      svg: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="16 18 22 12 16 6"/><polyline points="8 6 2 12 8 18"/></svg>' },
    { id: 'cron', label: 'Cron Jobs',
      svg: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>' },
    { id: 'files', label: 'File Manager',
      svg: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M13 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V9z"/><polyline points="13 2 13 9 20 9"/></svg>' },
    { id: 'stats', label: 'Statistics',
      svg: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg>' },
  ]},
  { label: 'Tools', items: [
    { id: 'backups', label: 'Backups',
      svg: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="23 4 23 10 17 10"/><polyline points="1 20 1 14 7 14"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/></svg>' },
    { id: 'docker', label: 'Docker',
      svg: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="9" width="4" height="4"/><rect x="7" y="9" width="4" height="4"/><rect x="12" y="9" width="4" height="4"/><rect x="7" y="4" width="4" height="4"/><path d="M22 11c0 5-3.9 9-10 9-8 0-10-7-10-7"/></svg>' },
  ]},
  { label: 'Account', items: [
    { id: 'change-password', label: 'Change Password',
      svg: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>' },
  ]},
];

let _activePage = 'dashboard';

function renderNav() {
  const nav = document.getElementById('sidebar-nav');
  if (!nav) return;
  nav.innerHTML = navGroups.map(g => `
    <div class="sidebar-section">
      <div class="sidebar-section-label">${g.label}</div>
      ${g.items.map(n => `
        <a href="#" class="sidebar-link${n.id === _activePage ? ' active' : ''}" data-page="${n.id}">
          ${n.svg}
          ${n.label}
        </a>`).join('')}
    </div>`).join('');

  nav.querySelectorAll('[data-page]').forEach(link => {
    link.addEventListener('click', e => {
      e.preventDefault();
      if (window.innerWidth <= 768) {
        document.getElementById('sidebar')?.classList.remove('open');
        document.getElementById('sidebar-overlay')?.classList.remove('open');
        document.body.style.overflow = '';
      }
      userNav(link.dataset.page);
    });
  });
}

window.userNav = (page) => {
  _activePage = page;
  renderNav();
  const allItems = navGroups.flatMap(g => g.items);
  const item = allItems.find(n => n.id === page);
  const titleEl = document.getElementById('page-title');
  if (titleEl && item) titleEl.textContent = item.label;
  const content = document.getElementById('page-content');
  if (!content) return;
  content.innerHTML = '<div style="padding:2rem;color:var(--text-muted);text-align:center">Loading…</div>';
  if (userPages[page]) userPages[page](content);
};

/* ── Change Password ─────────────────────────────────────────────────────── */
async function changePasswordPage(el) {
  el.innerHTML = `
<div class="page-header"><h2 class="page-title">Change Password</h2></div>
<div class="card" style="max-width:480px">
  <div class="card-header"><span class="card-title">Update Your Password</span></div>
  <div class="card-body">
    <div class="form-group">
      <label class="form-label">Current Password</label>
      <input type="password" id="cp-current" class="form-control" autocomplete="current-password">
    </div>
    <div class="form-group">
      <label class="form-label">New Password <span style="color:var(--muted);font-size:.8rem">(min 8 chars)</span></label>
      <input type="password" id="cp-new" class="form-control" autocomplete="new-password">
    </div>
    <div class="form-group">
      <label class="form-label">Confirm New Password</label>
      <input type="password" id="cp-confirm" class="form-control" autocomplete="new-password">
    </div>
    <button class="btn btn-primary" onclick="submitChangePassword()">Update Password</button>
  </div>
</div>`;
}

window.submitChangePassword = async () => {
  const current = document.getElementById('cp-current')?.value;
  const newPass  = document.getElementById('cp-new')?.value;
  const confirm  = document.getElementById('cp-confirm')?.value;
  if (!current || !newPass || !confirm) { Nova.toast('All fields required', 'error'); return; }
  if (newPass !== confirm) { Nova.toast('New passwords do not match', 'error'); return; }
  const res = await Nova.api('auth', 'change-password', {
    method: 'POST',
    body: { current_password: current, new_password: newPass, confirm_password: confirm },
  });
  if (res?.success) {
    Nova.toast('Password updated successfully', 'success');
    document.getElementById('cp-current').value = '';
    document.getElementById('cp-new').value = '';
    document.getElementById('cp-confirm').value = '';
  } else {
    Nova.toast(res?.message || 'Failed to update password', 'error');
  }
};

/* ── Docker (#34) ────────────────────────────────────────────────────────── */
async function dockerPage(el) {
  el.innerHTML = '<div class="loading">Loading Docker…</div>';
  const [stackRes, quotaRes, catRes] = await Promise.all([
    Nova.api('docker', 'stacks'),
    Nova.api('docker', 'quota-get'),
    Nova.api('docker', 'catalog'),
  ]);

  const stacks  = stackRes?.data?.stacks || [];
  const quota   = quotaRes?.data?.quota || { max_containers: 2, max_memory_mb: 512, max_cpus: 1.0 };
  const catalog = catRes?.data?.catalog || {};

  el.innerHTML = `
<div class="page-header"><h2 class="page-title">Docker Apps</h2></div>
<div class="stats-grid" style="margin-bottom:1.5rem">
  <div class="stat-card"><div class="stat-label">Apps Deployed</div><div class="stat-value">${stacks.length} / ${quota.max_containers}</div><div class="mt-1">${Nova.progressBar(Math.round(stacks.length/Math.max(quota.max_containers,1)*100))}</div></div>
  <div class="stat-card"><div class="stat-label">Max Memory / App</div><div class="stat-value stat-blue">${quota.max_memory_mb} MB</div></div>
  <div class="stat-card"><div class="stat-label">Max CPUs / App</div><div class="stat-value stat-green">${quota.max_cpus}</div></div>
</div>

<div style="display:flex;gap:.5rem;margin-bottom:1rem;flex-wrap:wrap">
  <button class="btn btn-sm ${_uDockerTab==='my-apps'?'btn-primary':'btn-ghost'}" onclick="uDockerTab('my-apps')">My Apps</button>
  <button class="btn btn-sm ${_uDockerTab==='catalog'?'btn-primary':'btn-ghost'}" onclick="uDockerTab('catalog')">App Catalog</button>
  <button class="btn btn-sm btn-danger" style="margin-left:auto" onclick="uDockerUninstallAll()">Remove All My Apps</button>
</div>
<div id="udocker-content"><div class="loading">Loading…</div></div>`;

  window._uDockerStacks  = stacks;
  window._uDockerQuota   = quota;
  window._uDockerCatalog = catalog;
  window._uDockerTab     = window._uDockerTab || 'my-apps';

  window.uDockerTab = async (tab) => {
    window._uDockerTab = tab;
    document.querySelectorAll('[onclick^="uDockerTab"]').forEach(b => {
      const t = b.getAttribute('onclick').match(/'([^']+)'/)?.[1];
      b.className = 'btn btn-sm ' + (t === tab ? 'btn-primary' : 'btn-ghost');
    });
    if (tab === 'my-apps') await uDockerReloadStacks();
    else uDockerLoadTab(tab);
  };

  if (window._uDockerTab === 'my-apps') await uDockerReloadStacks();
  else uDockerLoadTab(window._uDockerTab);
}

window._uDockerTab = 'my-apps';

window.uDockerUninstallAll = () => Nova.confirm(
  'Remove ALL your Docker apps? This will stop and delete every container and stack you own. Your hosting account and websites are not affected.',
  async () => {
    Nova.loading('Removing all your Docker apps…');
    const r = await Nova.api('docker', 'uninstall-account', { method: 'POST', body: {} });
    Nova.loadingDone();
    Nova.toast(r?.success ? 'All Docker apps removed' : (r?.error || r?.message || 'Failed'), r?.success ? 'success' : 'error');
    if (r?.success) await uDockerReloadStacks();
  },
  true
);

async function uDockerReloadStacks() {
  const r = await Nova.api('docker', 'stacks');
  window._uDockerStacks = r?.data?.stacks || [];
  uDockerLoadTab('my-apps');
}

function uDockerLoadTab(tab) {
  const tc = document.getElementById('udocker-content');
  if (!tc) return;
  const stacks  = window._uDockerStacks  || [];
  const catalog = window._uDockerCatalog || {};
  const quota   = window._uDockerQuota   || {};

  if (tab === 'my-apps') {
    const statusColor = s => s==='running'?'green':s==='starting'?'yellow':s==='stopped'?'red':'yellow';
    tc.innerHTML = `
<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1rem">
  <strong>${stacks.length} app${stacks.length===1?'':'s'}</strong>
  <div style="display:flex;gap:.5rem">
    <button class="btn btn-sm btn-ghost" onclick="uDockerReloadStacks()">↻ Refresh</button>
    <button class="btn btn-sm btn-primary" onclick="uDockerLaunchModal()" ${stacks.length>=quota.max_containers?'disabled title="Quota reached"':''}>+ Launch App</button>
  </div>
</div>
${stacks.length === 0
  ? `<div class="card"><div class="card-body" style="text-align:center;padding:3rem">
      <div style="font-size:2.5rem;margin-bottom:1rem">🐳</div>
      <p class="text-muted">No apps yet. Launch one from the catalog!</p>
      <button class="btn btn-primary" onclick="uDockerTab('catalog')">Browse Catalog</button>
    </div></div>`
  : `<div style="overflow-x:auto"><table class="table"><thead><tr><th>App</th><th>Status</th><th>Created</th><th>Actions</th></tr></thead><tbody>
${stacks.map(s=>`<tr>
  <td style="font-weight:600">${Nova.escHtml(s.name)}</td>
  <td>${Nova.badge(s.status, statusColor(s.status))}</td>
  <td class="text-muted text-sm">${Nova.relTime(s.created_at)}</td>
  <td style="white-space:nowrap">
    ${s.status==='running'
      ? `<button class="btn btn-xs btn-warning" onclick="uStackAct(${s.id},'down')">Stop</button>`
      : `<button class="btn btn-xs btn-success" onclick="uStackAct(${s.id},'up')">Start</button>`}
    <button class="btn btn-xs btn-ghost" onclick="uStackLogs(${s.id},'${Nova.escHtml(s.name)}')">Logs</button>
    <button class="btn btn-xs btn-secondary" onclick="uStackReinstall(${s.id},'${Nova.escHtml(s.name)}')">Reinstall</button>
    <button class="btn btn-xs btn-danger" onclick="uStackRemove(${s.id},'${Nova.escHtml(s.name)}')">Remove</button>
  </td>
</tr>`).join('')}
</tbody></table></div>`}`;

  } else if (tab === 'catalog') {
    tc.innerHTML = `
<p class="text-muted" style="margin-bottom:1rem">One-click app deployment. Each app runs as an isolated Docker container.</p>
<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:1rem">
${Object.entries(catalog).map(([key,app])=>`
<div class="card" style="cursor:pointer;transition:var(--transition)" onmouseover="this.style.borderColor='var(--primary)'" onmouseout="this.style.borderColor=''">
  <div class="card-body" style="text-align:center;padding:1.5rem">
    <div style="font-size:1.8rem;font-weight:700;margin-bottom:.5rem;color:var(--primary)">${Nova.escHtml(app.icon)}</div>
    <div style="font-weight:600;margin-bottom:.25rem">${Nova.escHtml(app.name)}</div>
    <div style="font-size:.78rem;color:var(--text-muted)">${Nova.escHtml(app.description)}</div>
    <button class="btn btn-sm btn-primary" style="margin-top:1rem" onclick="uDockerLaunchApp('${key}')">Launch</button>
  </div>
</div>`).join('')}
</div>`;
  }
}

window.uStackAct = async (stackId, action) => {
  const label = action === 'up' ? 'Starting' : 'Stopping';
  Nova.loading(`${label} app…`);
  const r = await Nova.api('docker', 'stack-action', { method: 'POST', body: { stack_id: stackId, action } });
  Nova.loadingDone();
  Nova.toast(r?.success ? `App ${action==='up'?'started':'stopped'}` : (r?.message||'Failed'), r?.success?'success':'error');
  if (r?.success) await uDockerReloadStacks();
};

window.uStackLogs = async (stackId, name) => {
  Nova.loading('Fetching logs…');
  const r = await Nova.api('docker', 'stack-action', { method: 'POST', body: { stack_id: stackId, action: 'logs' } });
  Nova.loadingDone();
  Nova.modal(`Logs: ${name}`, `<pre style="max-height:400px;overflow:auto;font-size:.78rem;white-space:pre-wrap">${Nova.escHtml(r?.data?.output||'No logs available')}</pre>`);
};

window.uStackReinstall = (stackId, name) => Nova.confirm(
  `Reinstall "${name}"? This will pull the latest images, restart all containers, and reset to a fresh state. Your data volumes will be preserved.`,
  async () => {
    Nova.loading(`Reinstalling ${name}…`);
    const r = await Nova.api('docker', 'stack-reinstall', { method: 'POST', body: { stack_id: stackId } });
    Nova.loadingDone();
    Nova.toast(r?.success ? `${name} reinstalled` : (r?.message || 'Reinstall failed'), r?.success ? 'success' : 'error');
    if (r?.success) await uDockerReloadStacks();
  },
  true
);

window.uStackRemove = async (stackId, name) => {
  if (!confirm(`Remove app "${name}"? This will stop and delete its containers and data.`)) return;
  Nova.loading('Removing app…');
  const r = await Nova.api('docker', 'stack-remove', { method: 'POST', body: { stack_id: stackId } });
  Nova.loadingDone();
  Nova.toast(r?.success ? 'App removed' : (r?.message||'Failed'), r?.success?'success':'error');
  if (r?.success) await uDockerReloadStacks();
};

window.uDockerLaunchModal = () => uDockerLaunchApp(null);

window.uDockerLaunchApp = async (preselect) => {
  const catalog = window._uDockerCatalog || {};
  const entries = Object.entries(catalog);
  const appOpts = entries.map(([k,a])=>`<option value="${k}" ${k===preselect?'selected':''}>${Nova.escHtml(a.name)}</option>`).join('');

  window.uDockerUpdateParams = (key) => {
    const app = catalog[key];
    if (!app) return;
    const tc = document.getElementById('ul-params');
    if (!tc) return;
    tc.innerHTML = (app.params||[]).map(p=>`
      <div class="form-group"><label>${Nova.escHtml(p.label)}${p.required?' *':''}</label>
      <input id="ul-${Nova.escHtml(p.key)}" type="${p.type||'text'}" class="form-control" ${p.placeholder?`placeholder="${Nova.escHtml(p.placeholder)}"`:''}></div>`).join('');
  };

  const ov = Nova.modal('Launch App',
    `<div class="form-group"><label>App</label>
     <select id="ul-app" class="form-control" onchange="uDockerUpdateParams(this.value)">${appOpts}</select></div>
     <div id="ul-params"></div>`,
    `<button class="btn btn-ghost" onclick="this.closest('.modal-overlay').remove()">Cancel</button>
     <button class="btn btn-primary" onclick="uDockerLaunchSubmit()">Launch</button>`
  );

  const initialKey = preselect || entries[0]?.[0];
  if (initialKey) uDockerUpdateParams(initialKey);

  window.uDockerLaunchSubmit = async () => {
    const key = document.getElementById('ul-app')?.value;
    const app = catalog[key];
    if (!app) return;
    const params = {};
    (app.params||[]).forEach(p => { params[p.key] = document.getElementById('ul-'+p.key)?.value||''; });
    const missing = (app.params||[]).filter(p=>p.required && !params[p.key]);
    if (missing.length) { Nova.toast(`Required: ${missing.map(p=>p.label).join(', ')}`, 'error'); return; }
    ov.remove();
    Nova.loading(`Launching ${app.name}… this may take a minute`);
    const r = await Nova.api('docker', 'launch', { method: 'POST', body: { app_key: key, params } });
    Nova.loadingDone();
    Nova.toast(r?.success ? `${app.name} launching — refresh in a moment to see status` : (r?.message||'Launch failed'), r?.success?'success':'error');
    if (r?.success) {
      await uDockerTab('my-apps');
    }
  };
};

/* ── Boot ────────────────────────────────────────────────────────────────── */
document.addEventListener('DOMContentLoaded', async () => {
  const ok = await initUser();
  if (!ok) return;
  document.getElementById('logout-btn')?.addEventListener('click', async e => {
    e.preventDefault();
    await Nova.api('auth', 'logout', { method: 'POST' });
    location.href = '/';
  });
  renderNav();
  window.userNav('dashboard');
});
