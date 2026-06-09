/**
 * NovaCPX Reseller Panel JS
 */

let _rUser = null;

async function initReseller() {
  const res = await Nova.api('auth', 'me');
  if (!res?.success || !['admin','reseller'].includes(res.data?.role)) {
    document.getElementById('auth-check').innerHTML = renderLogin();
    document.getElementById('main-layout').style.display = 'none';
    return false;
  }
  _rUser = res.data;
  document.getElementById('user-name').textContent = _rUser.username || 'Reseller';
  document.getElementById('auth-check').style.display = 'none';
  document.getElementById('main-layout').style.display = '';
  return true;
}

function renderLogin() {
  return `<div class="login-wrap">
    <div class="login-card">
      <div style="text-align:center;margin-bottom:2rem">
        <img src="/assets/img/nova-logo.svg" style="height:42px;margin-bottom:.5rem">
        <div style="color:var(--muted);font-size:.85rem">Reseller Portal · Port 8881</div>
      </div>
      <div class="form-group"><label class="form-label">Username</label><input id="li-user" type="text" class="form-control" autocomplete="username"></div>
      <div class="form-group"><label class="form-label">Password</label><input id="li-pass" type="password" class="form-control" autocomplete="current-password"></div>
      <button class="btn btn-primary" style="width:100%" onclick="doLogin()">Sign In</button>
      <div id="li-err" style="color:var(--red);margin-top:.75rem;text-align:center;display:none"></div>
    </div>
  </div>`;
}

async function doLogin() {
  Nova.loading('Signing in…');
  const res = await Nova.api('auth', 'login', { method: 'POST', body: { username: document.getElementById('li-user')?.value, password: document.getElementById('li-pass')?.value }});
  Nova.loadingDone();
  if (res?.success) {
    if (res.data?.portal_url && !res.data.portal_url.includes(':8881')) location.href = res.data.portal_url;
    else location.reload();
  } else {
    const err = document.getElementById('li-err');
    if (err) { err.textContent = res?.message || 'Login failed'; err.style.display = 'block'; }
  }
}
window.doLogin = doLogin;

/* ── Pages ─────────────────────────────────────────────────────────────── */

async function rDashboard(el) {
  el.innerHTML = `<div class="page-header"><h2 class="page-title">Reseller Dashboard</h2></div>
    <div id="r-stats" class="stats-grid"><div class="loading">Loading…</div></div>
    <div style="margin-top:1.5rem" class="card">
      <div class="card-header"><span class="card-title">Recent Accounts</span>
        <button class="btn btn-sm btn-primary" onclick="resellerNav('accounts')">View All</button>
      </div>
      <div id="r-recent"><div class="loading">Loading…</div></div>
    </div>`;

  const res = await Nova.api('accounts', 'list', { params:{ limit:5 }});
  const accts = res?.data || [];

  document.getElementById('r-stats').innerHTML = [
    { label: 'Total Accounts', val: res?.meta?.total || accts.length, icon: 'ni-accounts' },
    { label: 'Active', val: accts.filter(a=>a.status==='active').length, icon: 'ni-stats' },
    { label: 'Suspended', val: accts.filter(a=>a.status==='suspended').length, icon: 'ni-suspend' },
  ].map(s => `<div class="stat-card" style="display:flex;align-items:center;gap:1rem">
    <svg width="32" height="32" style="color:var(--primary);flex-shrink:0"><use href="/assets/img/nova-icons.svg#${s.icon}"/></svg>
    <div><div class="stat-value">${s.val}</div><div class="stat-label">${s.label}</div></div>
  </div>`).join('');

  document.getElementById('r-recent').innerHTML = accts.length
    ? `<table class="table"><thead><tr><th>Username</th><th>Domain</th><th>Package</th><th>Status</th></tr></thead><tbody>
        ${accts.map(a => `<tr>
          <td>${a.username}</td><td>${a.domain}</td><td>${a.package_name||'—'}</td>
          <td>${Nova.badge(a.status, a.status==='active'?'green':'yellow')}</td>
        </tr>`).join('')}
      </tbody></table>`
    : '<div class="empty">No accounts yet.</div>';
}

async function rAccounts(el) {
  el.innerHTML = `<div class="page-header">
    <h2 class="page-title">Hosting Accounts</h2>
    <button class="btn btn-primary btn-sm" onclick="resellerNav('createAccount')">+ Create Account</button>
  </div>
  <div class="card">
    <div style="padding:.75rem;border-bottom:1px solid var(--border)">
      <input id="r-search" class="form-control" placeholder="Search accounts…" oninput="rSearchAccounts(this.value)" style="max-width:300px">
    </div>
    <div id="r-accounts-list"><div class="loading">Loading…</div></div>
  </div>`;
  loadRAccounts();
}

