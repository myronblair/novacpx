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
  { id:'docker',         label:'Docker',      icon:'ni-docker' },
];
const rPages = { dashboard: rDashboard, accounts: rAccounts, createAccount: rCreateAccount, packages: rPackages, dns: rDNS, docker: rDocker };

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

/* ── Docker (Reseller #33) ────────────────────────────────────────────────── */
async function rDocker(el) {
  el.innerHTML = '<div class="loading">Loading…</div>';
  const [stRes, acctRes] = await Promise.all([
    Nova.api('docker', 'stacks'),
    Nova.api('accounts', 'list', { params: { limit: 200 } }),
  ]);
  const stacks = stRes?.data?.stacks || [];
  const accts  = acctRes?.data?.accounts || [];

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
  const r = await Nova.api('docker', 'container-action', { method: 'POST', body: { container_id: cid, action } });
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
    Nova.toast('Deploying…', 'info', 10000);
    const r = await Nova.api('docker', 'launch', { method:'POST', body:{ account_id: acctId, app_key: key, params }});
    Nova.toast(r?.success?`${appName} deployed!`:(r?.message||'Deploy failed'), r?.success?'success':'error');
    if (r?.success) rDockerLoadTab('containers');
  };
};
