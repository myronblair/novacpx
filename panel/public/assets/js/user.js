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
  return true;
}

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
  const res = await Nova.api('auth', 'login', { method: 'POST', body: { username: u, password: p } });
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
};

/* ── Dashboard ───────────────────────────────────────────────────────────── */
async function dashboard(el) {
  el.innerHTML = `<div class="page-header"><h2 class="page-title">Dashboard</h2></div>
    <div id="dash-rings" class="stats-grid">
      ${['Disk','Bandwidth','Emails','Databases'].map(l => `<div class="stat-card"><div class="stat-label">${l}</div><div class="stat-value">—</div></div>`).join('')}
    </div>
    <div class="card" style="margin-top:1.5rem">
      <div class="card-header"><span class="card-title">Quick Access</span></div>
      <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(130px,1fr));gap:1rem;padding:1.25rem">
        ${[
          ['ni-domains','Domains','domains'],
          ['ni-email','Email','email'],
          ['ni-databases','Databases','databases'],
          ['ni-ftp','FTP','ftp'],
          ['ni-ssl','SSL','ssl'],
          ['ni-php','PHP','php'],
          ['ni-cron','Cron Jobs','cron'],
          ['ni-files','File Manager','files'],
        ].map(([icon,label,page]) => `
          <button class="btn" style="display:flex;flex-direction:column;align-items:center;gap:.5rem;padding:1rem;background:var(--bg3);border:1px solid var(--border);border-radius:var(--radius)" onclick="userNav('${page}')">
            <svg width="24" height="24" style="color:var(--primary)"><use href="/assets/img/nova-icons.svg#${icon}"/></svg>
            <span style="font-size:.8rem">${label}</span>
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

window.issueSSL = async (domainId, domain) => {
  Nova.toast(`Issuing Let's Encrypt SSL for ${domain}…`,'info',6000);
  const res = await Nova.api('ssl', 'issue', { method: 'POST', body: { domain } });
  if (res?.success) { Nova.toast('SSL issued successfully','success'); loadDomainsList(); }
  else Nova.toast(res?.message || 'SSL failed — check domain DNS','error',6000);
};
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

window.addEmailAccount = () => {
  Nova.modal('Add Email Account', `
    <div class="form-group"><label class="form-label">Email Address</label><input id="em-addr" class="form-control" placeholder="user@yourdomain.com"></div>
    <div class="form-group"><label class="form-label">Password</label><input id="em-pass" type="password" class="form-control"></div>
    <div class="form-group"><label class="form-label">Quota (MB, 0=unlimited)</label><input id="em-quota" type="number" class="form-control" value="0"></div>`,
    `<button class="btn btn-primary" onclick="submitAddEmail()">Create</button>`
  );
};

window.submitAddEmail = async () => {
  const res = await Nova.api('email', 'create', { method: 'POST', body: {
    email: document.getElementById('em-addr')?.value,
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
window.submitIssueSSL = async () => {
  const domain = document.getElementById('ssl-dom')?.value;
  Nova.toast(`Issuing SSL for ${domain}…`, 'info', 8000);
  document.querySelector('.modal-overlay')?.remove();
  const res = await Nova.api('ssl', 'issue', { method:'POST', body:{ domain, email: document.getElementById('ssl-email')?.value }});
  if (res?.success) { Nova.toast('SSL issued!','success'); loadSSLList(); }
  else Nova.toast(res?.message || 'SSL issue failed','error',8000);
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
    document.getElementById('php-versions').innerHTML = versRes.data.map(v => `
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
  const res = await Nova.api('php', 'switch-version', { method:'POST', body:{ version: ver }});
  if (res?.success) { Nova.toast(`Switched to PHP ${ver}`,'success'); phpPage(document.getElementById('page-content')); }
  else Nova.toast(res?.message,'error');
};
window.savePHPSettings = async () => {
  const res = await Nova.api('php', 'update-config', { method:'POST', body:{
    memory_limit: document.getElementById('php-mem')?.value,
    max_execution_time: document.getElementById('php-exec')?.value,
    upload_max_filesize: document.getElementById('php-upload')?.value,
    post_max_size: document.getElementById('php-post')?.value,
  }});
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
  el.innerHTML = `<div class="page-header">
    <h2 class="page-title">Backups</h2>
    <button class="btn btn-primary btn-sm" onclick="createBackup()">+ Create Backup</button>
  </div>
  <div class="card"><div id="backup-list"><div class="loading">Loading…</div></div></div>`;

  const res = await Nova.api('system', 'audit-log', { params:{ limit:5 }});
  document.getElementById('backup-list').innerHTML = `<div style="padding:1.5rem;text-align:center;color:var(--muted)">
    <svg width="48" height="48" style="opacity:.4"><use href="/assets/img/nova-icons.svg#ni-backups"/></svg>
    <div style="margin-top:.75rem">Backup management is being configured by your hosting provider.</div>
    <div style="font-size:.85rem;margin-top:.25rem">Contact support to request a manual backup.</div>
  </div>`;
}
window.createBackup = () => Nova.toast('Backup request submitted — you will be notified when ready.','info');

/* ── Navigation ─────────────────────────────────────────────────────────── */
const navItems = [
  { id: 'dashboard',  label: 'Dashboard',    icon: 'ni-dashboard' },
  { id: 'domains',    label: 'Domains',       icon: 'ni-domains' },
  { id: 'email',      label: 'Email',         icon: 'ni-email' },
  { id: 'databases',  label: 'Databases',     icon: 'ni-databases' },
  { id: 'ftp',        label: 'FTP',           icon: 'ni-ftp' },
  { id: 'ssl',        label: 'SSL / TLS',     icon: 'ni-ssl' },
  { id: 'php',        label: 'PHP',           icon: 'ni-php' },
  { id: 'cron',       label: 'Cron Jobs',     icon: 'ni-cron' },
  { id: 'files',      label: 'File Manager',  icon: 'ni-files' },
  { id: 'stats',      label: 'Statistics',    icon: 'ni-stats' },
  { id: 'backups',    label: 'Backups',       icon: 'ni-backups' },
];

let _activePage = 'dashboard';

function renderNav() {
  const nav = document.getElementById('sidebar-nav');
  if (!nav) return;
  nav.innerHTML = navItems.map(n => `
    <a class="nav-item ${n.id === _activePage ? 'active' : ''}" href="#" onclick="userNav('${n.id}');return false">
      <svg width="18" height="18"><use href="/assets/img/nova-icons.svg#${n.icon}"/></svg>
      <span>${n.label}</span>
    </a>`).join('');
}

window.userNav = (page) => {
  _activePage = page;
  renderNav();
  const content = document.getElementById('page-content');
  if (!content) return;
  content.innerHTML = '<div class="loading">Loading…</div>';
  if (userPages[page]) userPages[page](content);
};

/* ── Boot ────────────────────────────────────────────────────────────────── */
document.addEventListener('DOMContentLoaded', async () => {
  const ok = await initUser();
  if (!ok) return;
  renderNav();
  window.userNav('dashboard');
});