async function loadRAccounts(search = '') {
  const el = document.getElementById('r-accounts-list');
  if (!el) return;
  const res = await Nova.api('accounts', 'list', { params: search ? { search } : {}});
  const acctRows = res?.data || [];
  if (!res?.success || !acctRows.length) { el.innerHTML = '<div class="empty">No accounts found.</div>'; return; }
  el.innerHTML = `<table class="table"><thead><tr><th>Username</th><th>Domain</th><th>Package</th><th>Disk</th><th>Status</th><th>Actions</th></tr></thead><tbody>
    ${acctRows.map(a => `<tr>
      <td><strong>${Nova.escHtml(a.username)}</strong></td>
      <td>${Nova.escHtml(a.domain)}</td>
      <td>${a.package_name ? Nova.escHtml(a.package_name) : '—'}</td>
      <td>${a.disk_usage_mb || 0} MB</td>
      <td>${Nova.badge(a.status, a.status==='active'?'green':a.status==='suspended'?'yellow':'red')}</td>
      <td style="display:flex;gap:.25rem;flex-wrap:wrap">
        <button class="btn btn-xs btn-primary" onclick="rLoginAs(${a.user_id},'${Nova.escHtml(a.username)}')">Login As</button>
        ${a.status === 'active'
          ? `<button class="btn btn-xs btn-warning" onclick="rSuspend(${a.id},'${a.username}')">Suspend</button>`
          : `<button class="btn btn-xs btn-success" onclick="rUnsuspend(${a.id},'${a.username}')">Unsuspend</button>`}
        <button class="btn btn-xs" onclick="rChangePass(${a.id},'${a.username}')">Passwd</button>
        <button class="btn btn-xs btn-danger" onclick="rTerminate(${a.id},'${a.username}')">Terminate</button>
      </td>
    </tr>`).join('')}
  </tbody></table>`;
}
window.loadRAccounts = loadRAccounts;
window.rSearchAccounts = (v) => loadRAccounts(v);

window.rLoginAs = async (userId, username) => {
  Nova.confirm(`Login as ${username}? You'll be taken to their panel. Use the banner to return.`, async () => {
    Nova.loading(`Switching to ${username}…`);
    const res = await Nova.api('auth', 'impersonate', { method: 'POST', body: { user_id: userId } });
    Nova.loadingDone();
    if (res?.success) {
      window.location.href = res.data?.portal_url || 'https://' + location.hostname + ':8880/';
    } else {
      Nova.toast(res?.message || 'Impersonation failed', 'error');
    }
  });
};

window.rSuspend = async (id, user) => {
  Nova.confirm(`Suspend account ${user}? Their website will show a suspension page.`, async () => {
    const res = await Nova.api('accounts', 'suspend', { method:'POST', body:{ account_id: id }});
    if (res?.success) { Nova.toast('Account suspended','success'); loadRAccounts(); }
    else Nova.toast(res?.message,'error');
  });
};
window.rUnsuspend = async (id, user) => {
  const res = await Nova.api('accounts', 'unsuspend', { method:'POST', body:{ account_id: id }});
  if (res?.success) { Nova.toast('Account unsuspended','success'); loadRAccounts(); }
  else Nova.toast(res?.message,'error');
};
window.rTerminate = (id, user) => {
  Nova.confirm(`TERMINATE ${user}? This permanently deletes all files, databases, DNS, and email. THIS CANNOT BE UNDONE.`, async () => {
    const res = await Nova.api('accounts', 'terminate', { method:'POST', body:{ account_id: id }});
    if (res?.success) { Nova.toast('Account terminated','success'); loadRAccounts(); }
    else Nova.toast(res?.message,'error');
  }, true);
};
window.rChangePass = (id, user) => {
  Nova.modal(`Change Password — ${user}`, `<div class="form-group"><label class="form-label">New Password</label><input id="rp-pass" type="password" class="form-control"></div>`,
    `<button class="btn btn-primary" onclick="Nova.api('accounts','change-password',{method:'POST',body:{account_id:${id},password:document.getElementById('rp-pass').value}}).then(r=>{if(r?.success){Nova.toast('Password updated','success');document.querySelector('.modal-overlay').remove();}else Nova.toast(r?.message,'error');})">Update</button>`);
};

async function rCreateAccount(el) {
  el.innerHTML = `<div class="page-header"><h2 class="page-title">Create Hosting Account</h2></div>
  <div class="card" style="max-width:600px">
    <div style="padding:1.5rem">
      <div class="form-group"><label class="form-label">Username <span style="color:var(--red)">*</span></label><input id="ca-user" class="form-control" placeholder="lowercase letters, numbers"></div>
      <div class="form-group"><label class="form-label">Password <span style="color:var(--red)">*</span></label><input id="ca-pass" type="password" class="form-control"></div>
      <div class="form-group"><label class="form-label">Email</label><input id="ca-email" type="email" class="form-control" placeholder="user@example.com"></div>
      <div class="form-group"><label class="form-label">Primary Domain <span style="color:var(--red)">*</span></label><input id="ca-domain" class="form-control" placeholder="example.com"></div>
      <div class="form-group"><label class="form-label">Package</label><select id="ca-pkg" class="form-control"><option value="">Loading…</option></select></div>
      <div style="margin-top:1.5rem;display:flex;gap:.75rem">
        <button class="btn btn-primary" onclick="submitCreateAccount()">Create Account</button>
        <button class="btn" onclick="resellerNav('accounts')">Cancel</button>
      </div>
      <div id="ca-result" style="margin-top:1rem"></div>
    </div>
  </div>`;

  Nova.api('packages', 'list').then(res => {
    const sel = document.getElementById('ca-pkg');
    if (sel && res?.success) {
      sel.innerHTML = res.data.map(p => `<option value="${p.id}">${p.name} — ${p.disk_mb}MB disk</option>`).join('');
    }
  });
}

