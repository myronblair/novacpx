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
  const res = await Nova.api('auth', 'login', { method: 'POST', body: { username: document.getElementById('li-user')?.value, password: document.getElementById('li-pass')?.value }});
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
  const accts = res?.data?.accounts || [];

  document.getElementById('r-stats').innerHTML = [
    { label: 'Total Accounts', val: res?.data?.total || 0, icon: 'ni-accounts' },
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
  if (!res?.success || !res.data.accounts.length) { el.innerHTML = '<div class="empty">No accounts found.</div>'; return; }
  el.innerHTML = `<table class="table"><thead><tr><th>Username</th><th>Domain</th><th>Package</th><th>Disk</th><th>Status</th><th>Actions</th></tr></thead><tbody>
    ${res.data.accounts.map(a => `<tr>
      <td><strong>${a.username}</strong></td>
      <td>${a.domain}</td>
      <td>${a.package_name || '—'}</td>
      <td>${a.disk_usage_mb || 0} MB</td>
      <td>${Nova.badge(a.status, a.status==='active'?'green':a.status==='suspended'?'yellow':'red')}</td>
      <td style="display:flex;gap:.25rem">
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
  const res = await Nova.api('accounts', 'create', { method:'POST', body:{
    username:   document.getElementById('ca-user')?.value,
    password:   document.getElementById('ca-pass')?.value,
    email:      document.getElementById('ca-email')?.value,
    domain:     document.getElementById('ca-domain')?.value,
    package_id: document.getElementById('ca-pkg')?.value,
  }});
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
const rNavItems = [
  { id:'dashboard',      label:'Dashboard',   icon:'ni-dashboard' },
  { id:'accounts',       label:'Accounts',    icon:'ni-accounts' },
  { id:'createAccount',  label:'New Account', icon:'ni-add' },
  { id:'packages',       label:'Packages',    icon:'ni-packages' },
  { id:'dns',            label:'DNS Zones',   icon:'ni-dns' },
];
const rPages = { dashboard: rDashboard, accounts: rAccounts, createAccount: rCreateAccount, packages: rPackages, dns: rDNS };

let _rActivePage = 'dashboard';

function renderRNav() {
  const nav = document.getElementById('sidebar-nav');
  if (!nav) return;
  nav.innerHTML = rNavItems.map(n => `
    <a class="nav-item ${n.id === _rActivePage ? 'active' : ''}" href="#" onclick="resellerNav('${n.id}');return false">
      <svg width="18" height="18"><use href="/assets/img/nova-icons.svg#${n.icon}"/></svg>
      <span>${n.label}</span>
    </a>`).join('');
}

window.resellerNav = (page) => {
  _rActivePage = page;
  renderRNav();
  const content = document.getElementById('page-content');
  if (!content) return;
  content.innerHTML = '<div class="loading">Loading…</div>';
  if (rPages[page]) rPages[page](content);
};

document.addEventListener('DOMContentLoaded', async () => {
  const ok = await initReseller();
  if (!ok) return;
  renderRNav();
  window.resellerNav('dashboard');
});