window.submitCreateAccount = async () => {
  const btn = document.querySelector('#ca-result');
  if (btn) btn.textContent = '';
  Nova.loading('Creating hosting account…');
  const res = await Nova.api('accounts', 'create', { method:'POST', body:{
    username:   document.getElementById('ca-user')?.value,
    password:   document.getElementById('ca-pass')?.value,
    email:      document.getElementById('ca-email')?.value,
    domain:     document.getElementById('ca-domain')?.value,
    package_id: document.getElementById('ca-pkg')?.value,
  }});
  Nova.loadingDone();
  if (res?.success) {
    Nova.toast('Account created successfully!','success');
    if (btn) btn.innerHTML = `<div class="alert alert-success">Account created! <a href="#" onclick="resellerNav('accounts')">View accounts →</a></div>`;
  } else {
    Nova.toast(res?.message || 'Failed to create account','error');
    if (btn) btn.innerHTML = `<div class="alert alert-error">${res?.message || 'Error'}</div>`;
  }
};

async function rPackages(el) {
  el.innerHTML = `<div class="page-header">
    <h2 class="page-title">Packages</h2>
    <button class="btn btn-primary btn-sm" onclick="rAddPackage()">+ Add Package</button>
  </div>
  <div class="card"><div id="pkg-list"><div class="loading">Loading…</div></div></div>`;

  const res = await Nova.api('packages', 'list');
  const plist = document.getElementById('pkg-list');
  if (!res?.success || !res.data.length) { plist.innerHTML = '<div class="empty">No packages yet.</div>'; return; }
  plist.innerHTML = `<table class="table"><thead><tr><th>Name</th><th>Disk</th><th>BW</th><th>DBs</th><th>Emails</th><th>Domains</th><th>Price</th><th>Actions</th></tr></thead><tbody>
    ${res.data.map(p => `<tr>
      <td><strong>${p.name}</strong></td>
      <td>${p.disk_mb > 0 ? p.disk_mb+'MB' : '∞'}</td>
      <td>${p.bandwidth_mb > 0 ? p.bandwidth_mb+'MB' : '∞'}</td>
      <td>${p.databases || '∞'}</td>
      <td>${p.email_accounts || '∞'}</td>
      <td>${p.addon_domains || '∞'}</td>
      <td>${p.price ? '$'+p.price : 'Free'}</td>
      <td style="display:flex;gap:.25rem">
        <button class="btn btn-xs" onclick="rEditPackage(${p.id})">Edit</button>
        <button class="btn btn-xs btn-danger" onclick="rDeletePackage(${p.id},'${p.name}')">Del</button>
      </td>
    </tr>`).join('')}
  </tbody></table>`;
}

window.rAddPackage = () => showPackageModal();
window.rEditPackage = async (id) => {
  const res = await Nova.api('packages', 'get', { params:{ id }});
  if (res?.success) showPackageModal(res.data);
};
function showPackageModal(pkg = null) {
  const p = pkg || {};
  Nova.modal(pkg ? 'Edit Package' : 'Add Package', `
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:.75rem">
      <div class="form-group" style="grid-column:1/-1"><label class="form-label">Package Name</label><input id="pk-name" class="form-control" value="${p.name||''}"></div>
      <div class="form-group"><label class="form-label">Disk (MB, 0=∞)</label><input id="pk-disk" type="number" class="form-control" value="${p.disk_mb||0}"></div>
      <div class="form-group"><label class="form-label">Bandwidth (MB)</label><input id="pk-bw" type="number" class="form-control" value="${p.bandwidth_mb||0}"></div>
      <div class="form-group"><label class="form-label">Databases</label><input id="pk-db" type="number" class="form-control" value="${p.databases||0}"></div>
      <div class="form-group"><label class="form-label">Email Accounts</label><input id="pk-email" type="number" class="form-control" value="${p.email_accounts||0}"></div>
      <div class="form-group"><label class="form-label">Addon Domains</label><input id="pk-adom" type="number" class="form-control" value="${p.addon_domains||0}"></div>
      <div class="form-group"><label class="form-label">Subdomains</label><input id="pk-sub" type="number" class="form-control" value="${p.subdomains||0}"></div>
      <div class="form-group"><label class="form-label">FTP Accounts</label><input id="pk-ftp" type="number" class="form-control" value="${p.ftp_accounts||0}"></div>
      <div class="form-group"><label class="form-label">Price ($/mo)</label><input id="pk-price" type="number" step="0.01" class="form-control" value="${p.price||0}"></div>
    </div>`,
    `<button class="btn btn-primary" onclick="submitPackage(${p.id||'null'})">Save</button>`);
}
window.submitPackage = async (id) => {
  const body = { name:document.getElementById('pk-name')?.value, disk_mb:parseInt(document.getElementById('pk-disk')?.value), bandwidth_mb:parseInt(document.getElementById('pk-bw')?.value), databases:parseInt(document.getElementById('pk-db')?.value), email_accounts:parseInt(document.getElementById('pk-email')?.value), addon_domains:parseInt(document.getElementById('pk-adom')?.value), subdomains:parseInt(document.getElementById('pk-sub')?.value), ftp_accounts:parseInt(document.getElementById('pk-ftp')?.value), price:parseFloat(document.getElementById('pk-price')?.value) };
  const res = id ? await Nova.api('packages','update',{method:'POST',body:{...body,id}}) : await Nova.api('packages','create',{method:'POST',body});
  if (res?.success) { Nova.toast(id ? 'Package updated' : 'Package created','success'); document.querySelector('.modal-overlay')?.remove(); rPackages(document.getElementById('page-content')); }
  else Nova.toast(res?.message,'error');
};
window.rDeletePackage = (id, name) => {
  Nova.confirm(`Delete package "${name}"? Cannot delete if accounts are using it.`, async () => {
    const res = await Nova.api('packages','delete',{method:'POST',body:{id}});
    if (res?.success) { Nova.toast('Deleted','success'); rPackages(document.getElementById('page-content')); }
    else Nova.toast(res?.message,'error');
  }, true);
};

async function rDNS(el) {
  el.innerHTML = `<div class="page-header"><h2 class="page-title">DNS Zones</h2></div>
  <div class="card"><div id="r-dns-list"><div class="loading">Loading…</div></div></div>`;
  const res = await Nova.api('dns', 'zones');
  const list = document.getElementById('r-dns-list');
  if (!res?.success || !res.data.length) { list.innerHTML = '<div class="empty">No DNS zones.</div>'; return; }
  list.innerHTML = `<table class="table"><thead><tr><th>Domain</th><th>Account</th><th>Records</th><th>Actions</th></tr></thead><tbody>
    ${res.data.map(z => `<tr>
      <td>${z.domain}</td>
      <td>${z.username||'—'}</td>
      <td>${z.record_count||0}</td>
      <td><button class="btn btn-xs" onclick="rViewZone(${z.id},'${z.domain}')">Edit Records</button></td>
    </tr>`).join('')}
  </tbody></table>`;
}

window.rViewZone = async (zoneId, domain) => {
  const res = await Nova.api('dns', 'records', { params:{ zone_id: zoneId }});
  if (!res?.success) { Nova.toast('Failed to load records','error'); return; }
  const rows = res.data.map(r => `<tr>
    <td>${r.name}</td><td>${Nova.badge(r.type,'default')}</td><td><code style="font-size:.8rem">${r.value}</code></td><td>${r.ttl}</td>
    <td><button class="btn btn-xs btn-danger" onclick="rDeleteRecord(${r.id},${zoneId},'${domain}')">Del</button></td>
  </tr>`).join('');
  Nova.modal(`DNS Records — ${domain}`,
    `<button class="btn btn-sm btn-primary" style="margin-bottom:.75rem" onclick="rAddRecord(${zoneId},'${domain}')">+ Add Record</button>
    <table class="table"><thead><tr><th>Name</th><th>Type</th><th>Value</th><th>TTL</th><th></th></tr></thead><tbody>${rows}</tbody></table>`);
};
window.rAddRecord = (zoneId, domain) => {
  Nova.modal('Add DNS Record', `
    <div class="form-group"><label class="form-label">Name</label><input id="dns-name" class="form-control" placeholder="@ or subdomain"></div>
    <div class="form-group"><label class="form-label">Type</label><select id="dns-type" class="form-control"><option>A</option><option>AAAA</option><option>CNAME</option><option>MX</option><option>TXT</option><option>NS</option><option>SRV</option></select></div>
    <div class="form-group"><label class="form-label">Value</label><input id="dns-val" class="form-control"></div>
    <div class="form-group"><label class="form-label">TTL</label><input id="dns-ttl" type="number" class="form-control" value="3600"></div>
    <div class="form-group"><label class="form-label">Priority (MX)</label><input id="dns-pri" type="number" class="form-control" value="10"></div>`,
    `<button class="btn btn-primary" onclick="Nova.api('dns','add-record',{method:'POST',body:{zone_id:${zoneId},name:document.getElementById('dns-name').value,type:document.getElementById('dns-type').value,value:document.getElementById('dns-val').value,ttl:parseInt(document.getElementById('dns-ttl').value),priority:parseInt(document.getElementById('dns-pri').value)}}).then(r=>{if(r?.success){Nova.toast('Record added','success');document.querySelector('.modal-overlay').remove();}else Nova.toast(r?.message,'error');})">Add Record</button>`);
};
window.rDeleteRecord = async (id, zoneId, domain) => {
  Nova.confirm('Delete this DNS record?', async () => {
    const res = await Nova.api('dns', 'delete-record', { method:'POST', body:{id, zone_id: zoneId }});
    if (res?.success) { Nova.toast('Deleted','success'); document.querySelector('.modal-overlay')?.remove(); rViewZone(zoneId, domain); }
    else Nova.toast(res?.message,'error');
  }, true);
};

/* ── Nav ────────────────────────────────────────────────────────────────── */
const rNavGroups = [
  { label: 'Overview', items: [
    { id: 'dashboard', label: 'Dashboard',
      svg: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/></svg>' },
  ]},
  { label: 'Accounts', items: [
    { id: 'accounts', label: 'All Accounts',
      svg: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>' },
    { id: 'createAccount', label: 'New Account',
      svg: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="8.5" cy="7" r="4"/><line x1="20" y1="8" x2="20" y2="14"/><line x1="17" y1="11" x2="23" y2="11"/></svg>' },
    { id: 'packages', label: 'Packages',
      svg: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="16.5" y1="9.4" x2="7.5" y2="4.21"/><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><polyline points="3.27 6.96 12 12.01 20.73 6.96"/><line x1="12" y1="22.08" x2="12" y2="12"/></svg>' },
  ]},
  { label: 'DNS', items: [
    { id: 'dns', label: 'DNS Zones',
      svg: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/></svg>' },
  ]},
  { label: 'Tools', items: [
    { id: 'docker', label: 'Docker',
      svg: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="9" width="4" height="4"/><rect x="7" y="9" width="4" height="4"/><rect x="12" y="9" width="4" height="4"/><rect x="7" y="4" width="4" height="4"/><path d="M22 11c0 5-3.9 9-10 9-8 0-10-7-10-7"/></svg>' },
    { id: 'whitelabel', label: 'White Label',
      svg: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"/><path d="M19.07 4.93a10 10 0 0 1 0 14.14M4.93 4.93a10 10 0 0 0 0 14.14"/></svg>' },
  ]},
];
const rPages = { dashboard: rDashboard, accounts: rAccounts, createAccount: rCreateAccount, packages: rPackages, dns: rDNS, docker: rDocker, whitelabel: rWhiteLabel };

let _rActivePage = 'dashboard';

function renderRNav() {
  const nav = document.getElementById('sidebar-nav');
  if (!nav) return;
  nav.innerHTML = rNavGroups.map(g => `
    <div class="sidebar-section">
      <div class="sidebar-section-label">${g.label}</div>
      ${g.items.map(n => `
        <a href="#" class="sidebar-link${n.id === _rActivePage ? ' active' : ''}" data-page="${n.id}">
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
      resellerNav(link.dataset.page);
    });
  });
}

window.resellerNav = (page) => {
  _rActivePage = page;
  renderRNav();
  const allItems = rNavGroups.flatMap(g => g.items);
  const item = allItems.find(n => n.id === page);
  const titleEl = document.getElementById('page-title');
  if (titleEl && item) titleEl.textContent = item.label;
  const content = document.getElementById('page-content');
  if (!content) return;
  content.innerHTML = '<div style="padding:2rem;color:var(--text-muted);text-align:center">Loading…</div>';
  if (rPages[page]) rPages[page](content);
};

document.addEventListener('DOMContentLoaded', async () => {
  const ok = await initReseller();
  if (!ok) return;
  document.getElementById('logout-btn')?.addEventListener('click', async e => {
    e.preventDefault();
    await Nova.api('auth', 'logout', { method: 'POST' });
    location.href = '/';
  });
  renderRNav();
  window.resellerNav('dashboard');
});

/* ── Docker (Reseller #33) ────────────────────────────────────────────────── */
async function rDocker(el) {
  el.innerHTML = '<div class="loading">Loading…</div>';
  const [stRes, acctRes] = await Promise.all([
    Nova.api('docker', 'stacks'),
    Nova.api('accounts', 'list', { params: { limit: 200 } }),
  ]);
  const stacks = stRes?.data?.stacks || [];
  const accts  = acctRes?.data || [];

  el.innerHTML = `
<div class="page-header"><h2 class="page-title">Docker</h2></div>
<p class="text-muted" style="margin-bottom:1.5rem">Manage Docker containers and quotas for your customers. Contact the server admin to change your own Docker allocation.</p>

<div style="display:flex;gap:.5rem;margin-bottom:1rem">
  <button class="btn btn-sm ${_rDockerTab==='containers'?'btn-primary':'btn-ghost'}" onclick="rDockerTab('containers')">Containers</button>
  <button class="btn btn-sm ${_rDockerTab==='quotas'?'btn-primary':'btn-ghost'}" onclick="rDockerTab('quotas')">Customer Quotas</button>
  <button class="btn btn-sm ${_rDockerTab==='catalog'?'btn-primary':'btn-ghost'}" onclick="rDockerTab('catalog')">App Catalog</button>
</div>
<div id="rdocker-content"><div class="loading">Loading…</div></div>`;

  window._rDockerAccts = accts;
  window._rDockerTab   = window._rDockerTab || 'containers';

  window.rDockerTab = async (tab) => {
    window._rDockerTab = tab;
    document.querySelectorAll('[onclick^="rDockerTab"]').forEach(b => {
      const t = b.getAttribute('onclick').match(/'([^']+)'/)?.[1];
      b.className = 'btn btn-sm ' + (t === tab ? 'btn-primary' : 'btn-ghost');
    });
    await rDockerLoadTab(tab);
  };

  await rDockerLoadTab(window._rDockerTab);
}

window._rDockerTab = 'containers';

async function rDockerLoadTab(tab) {
  const tc = document.getElementById('rdocker-content');
  if (!tc) return;
  tc.innerHTML = '<div class="loading">Loading…</div>';

  if (tab === 'containers') {
    const r = await Nova.api('docker', 'containers');
    const rows = r?.data?.containers || [];
    tc.innerHTML = rows.length === 0
      ? '<div class="text-muted" style="padding:2rem;text-align:center">No containers for your accounts</div>'
      : `<div style="overflow-x:auto"><table class="table"><thead><tr><th>Name</th><th>Image</th><th>Status</th><th>Account</th><th>Actions</th></tr></thead><tbody>
${rows.map(c=>`<tr>
  <td style="font-family:monospace;font-size:.82rem">${Nova.escHtml(c.name)}</td>
  <td style="font-size:.82rem">${Nova.escHtml(c.image)}</td>
  <td>${Nova.badge(c.status,c.status==='running'?'green':'red')}</td>
  <td>${c.account_id||'—'}</td>
  <td>
    ${c.status==='running'
      ? `<button class="btn btn-xs btn-warning" onclick="rDockerAct('${Nova.escHtml(c.container_id||'')}','stop')">Stop</button>`
      : `<button class="btn btn-xs btn-success" onclick="rDockerAct('${Nova.escHtml(c.container_id||'')}','start')">Start</button>`}
    <button class="btn btn-xs btn-ghost" onclick="rDockerLogs('${Nova.escHtml(c.container_id||'')}','${Nova.escHtml(c.name)}')">Logs</button>
  </td>
</tr>`).join('')}
</tbody></table></div>`;

  } else if (tab === 'quotas') {
    const accts = window._rDockerAccts || [];
    tc.innerHTML = accts.length === 0
      ? '<div class="text-muted" style="padding:2rem;text-align:center">No accounts</div>'
      : `<p class="text-muted" style="margin-bottom:1rem">Set Docker limits for each of your customers.</p>
<div style="overflow-x:auto"><table class="table"><thead><tr><th>Username</th><th>Max Containers</th><th>Max Memory</th><th>Max CPUs</th><th>Actions</th></tr></thead><tbody>
${accts.map(u=>`<tr>
  <td>${Nova.escHtml(u.username)}</td>
  <td>2</td><td>512 MB</td><td>1.0</td>
  <td><button class="btn btn-xs btn-primary" onclick="rDockerQuotaModal(${u.user_id},'${Nova.escHtml(u.username)}')">Edit</button></td>
</tr>`).join('')}
</tbody></table></div>`;

  } else if (tab === 'catalog') {
    const r = await Nova.api('docker', 'catalog');
    const catalog = r?.data?.catalog || {};
    const accts   = window._rDockerAccts || [];
    tc.innerHTML = `
<p class="text-muted" style="margin-bottom:1rem">Pre-install app stacks for your customers.</p>
<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:1rem">
${Object.entries(catalog).map(([key,app])=>`
<div class="card" style="cursor:pointer" onclick="rDockerLaunchModal('${key}','${Nova.escHtml(app.name)}')">
  <div class="card-body" style="text-align:center;padding:1.5rem">
    <div style="font-size:1.5rem;font-weight:700;margin-bottom:.5rem;color:var(--primary)">${Nova.escHtml(app.icon)}</div>
    <div style="font-weight:600">${Nova.escHtml(app.name)}</div>
    <div style="font-size:.8rem;color:var(--text-muted);margin-top:.25rem">${Nova.escHtml(app.description)}</div>
    <button class="btn btn-sm btn-primary" style="margin-top:1rem">Deploy</button>
  </div>
</div>`).join('')}
</div>`;
  }
}

window.rDockerAct = async (cid, action) => {
  Nova.loading(`${action.charAt(0).toUpperCase()+action.slice(1)}ing container…`);
  const r = await Nova.api('docker', 'container-action', { method: 'POST', body: { container_id: cid, action } });
  Nova.loadingDone();
  Nova.toast(r?.success ? `Container ${action}ed` : (r?.message||'Failed'), r?.success?'success':'error');
  if (r?.success) rDockerLoadTab('containers');
};

window.rDockerLogs = async (cid, name) => {
  const r = await Nova.api('docker', 'container-logs', { params: { container_id: cid, lines: 100 } });
  Nova.modal(`Logs: ${name}`, `<pre style="max-height:400px;overflow:auto;font-size:.78rem;white-space:pre-wrap">${Nova.escHtml(r?.data?.logs||'')}</pre>`);
};

window.rDockerQuotaModal = (userId, username) => {
  const ov = Nova.modal(`Docker Quota: ${username}`,
    `<div class="form-group"><label>Max Containers</label><input id="rdq-cnt" type="number" class="form-control" value="2" min="0"></div>
     <div class="form-group"><label>Max Memory (MB)</label><input id="rdq-mem" type="number" class="form-control" value="512" min="64"></div>
     <div class="form-group"><label>Max CPUs</label><input id="rdq-cpus" type="number" step="0.5" class="form-control" value="1.0" min="0.1"></div>`,
    `<button class="btn btn-ghost" onclick="this.closest('.modal-overlay').remove()">Cancel</button>
     <button class="btn btn-primary" onclick="rDockerQuotaSubmit(${userId})">Save</button>`
  );
  window.rDockerQuotaSubmit = async (uid) => {
    ov.remove();
    const r = await Nova.api('docker', 'quota-set', { method:'POST', body:{
      user_id: uid,
      max_containers: parseInt(document.getElementById('rdq-cnt').value)||2,
      max_memory_mb:  parseInt(document.getElementById('rdq-mem').value)||512,
      max_cpus:       parseFloat(document.getElementById('rdq-cpus').value)||1.0,
    }});
    Nova.toast(r?.success?'Quota saved':(r?.message||'Failed'),r?.success?'success':'error');
  };
};

window.rDockerLaunchModal = async (appKey, appName) => {
  const catRes = await Nova.api('docker', 'catalog');
  const app    = catRes?.data?.catalog?.[appKey];
  if (!app) return;
  const accts  = window._rDockerAccts || [];
  const acctOpts = accts.map(a=>`<option value="${a.id}">${Nova.escHtml(a.username)}</option>`).join('');
  const paramFields = (app.params||[]).map(p=>`
    <div class="form-group"><label>${Nova.escHtml(p.label)}${p.required?' *':''}</label>
    <input id="rl-${Nova.escHtml(p.key)}" type="${p.type||'text'}" class="form-control" ${p.placeholder?`placeholder="${Nova.escHtml(p.placeholder)}"`:''}></div>`).join('');
  const ov = Nova.modal(`Deploy ${appName}`,
    `<div class="form-group"><label>Account</label><select id="rl-acct" class="form-control"><option value="">Select account</option>${acctOpts}</select></div>${paramFields}`,
    `<button class="btn btn-ghost" onclick="this.closest('.modal-overlay').remove()">Cancel</button>
     <button class="btn btn-primary" onclick="rDockerLaunchSubmit('${appKey}')">Deploy</button>`
  );
  window.rDockerLaunchSubmit = async (key) => {
    const acctId = parseInt(document.getElementById('rl-acct').value)||0;
    if (!acctId) { Nova.toast('Select an account','error'); return; }
    const params = {};
    (app.params||[]).forEach(p => { params[p.key] = document.getElementById('rl-'+p.key)?.value||''; });
    ov.remove();
    Nova.loading(`Deploying ${appName}… this may take a minute`);
    const r = await Nova.api('docker', 'launch', { method:'POST', body:{ account_id: acctId, app_key: key, params }});
    Nova.loadingDone();
    Nova.toast(r?.success?`${appName} deployed!`:(r?.message||'Deploy failed'), r?.success?'success':'error');
    if (r?.success) rDockerLoadTab('containers');
  };
};

// ── White Label / Branding (#18) ────────────────────────────────────────────
async function rWhiteLabel(el) {
  el.innerHTML = '<div class="page-loader">Loading…</div>';
  const res = await Nova.api('branding', 'get');
  const b   = res?.data || {};

  el.innerHTML = `
<div class="page-header"><h1 class="page-title">White Label Branding</h1></div>
<div class="grid-2" style="gap:1.5rem;align-items:start">

  <div class="card">
    <div class="card-header"><span class="card-title">Panel Identity</span></div>
    <div class="card-body" style="display:flex;flex-direction:column;gap:1rem">
      <div class="form-group">
        <label>Panel Name</label>
        <input id="wl-name" class="form-control" value="${Nova.escHtml(b.panel_name||'NovaCPX')}" placeholder="NovaCPX">
      </div>
      <div class="form-group">
        <label>Logo</label>
        ${b.logo_url ? `<div style="margin-bottom:.5rem"><img src="${Nova.escHtml(b.logo_url)}" style="max-height:50px;max-width:200px;border-radius:6px;background:var(--bg2);padding:.5rem"></div>` : ''}
        <div style="display:flex;gap:.5rem;align-items:center;flex-wrap:wrap">
          <label class="btn btn-ghost btn-sm" style="cursor:pointer">
            Upload Logo <input type="file" id="wl-logo-file" accept="image/*" style="display:none" onchange="rWlUploadLogo()">
          </label>
          ${b.logo_url ? `<button class="btn btn-ghost btn-sm" onclick="rWlDeleteLogo()" style="color:var(--danger)">Remove</button>` : ''}
          <span class="text-muted text-sm">PNG/SVG/JPG · max 512 KB</span>
        </div>
      </div>
      <div class="form-group">
        <label>Custom CSS <span class="text-muted text-sm">(advanced)</span></label>
        <textarea id="wl-css" class="form-control" rows="4" style="font-family:monospace;font-size:.8rem" placeholder="/* e.g. .sidebar { background: #1a1a2e; } */">${Nova.escHtml(b.custom_css||'')}</textarea>
      </div>
    </div>
  </div>

  <div style="display:flex;flex-direction:column;gap:1.5rem">
    <div class="card">
      <div class="card-header"><span class="card-title">Colors</span></div>
      <div class="card-body" style="display:flex;flex-direction:column;gap:1rem">
        <div class="form-group">
          <label>Primary Color</label>
          <div style="display:flex;gap:.5rem;align-items:center">
            <input type="color" id="wl-primary" value="${Nova.escHtml(b.primary_color||'#6366f1')}" style="width:48px;height:36px;padding:2px;border-radius:6px;border:1px solid var(--border);background:var(--bg2);cursor:pointer">
            <input type="text"  id="wl-primary-hex" class="form-control" style="width:110px;font-family:monospace" value="${Nova.escHtml(b.primary_color||'#6366f1')}" maxlength="7">
          </div>
        </div>
        <div class="form-group">
          <label>Accent Color</label>
          <div style="display:flex;gap:.5rem;align-items:center">
            <input type="color" id="wl-accent" value="${Nova.escHtml(b.accent_color||'#0ea5e9')}" style="width:48px;height:36px;padding:2px;border-radius:6px;border:1px solid var(--border);background:var(--bg2);cursor:pointer">
            <input type="text"  id="wl-accent-hex" class="form-control" style="width:110px;font-family:monospace" value="${Nova.escHtml(b.accent_color||'#0ea5e9')}" maxlength="7">
          </div>
        </div>
        <div id="wl-color-preview" style="height:40px;border-radius:8px;background:linear-gradient(135deg,${Nova.escHtml(b.primary_color||'#6366f1')},${Nova.escHtml(b.accent_color||'#0ea5e9')});transition:background .3s"></div>
      </div>
    </div>

    <div class="card">
      <div class="card-header"><span class="card-title">Support</span></div>
      <div class="card-body" style="display:flex;flex-direction:column;gap:1rem">
        <div class="form-group">
          <label>Support Email</label>
          <input id="wl-email" class="form-control" type="email" value="${Nova.escHtml(b.support_email||'')}" placeholder="support@yourdomain.com">
        </div>
        <div class="form-group">
          <label>Support URL</label>
          <input id="wl-url" class="form-control" type="url" value="${Nova.escHtml(b.support_url||'')}" placeholder="https://support.yourdomain.com">
        </div>
        <label style="display:flex;align-items:center;gap:.5rem;cursor:pointer">
          <input type="checkbox" id="wl-hide-powered" ${b.hide_powered_by ? 'checked' : ''}>
          Hide "Powered by NovaCPX" in panel footer
        </label>
      </div>
    </div>

    <div style="display:flex;gap:.5rem;justify-content:flex-end">
      <button class="btn btn-ghost" onclick="rWhiteLabel(document.getElementById('page-content'))">Reset</button>
      <button class="btn btn-primary" onclick="rWlSave()">Save Branding</button>
    </div>
  </div>
</div>`;

  // Sync color pickers ↔ hex inputs ↔ preview
  ['primary','accent'].forEach(k => {
    const picker = document.getElementById('wl-'+k);
    const hex    = document.getElementById('wl-'+k+'-hex');
    const sync   = () => {
      if (picker) hex.value = picker.value;
      rWlUpdatePreview();
    };
    const syncBack = () => {
      if (/^#[0-9a-fA-F]{6}$/.test(hex.value)) { picker.value = hex.value; rWlUpdatePreview(); }
    };
    picker?.addEventListener('input', sync);
    hex?.addEventListener('input', syncBack);
  });
}

function rWlUpdatePreview() {
  const p  = document.getElementById('wl-primary-hex')?.value || '#6366f1';
  const a  = document.getElementById('wl-accent-hex')?.value  || '#0ea5e9';
  const el = document.getElementById('wl-color-preview');
  if (el) el.style.background = `linear-gradient(135deg,${p},${a})`;
  // Live-preview CSS vars
  const style = document.getElementById('reseller-branding') || (() => {
    const s = document.createElement('style'); s.id = 'reseller-branding'; document.head.appendChild(s); return s;
  })();
  style.textContent = `:root { --primary: ${p}; --primary-dark: ${p}; --accent: ${a}; }`;
}

window.rWlUploadLogo = async () => {
  const file = document.getElementById('wl-logo-file')?.files?.[0];
  if (!file) return;
  if (file.size > 512 * 1024) { Nova.toast('Logo must be under 512 KB', 'error'); return; }
  const fd = new FormData();
  fd.append('logo', file);
  Nova.toast('Uploading…', 'info', 5000);
  try {
    const res = await fetch('/api/branding/upload-logo', {
      method: 'POST', credentials: 'include', body: fd
    });
    const data = await res.json();
    Nova.toast(data?.success ? 'Logo uploaded' : (data?.message || 'Upload failed'),
               data?.success ? 'success' : 'error');
    if (data?.success) rWhiteLabel(document.getElementById('page-content'));
  } catch (e) { Nova.toast('Upload failed', 'error'); }
};

window.rWlDeleteLogo = async () => {
  const r = await Nova.api('branding', 'delete-logo', { method: 'POST' });
  Nova.toast(r?.success ? 'Logo removed' : (r?.message || 'Failed'), r?.success ? 'success' : 'error');
  if (r?.success) rWhiteLabel(document.getElementById('page-content'));
};

window.rWlSave = async () => {
  const body = {
    panel_name:      document.getElementById('wl-name')?.value?.trim()        || 'NovaCPX',
    primary_color:   document.getElementById('wl-primary-hex')?.value          || '#6366f1',
    accent_color:    document.getElementById('wl-accent-hex')?.value           || '#0ea5e9',
    support_email:   document.getElementById('wl-email')?.value?.trim()        || '',
    support_url:     document.getElementById('wl-url')?.value?.trim()          || '',
    hide_powered_by: document.getElementById('wl-hide-powered')?.checked ? 1 : 0,
    custom_css:      document.getElementById('wl-css')?.value                  || '',
  };
  Nova.loading('Saving branding…');
  const r = await Nova.api('branding', 'save', { method: 'POST', body });
  Nova.loadingDone();
  Nova.toast(r?.success ? 'Branding saved — reload to see changes' : (r?.message || 'Save failed'),
             r?.success ? 'success' : 'error');
};
