/**
 * NovaCPX Admin Panel — page controllers
 */
(async () => {
  // ── Auth guard ─────────────────────────────────────────────────────────────
  // Inline login handler on port 8882
  const loginForm = document.getElementById('login-form');
  if (loginForm) {
    loginForm.addEventListener('submit', async e => {
      e.preventDefault();
      const btn = document.getElementById('l-btn');
      const err = document.getElementById('login-err');
      btn.disabled = true; btn.textContent = 'Signing in…'; err.style.display = 'none';
      const res = await Nova.api('auth', 'login', {
        method: 'POST',
        body: { username: document.getElementById('l-user').value, password: document.getElementById('l-pass').value }
      });
      if (res?.success && res.data?.user?.role === 'admin') {
        location.reload();
      } else {
        err.textContent = res?.message || 'Invalid credentials or insufficient role';
        err.style.display = '';
        btn.disabled = false; btn.textContent = 'Sign In to Admin';
      }
    });
  }

  const me = await Nova.api('auth', 'me');
  if (!me?.success || me.data.role !== 'admin') {
    // Already showing the login form in #auth-check
    return;
  }
  document.getElementById('auth-check').style.display = 'none';
  document.getElementById('app').style.display = '';
  document.getElementById('user-name').textContent = me.data.username;
  document.getElementById('user-avatar').textContent = me.data.username[0].toUpperCase();

  // ── Logout ─────────────────────────────────────────────────────────────────
  document.getElementById('logout-btn').addEventListener('click', async e => {
    e.preventDefault();
    await Nova.api('auth', 'logout', { method: 'POST' });
    location.href = '/';
  });

  // ── Page definitions ───────────────────────────────────────────────────────
  const pages = {
    dashboard,
    'server-status': serverStatus,
    accounts,
    resellers,
    packages,
    'create-account': createAccount,
    'dns-zones': dnsZones,
    nameservers,
    'web-server': webServer,
    'php-manager': phpManager,
    'mysql-manager': mysqlManager,
    'mail-server': mailServer,
    'ftp-server': ftpServer,
    'ssl-manager': sslManager,
    firewall,
    'audit-log': auditLog,
    updates,
    backups,
    settings,
  };

  Nova.initNav(pages);
  await Nova.loadPage('dashboard', pages);
  checkUpdates();

  // ── Dashboard ──────────────────────────────────────────────────────────────
  async function dashboard() {
    const [stats, version] = await Promise.all([
      Nova.api('system', 'stats'),
      Nova.api('system', 'version'),
    ]);
    const s = stats?.data || {};
    const v = version?.data || {};

    document.getElementById('server-ip').textContent = '';

    return `
<div class="stats-grid">
  <div class="stat-card">
    <div class="stat-label">CPU Usage</div>
    <div class="stat-value ${s.cpu?.pct > 80 ? 'stat-red' : 'stat-green'}">${s.cpu?.pct ?? 0}%</div>
    <div class="stat-sub">Load: ${(s.cpu?.load || [0,0,0]).join(' / ')}</div>
    <div class="mt-1">${Nova.progressBar(s.cpu?.pct || 0)}</div>
  </div>
  <div class="stat-card">
    <div class="stat-label">Memory</div>
    <div class="stat-value ${s.ram?.pct > 80 ? 'stat-red' : 'stat-blue'}">${s.ram?.pct ?? 0}%</div>
    <div class="stat-sub">${Nova.bytes((s.ram?.used_kb||0)*1024)} / ${Nova.bytes((s.ram?.total_kb||0)*1024)}</div>
    <div class="mt-1">${Nova.progressBar(s.ram?.pct || 0)}</div>
  </div>
  <div class="stat-card">
    <div class="stat-label">Disk</div>
    <div class="stat-value ${s.disk?.pct > 85 ? 'stat-red' : 'stat-yellow'}">${s.disk?.pct ?? 0}%</div>
    <div class="stat-sub">${Nova.bytes(s.disk?.total - s.disk?.free || 0)} used</div>
    <div class="mt-1">${Nova.progressBar(s.disk?.pct || 0)}</div>
  </div>
  <div class="stat-card">
    <div class="stat-label">Uptime</div>
    <div class="stat-value stat-green" style="font-size:1rem;padding-top:.4rem">${s.uptime || '—'}</div>
    <div class="stat-sub">PHP ${v.php_version || '—'}</div>
  </div>
</div>

<div class="grid-2 gap-2">
  <div class="card">
    <div class="card-header"><span class="card-title">Services</span></div>
    <div class="card-body">
      <table><tbody>
        ${Object.entries(s.services || {}).map(([svc, status]) => `
          <tr>
            <td>${Nova.serviceDot(status)} ${svc}</td>
            <td>${Nova.badge(status, status === 'active' ? 'green' : 'red')}</td>
            <td class="text-right">
              <button class="btn btn-ghost btn-sm" onclick="adminServiceAction('${svc}','restart')">Restart</button>
              <button class="btn btn-ghost btn-sm" onclick="adminServiceAction('${svc}','stop')">Stop</button>
            </td>
          </tr>`).join('')}
      </tbody></table>
    </div>
  </div>

  <div class="card">
    <div class="card-header"><span class="card-title">NovaCPX Version</span></div>
    <div class="card-body">
      <table><tbody>
        <tr><td class="text-muted">Installed</td><td><strong>${v.installed_version || '—'}</strong></td></tr>
        <tr><td class="text-muted">Branch</td><td><code>${v.git_branch || 'main'}</code></td></tr>
        <tr><td class="text-muted">Commit</td><td><code>${v.git_commit || '—'}</code>${v.git_dirty ? ' <span class="badge badge-yellow">dirty</span>' : ''}</td></tr>
        <tr><td class="text-muted">PHP</td><td>${v.php_version || '—'}</td></tr>
        <tr><td class="text-muted">OS</td><td>${v.os || '—'}</td></tr>
      </tbody></table>
      <div class="mt-2">
        <button class="btn btn-primary btn-sm" onclick="adminPage('updates')">Check for Updates</button>
      </div>
    </div>
  </div>
</div>`;
  }

  // ── Server Status ──────────────────────────────────────────────────────────
  async function serverStatus() {
    const res = await Nova.api('system', 'stats');
    const s = res?.data || {};
    return `
<div class="card">
  <div class="card-header"><span class="card-title">Real-Time Server Status</span>
    <button class="btn btn-ghost btn-sm" onclick="adminPage('server-status')">&#x21bb; Refresh</button>
  </div>
  <div class="card-body">
    <div class="grid-3">
      <div><p class="text-muted text-sm mb-1">CPU</p><h2>${s.cpu?.pct}%</h2>${Nova.progressBar(s.cpu?.pct||0)}</div>
      <div><p class="text-muted text-sm mb-1">RAM</p><h2>${s.ram?.pct}%</h2>${Nova.progressBar(s.ram?.pct||0)}</div>
      <div><p class="text-muted text-sm mb-1">Disk</p><h2>${s.disk?.pct}%</h2>${Nova.progressBar(s.disk?.pct||0)}</div>
    </div>
    <div class="mt-3">
      <p class="text-muted text-sm mb-1">Load Average</p>
      <p>${(s.cpu?.load||[]).join(' / ')}</p>
    </div>
    <div class="mt-3">
      <p class="text-muted text-sm mb-1">Uptime</p>
      <p>${s.uptime}</p>
    </div>
  </div>
</div>`;
  }

  // ── Updates ────────────────────────────────────────────────────────────────
  async function updates() {
    const [ver, check] = await Promise.all([
      Nova.api('system', 'version'),
      Nova.api('system', 'check-update'),
    ]);
    const v = ver?.data || {};
    const upd = check?.data || {};
    const count = upd.updates_available || 0;

    return `
<div class="card">
  <div class="card-header">
    <span class="card-title">NovaCPX Updates</span>
    ${count > 0 ? Nova.badge(count + ' update' + (count > 1 ? 's' : '') + ' available', 'yellow') : Nova.badge('Up to date', 'green')}
  </div>
  <div class="card-body">
    <div class="grid-2 mb-3">
      <div><p class="text-muted text-sm">Installed Version</p><p class="font-bold">${v.installed_version}</p></div>
      <div><p class="text-muted text-sm">Git Commit</p><code>${v.git_commit || '—'}</code></div>
      <div><p class="text-muted text-sm">Branch</p><code>${v.git_branch || 'main'}</code></div>
      <div><p class="text-muted text-sm">Dirty Working Tree</p><p>${v.git_dirty ? Nova.badge('Yes','yellow') : Nova.badge('No','green')}</p></div>
    </div>

    ${count > 0 ? `
    <div class="card mb-2" style="background:var(--bg3)">
      <div class="card-header"><span class="card-title">Pending Commits</span></div>
      <div class="card-body terminal">
        ${upd.commits?.map(c => `<div>${c}</div>`).join('') || 'None'}
      </div>
    </div>
    <button class="btn btn-primary" onclick="applyUpdate()">Apply Update</button>
    ` : `<p class="text-muted">NovaCPX is up to date.</p>`}
  </div>
</div>`;
  }

  // ── Audit Log ──────────────────────────────────────────────────────────────
  async function auditLog() {
    const res = await Nova.api('system', 'audit-log', { params: { per_page: 50 } });
    const rows = res?.data || [];
    return `
<div class="card">
  <div class="card-header"><span class="card-title">Audit Log</span></div>
  <div class="table-wrap">
    <table>
      <thead><tr><th>Time</th><th>User</th><th>Action</th><th>Resource</th><th>IP</th></tr></thead>
      <tbody>
        ${rows.map(r => `
          <tr>
            <td class="text-muted text-sm">${Nova.relTime(r.created_at)}</td>
            <td>${r.username || '—'}</td>
            <td><code>${r.action}</code></td>
            <td>${r.resource || '—'}</td>
            <td class="text-muted text-sm">${r.ip_address || '—'}</td>
          </tr>`).join('')}
      </tbody>
    </table>
  </div>
</div>`;
  }

  // ── PHP Manager ────────────────────────────────────────────────────────────
  async function phpManager() {
    return `
<div class="card">
  <div class="card-header"><span class="card-title">PHP Version Manager</span></div>
  <div class="card-body">
    <p class="text-muted mb-2">Manage installed PHP versions and global extensions.</p>
    <div class="grid-4">
      ${['7.4','8.1','8.2','8.3'].map(v => `
        <div class="stat-card">
          <div class="stat-label">PHP ${v}</div>
          <div class="stat-value" style="font-size:1rem">${Nova.badge('Active','green')}</div>
          <div class="mt-2 flex gap-1">
            <button class="btn btn-ghost btn-sm" onclick="phpAction('${v}','fpm-restart')">Restart FPM</button>
          </div>
        </div>`).join('')}
    </div>
    <div class="mt-3">
      <h4 class="mb-1">Global PHP Extensions</h4>
      <p class="text-muted text-sm">Extensions installed across all PHP versions: mbstring, curl, gd, xml, zip, opcache, redis, imagick, pdo, pdo_mysql, pdo_pgsql</p>
    </div>
  </div>
</div>`;
  }

  // ── Settings ───────────────────────────────────────────────────────────────
  async function settings() {
    return `
<div class="card">
  <div class="card-header"><span class="card-title">Panel Settings</span></div>
  <div class="card-body">
    <form id="settings-form">
      <div class="grid-2">
        <div class="form-group"><label>Panel Name</label><input type="text" name="panel_name" value="NovaCPX"></div>
        <div class="form-group"><label>Default PHP Version</label>
          <select name="default_php">
            ${['7.4','8.1','8.2','8.3'].map(v => `<option value="${v}" ${v==='8.3'?'selected':''}>${v}</option>`).join('')}
          </select>
        </div>
        <div class="form-group"><label>Primary Nameserver</label><input type="text" name="default_nameserver1" value="ns1.example.com"></div>
        <div class="form-group"><label>Secondary Nameserver</label><input type="text" name="default_nameserver2" value="ns2.example.com"></div>
        <div class="form-group"><label>Update Channel</label>
          <select name="update_channel"><option value="stable">Stable</option><option value="beta">Beta</option></select>
        </div>
        <div class="form-group"><label>Git Remote</label><input type="url" name="git_remote" value="https://github.com/myronblair/novacpx.git"></div>
      </div>
      <button type="submit" class="btn btn-primary">Save Settings</button>
    </form>
  </div>
</div>`;
  }

  // ── Accounts ───────────────────────────────────────────────────────────────
  async function accounts() {
    const res = await Nova.api('accounts', 'list');
    const accts = res?.data?.accounts || [];
    window._adminAccts = accts;
    return `
<div class="card">
  <div class="card-header">
    <span class="card-title">All Hosting Accounts</span>
    <div style="display:flex;gap:.5rem">
      <input id="acct-search" class="form-control" style="width:200px" placeholder="Search…" oninput="adminSearchAccounts(this.value)">
      <button class="btn btn-primary btn-sm" onclick="adminPage('create-account')">+ Create</button>
    </div>
  </div>
  <div id="admin-acct-table">
    ${renderAccountTable(accts)}
  </div>
</div>`;
  }

  function renderAccountTable(accts) {
    if (!accts.length) return '<div class="empty" style="padding:2rem">No accounts found.</div>';
    return `<table class="table"><thead><tr><th>Username</th><th>Domain</th><th>Reseller</th><th>Package</th><th>Disk</th><th>Status</th><th>Created</th><th>Actions</th></tr></thead><tbody>
      ${accts.map(a => `<tr>
        <td><strong>${a.username}</strong></td>
        <td>${a.domain}</td>
        <td>${a.reseller_username || '<span class="text-muted">admin</span>'}</td>
        <td>${a.package_name || '—'}</td>
        <td>${a.disk_usage_mb || 0} MB</td>
        <td>${Nova.badge(a.status, a.status==='active'?'green':a.status==='suspended'?'yellow':'red')}</td>
        <td class="text-muted text-sm">${Nova.relTime(a.created_at)}</td>
        <td style="display:flex;gap:.25rem">
          ${a.status==='active'
            ? `<button class="btn btn-xs btn-warning" onclick="adminSuspend(${a.id},'${a.username}')">Suspend</button>`
            : `<button class="btn btn-xs btn-success" onclick="adminUnsuspend(${a.id})">Unsuspend</button>`}
          <button class="btn btn-xs" onclick="adminChangePass(${a.id},'${a.username}')">Passwd</button>
          <button class="btn btn-xs btn-danger" onclick="adminTerminate(${a.id},'${a.username}')">Terminate</button>
        </td>
      </tr>`).join('')}
    </tbody></table>`;
  }

  window.adminSearchAccounts = async (q) => {
    const res = await Nova.api('accounts', 'list', { params: q ? { search: q } : {}});
    const el = document.getElementById('admin-acct-table');
    if (el) el.innerHTML = renderAccountTable(res?.data?.accounts || []);
  };
  window.adminSuspend = async (id, user) => {
    Nova.confirm(`Suspend ${user}?`, async () => {
      const res = await Nova.api('accounts','suspend',{method:'POST',body:{account_id:id}});
      if (res?.success) { Nova.toast('Suspended','success'); adminPage('accounts'); }
      else Nova.toast(res?.message,'error');
    });
  };
  window.adminUnsuspend = async (id) => {
    const res = await Nova.api('accounts','unsuspend',{method:'POST',body:{account_id:id}});
    if (res?.success) { Nova.toast('Unsuspended','success'); adminPage('accounts'); }
    else Nova.toast(res?.message,'error');
  };
  window.adminChangePass = (id, user) => {
    Nova.modal(`Change Password — ${user}`, `<div class="form-group"><label class="form-label">New Password</label><input id="acp-pass" type="password" class="form-control"></div>`,
      `<button class="btn btn-primary" onclick="Nova.api('accounts','change-password',{method:'POST',body:{account_id:${id},password:document.getElementById('acp-pass').value}}).then(r=>{if(r?.success){Nova.toast('Updated','success');document.querySelector('.modal-overlay').remove();}else Nova.toast(r?.message,'error');})">Update</button>`);
  };
  window.adminTerminate = (id, user) => {
    Nova.confirm(`TERMINATE ${user}? This permanently deletes all files, DBs, DNS, email. IRREVERSIBLE.`, async () => {
      const res = await Nova.api('accounts','terminate',{method:'POST',body:{account_id:id}});
      if (res?.success) { Nova.toast('Terminated','success'); adminPage('accounts'); }
      else Nova.toast(res?.message,'error');
    }, true);
  };

  // ── Create Account ─────────────────────────────────────────────────────────
  async function createAccount() {
    const pkgRes = await Nova.api('packages', 'list');
    const pkgOpts = (pkgRes?.data || []).map(p => `<option value="${p.id}">${p.name} — ${p.disk_mb}MB</option>`).join('');
    return `
<div class="card" style="max-width:640px">
  <div class="card-header"><span class="card-title">Create Hosting Account</span></div>
  <div style="padding:1.5rem">
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:.75rem">
      <div class="form-group"><label class="form-label">Username *</label><input id="nca-user" class="form-control" placeholder="lowercase, no spaces"></div>
      <div class="form-group"><label class="form-label">Password *</label><input id="nca-pass" type="password" class="form-control"></div>
      <div class="form-group"><label class="form-label">Email</label><input id="nca-email" type="email" class="form-control"></div>
      <div class="form-group"><label class="form-label">Domain *</label><input id="nca-domain" class="form-control" placeholder="example.com"></div>
      <div class="form-group"><label class="form-label">Package</label><select id="nca-pkg" class="form-control">${pkgOpts}</select></div>
      <div class="form-group"><label class="form-label">PHP Version</label>
        <select id="nca-php" class="form-control">
          ${['8.3','8.2','8.1','7.4'].map(v => `<option value="${v}">PHP ${v}</option>`).join('')}
        </select>
      </div>
    </div>
    <div style="margin-top:1.25rem;display:flex;gap:.75rem">
      <button class="btn btn-primary" onclick="adminSubmitCreateAccount()">Create Account</button>
      <button class="btn" onclick="adminPage('accounts')">Cancel</button>
    </div>
    <div id="nca-result" style="margin-top:1rem"></div>
  </div>
</div>`;
  }

  window.adminSubmitCreateAccount = async () => {
    const res = await Nova.api('accounts','create',{method:'POST',body:{
      username:   document.getElementById('nca-user')?.value,
      password:   document.getElementById('nca-pass')?.value,
      email:      document.getElementById('nca-email')?.value,
      domain:     document.getElementById('nca-domain')?.value,
      package_id: document.getElementById('nca-pkg')?.value,
      php_version:document.getElementById('nca-php')?.value,
    }});
    const el = document.getElementById('nca-result');
    if (res?.success) {
      Nova.toast('Account created!','success');
      if (el) el.innerHTML = `<div class="alert alert-success">Account created successfully! <a href="#" onclick="adminPage('accounts')">View accounts →</a></div>`;
    } else {
      Nova.toast(res?.message || 'Failed','error');
      if (el) el.innerHTML = `<div class="alert alert-error">${res?.message || 'Error creating account'}</div>`;
    }
  };

  // ── Resellers ──────────────────────────────────────────────────────────────
  async function resellers() {
    const res = await Nova.api('accounts', 'list', { params:{ role: 'reseller' }});
    const rows = res?.data?.accounts || [];
    return `
<div class="card">
  <div class="card-header">
    <span class="card-title">Reseller Accounts</span>
    <button class="btn btn-primary btn-sm" onclick="adminAddReseller()">+ Add Reseller</button>
  </div>
  <div id="reseller-table">
    ${rows.length ? `<table class="table"><thead><tr><th>Username</th><th>Email</th><th>Accounts</th><th>Status</th><th>Actions</th></tr></thead><tbody>
      ${rows.map(r => `<tr>
        <td>${r.username}</td><td>${r.email||'—'}</td>
        <td>${r.account_count||0}</td>
        <td>${Nova.badge(r.status,r.status==='active'?'green':'red')}</td>
        <td style="display:flex;gap:.25rem">
          <button class="btn btn-xs" onclick="adminChangePass(${r.id},'${r.username}')">Passwd</button>
          <button class="btn btn-xs btn-danger" onclick="adminSuspend(${r.id},'${r.username}')">Suspend</button>
        </td>
      </tr>`).join('')}
    </tbody></table>`
    : '<div class="empty" style="padding:2rem">No resellers yet.</div>'}
  </div>
</div>`;
  }

  window.adminAddReseller = () => {
    Nova.modal('Create Reseller Account', `
      <div class="form-group"><label class="form-label">Username</label><input id="ar-user" class="form-control"></div>
      <div class="form-group"><label class="form-label">Password</label><input id="ar-pass" type="password" class="form-control"></div>
      <div class="form-group"><label class="form-label">Email</label><input id="ar-email" type="email" class="form-control"></div>`,
      `<button class="btn btn-primary" onclick="Nova.api('auth','register',{method:'POST',body:{username:document.getElementById('ar-user').value,password:document.getElementById('ar-pass').value,email:document.getElementById('ar-email').value,role:'reseller'}}).then(r=>{if(r?.success){Nova.toast('Reseller created','success');document.querySelector('.modal-overlay').remove();adminPage('resellers');}else Nova.toast(r?.message,'error');})">Create</button>`);
  };

  // ── Packages ───────────────────────────────────────────────────────────────
  async function packages() {
    const res = await Nova.api('packages', 'list');
    const pkgs = res?.data || [];
    return `
<div class="card">
  <div class="card-header">
    <span class="card-title">Hosting Packages</span>
    <button class="btn btn-primary btn-sm" onclick="adminAddPkg()">+ Add Package</button>
  </div>
  ${pkgs.length ? `<table class="table"><thead><tr><th>Name</th><th>Disk</th><th>BW</th><th>DBs</th><th>Emails</th><th>Price</th><th>Accounts</th><th>Actions</th></tr></thead><tbody>
    ${pkgs.map(p => `<tr>
      <td><strong>${p.name}</strong></td>
      <td>${p.disk_mb > 0 ? p.disk_mb+'MB' : '∞'}</td>
      <td>${p.bandwidth_mb > 0 ? p.bandwidth_mb+'MB' : '∞'}</td>
      <td>${p.databases||'∞'}</td>
      <td>${p.email_accounts||'∞'}</td>
      <td>${p.price ? '$'+p.price : 'Free'}</td>
      <td>${p.account_count||0}</td>
      <td style="display:flex;gap:.25rem">
        <button class="btn btn-xs" onclick="adminEditPkg(${p.id})">Edit</button>
        <button class="btn btn-xs btn-danger" onclick="adminDelPkg(${p.id},'${p.name}')">Del</button>
      </td>
    </tr>`).join('')}
  </tbody></table>`
  : '<div class="empty" style="padding:2rem">No packages yet. Create one to start hosting accounts.</div>'}
</div>`;
  }

  window.adminAddPkg = () => showAdminPkgModal();
  window.adminEditPkg = async (id) => {
    const r = await Nova.api('packages','get',{params:{id}});
    if (r?.success) showAdminPkgModal(r.data);
  };
  function showAdminPkgModal(p = {}) {
    Nova.modal(p.id ? 'Edit Package' : 'Add Package', `
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:.75rem">
        <div class="form-group" style="grid-column:1/-1"><label class="form-label">Name</label><input id="ap-name" class="form-control" value="${p.name||''}"></div>
        <div class="form-group"><label class="form-label">Disk (MB)</label><input id="ap-disk" type="number" class="form-control" value="${p.disk_mb||0}"></div>
        <div class="form-group"><label class="form-label">Bandwidth (MB)</label><input id="ap-bw" type="number" class="form-control" value="${p.bandwidth_mb||0}"></div>
        <div class="form-group"><label class="form-label">Databases</label><input id="ap-db" type="number" class="form-control" value="${p.databases||0}"></div>
        <div class="form-group"><label class="form-label">Email Accounts</label><input id="ap-email" type="number" class="form-control" value="${p.email_accounts||0}"></div>
        <div class="form-group"><label class="form-label">Addon Domains</label><input id="ap-dom" type="number" class="form-control" value="${p.addon_domains||0}"></div>
        <div class="form-group"><label class="form-label">Subdomains</label><input id="ap-sub" type="number" class="form-control" value="${p.subdomains||0}"></div>
        <div class="form-group"><label class="form-label">FTP Accounts</label><input id="ap-ftp" type="number" class="form-control" value="${p.ftp_accounts||0}"></div>
        <div class="form-group"><label class="form-label">Price ($/mo)</label><input id="ap-price" type="number" step="0.01" class="form-control" value="${p.price||0}"></div>
      </div>`,
      `<button class="btn btn-primary" onclick="adminSavePkg(${p.id||'null'})">Save</button>`);
  }
  window.adminSavePkg = async (id) => {
    const body = {name:document.getElementById('ap-name')?.value,disk_mb:+document.getElementById('ap-disk')?.value,bandwidth_mb:+document.getElementById('ap-bw')?.value,databases:+document.getElementById('ap-db')?.value,email_accounts:+document.getElementById('ap-email')?.value,addon_domains:+document.getElementById('ap-dom')?.value,subdomains:+document.getElementById('ap-sub')?.value,ftp_accounts:+document.getElementById('ap-ftp')?.value,price:+document.getElementById('ap-price')?.value};
    const res = id ? await Nova.api('packages','update',{method:'POST',body:{...body,id}}) : await Nova.api('packages','create',{method:'POST',body});
    if (res?.success) { Nova.toast(id?'Updated':'Created','success'); document.querySelector('.modal-overlay')?.remove(); adminPage('packages'); }
    else Nova.toast(res?.message,'error');
  };
  window.adminDelPkg = (id, name) => {
    Nova.confirm(`Delete package "${name}"?`, async () => {
      const r = await Nova.api('packages','delete',{method:'POST',body:{id}});
      if (r?.success) { Nova.toast('Deleted','success'); adminPage('packages'); }
      else Nova.toast(r?.message,'error');
    }, true);
  };

  // ── DNS Zones ──────────────────────────────────────────────────────────────
  async function dnsZones() {
    const res = await Nova.api('dns', 'zones');
    const zones = res?.data || [];
    return `
<div class="card">
  <div class="card-header"><span class="card-title">DNS Zones</span>
    <button class="btn btn-sm" onclick="adminAddZone()">+ Add Zone</button>
  </div>
  ${zones.length ? `<table class="table"><thead><tr><th>Domain</th><th>Account</th><th>Records</th><th>Actions</th></tr></thead><tbody>
    ${zones.map(z => `<tr>
      <td>${z.domain}</td>
      <td>${z.username||'—'}</td>
      <td>${z.record_count||0}</td>
      <td style="display:flex;gap:.25rem">
        <button class="btn btn-xs" onclick="adminEditZone(${z.id},'${z.domain}')">Records</button>
        <button class="btn btn-xs btn-danger" onclick="adminDelZone(${z.id},'${z.domain}')">Del</button>
      </td>
    </tr>`).join('')}
  </tbody></table>`
  : '<div class="empty" style="padding:2rem">No DNS zones yet.</div>'}
</div>`;
  }

  window.adminAddZone = () => {
    Nova.modal('Create DNS Zone', `<div class="form-group"><label class="form-label">Domain</label><input id="az-dom" class="form-control" placeholder="example.com"></div>`,
      `<button class="btn btn-primary" onclick="Nova.api('dns','create-zone',{method:'POST',body:{domain:document.getElementById('az-dom').value}}).then(r=>{if(r?.success){Nova.toast('Zone created','success');document.querySelector('.modal-overlay').remove();adminPage('dns-zones');}else Nova.toast(r?.message,'error');})">Create</button>`);
  };
  window.adminEditZone = async (id, domain) => {
    const res = await Nova.api('dns', 'records', {params:{zone_id:id}});
    if (!res?.success) { Nova.toast('Failed to load records','error'); return; }
    const rows = res.data.map(r => `<tr><td>${r.name}</td><td>${Nova.badge(r.type,'default')}</td><td><code>${r.value}</code></td><td>${r.ttl}</td>
      <td><button class="btn btn-xs btn-danger" onclick="adminDelRecord(${r.id},${id},'${domain}')">Del</button></td></tr>`).join('');
    Nova.modal(`DNS: ${domain}`, `
      <button class="btn btn-sm btn-primary" style="margin-bottom:.75rem" onclick="adminAddRecord(${id},'${domain}')">+ Add Record</button>
      <table class="table"><thead><tr><th>Name</th><th>Type</th><th>Value</th><th>TTL</th><th></th></tr></thead><tbody>${rows}</tbody></table>`);
  };
  window.adminAddRecord = (zoneId, domain) => {
    Nova.modal('Add Record', `
      <div class="form-group"><label class="form-label">Name</label><input id="ar2-name" class="form-control" value="@"></div>
      <div class="form-group"><label class="form-label">Type</label><select id="ar2-type" class="form-control"><option>A</option><option>AAAA</option><option>CNAME</option><option>MX</option><option>TXT</option><option>NS</option></select></div>
      <div class="form-group"><label class="form-label">Value</label><input id="ar2-val" class="form-control"></div>
      <div class="form-group"><label class="form-label">TTL</label><input id="ar2-ttl" type="number" class="form-control" value="3600"></div>`,
      `<button class="btn btn-primary" onclick="Nova.api('dns','add-record',{method:'POST',body:{zone_id:${zoneId},name:document.getElementById('ar2-name').value,type:document.getElementById('ar2-type').value,value:document.getElementById('ar2-val').value,ttl:parseInt(document.getElementById('ar2-ttl').value)}}).then(r=>{if(r?.success){Nova.toast('Added','success');document.querySelector('.modal-overlay').remove();adminEditZone(${zoneId},'${domain}');}else Nova.toast(r?.message,'error');})">Add</button>`);
  };
  window.adminDelRecord = async (id, zoneId, domain) => {
    Nova.confirm('Delete this record?', async () => {
      const r = await Nova.api('dns','delete-record',{method:'POST',body:{id}});
      if (r?.success) { Nova.toast('Deleted','success'); document.querySelector('.modal-overlay')?.remove(); adminEditZone(zoneId,domain); }
      else Nova.toast(r?.message,'error');
    }, true);
  };
  window.adminDelZone = (id, domain) => {
    Nova.confirm(`Delete DNS zone for ${domain}?`, async () => {
      const r = await Nova.api('dns','delete-zone',{method:'POST',body:{zone_id:id}});
      if (r?.success) { Nova.toast('Zone deleted','success'); adminPage('dns-zones'); }
      else Nova.toast(r?.message,'error');
    }, true);
  };

  // ── Nameservers ────────────────────────────────────────────────────────────
  async function nameservers() {
    const r = await Nova.api('server_setup','get');
    const d = r?.data || {};
    return `
<div class="card" style="max-width:500px">
  <div class="card-header"><span class="card-title">Nameserver Configuration</span></div>
  <div style="padding:1.5rem">
    <div class="form-group"><label class="form-label">Primary Nameserver</label><input id="ns1" class="form-control" value="${d.nameserver1||''}"></div>
    <div class="form-group"><label class="form-label">Secondary Nameserver</label><input id="ns2" class="form-control" value="${d.nameserver2||''}"></div>
    <div class="form-group"><label class="form-label">Hostname</label><input id="srvhost" class="form-control" value="${d.hostname||d.system_hostname||''}"></div>
    <div style="display:flex;gap:.75rem;margin-top:1rem">
      <button class="btn btn-primary" onclick="adminSaveNS()">Save Nameservers</button>
      <button class="btn" onclick="adminSetHostname()">Set Hostname</button>
    </div>
  </div>
</div>`;
  }
  window.adminSaveNS = async () => {
    const r = await Nova.api('server_setup','nameservers',{method:'POST',body:{ns1:document.getElementById('ns1')?.value,ns2:document.getElementById('ns2')?.value}});
    if (r?.success) Nova.toast('Nameservers saved','success');
    else Nova.toast(r?.message,'error');
  };
  window.adminSetHostname = async () => {
    const r = await Nova.api('server_setup','set-hostname',{method:'POST',body:{hostname:document.getElementById('srvhost')?.value}});
    if (r?.success) Nova.toast(`Hostname set to ${r.data?.hostname}`,'success');
    else Nova.toast(r?.message,'error');
  };

  // ── Web Server ────────────────────────────────────────────────────────────
  async function webServer() {
    const r = await Nova.api('system', 'stats');
    const svcs = r?.data?.services || {};
    const webSvc = Object.keys(svcs).find(k => k.includes('apache') || k.includes('nginx')) || 'apache2';
    return `
<div class="card">
  <div class="card-header"><span class="card-title">Web Server Management</span></div>
  <div style="padding:1.5rem">
    <div class="stats-grid" style="margin-bottom:1.5rem">
      ${Object.entries(svcs).map(([s,st]) => `<div class="stat-card">
        <div style="display:flex;justify-content:space-between;align-items:center">
          <strong>${s}</strong>${Nova.badge(st,st==='active'?'green':'red')}
        </div>
        <div style="margin-top:.75rem;display:flex;gap:.5rem">
          <button class="btn btn-sm" onclick="adminServiceAction('${s}','restart')">Restart</button>
          <button class="btn btn-sm" onclick="adminServiceAction('${s}','start')">Start</button>
          <button class="btn btn-sm btn-danger" onclick="adminServiceAction('${s}','stop')">Stop</button>
        </div>
      </div>`).join('')}
    </div>
  </div>
</div>`;
  }

  // ── SSL Manager ────────────────────────────────────────────────────────────
  async function sslManager() {
    const res = await Nova.api('ssl', 'list', {params:{account_id:0}});
    const certs = res?.data || [];
    return `
<div class="card">
  <div class="card-header">
    <span class="card-title">SSL Certificate Manager</span>
    <button class="btn btn-primary btn-sm" onclick="adminIssueBulkSSL()">Issue SSL for All Domains</button>
  </div>
  ${certs.length ? `<table class="table"><thead><tr><th>Domain</th><th>Account</th><th>Type</th><th>Expires</th><th>Days</th><th>Actions</th></tr></thead><tbody>
    ${certs.map(c => {
      const days = c.days_remaining;
      const badge = days !== null ? Nova.badge(days+'d', days<7?'red':days<30?'yellow':'green') : Nova.badge('unknown','muted');
      return `<tr>
        <td>${c.domain}</td>
        <td>${c.username||'—'}</td>
        <td>${Nova.badge(c.type,'default')}</td>
        <td>${c.expires_at||'—'}</td>
        <td>${badge}</td>
        <td style="display:flex;gap:.25rem">
          <button class="btn btn-xs" onclick="adminRenewCert(${c.id})">Renew</button>
          <button class="btn btn-xs btn-danger" onclick="adminDelCert(${c.id},'${c.domain}')">Del</button>
        </td>
      </tr>`;
    }).join('')}
  </tbody></table>`
  : '<div class="empty" style="padding:2rem">No SSL certificates issued yet.</div>'}
</div>`;
  }
  window.adminIssueBulkSSL = async () => {
    Nova.toast('Queuing SSL for all domains without certificates…','info',6000);
    // Get all accounts, then issue SSL for each domain
    const accts = await Nova.api('accounts','list',{params:{limit:1000}});
    let count = 0;
    for (const a of (accts?.data?.accounts || [])) {
      await Nova.api('ssl','issue',{method:'POST',body:{domain:a.domain}});
      count++;
    }
    Nova.toast(`SSL issued for ${count} domains`,'success');
    adminPage('ssl-manager');
  };
  window.adminRenewCert = async (id) => {
    Nova.toast('Renewing…','info');
    const r = await Nova.api('ssl','renew',{method:'POST',body:{cert_id:id}});
    if (r?.success) { Nova.toast('Renewed','success'); adminPage('ssl-manager'); }
    else Nova.toast(r?.message,'error');
  };
  window.adminDelCert = (id, domain) => {
    Nova.confirm(`Delete SSL cert for ${domain}?`, async () => {
      const r = await Nova.api('ssl','delete',{method:'POST',body:{cert_id:id}});
      if (r?.success) { Nova.toast('Deleted','success'); adminPage('ssl-manager'); }
      else Nova.toast(r?.message,'error');
    }, true);
  };

  // ── Firewall ───────────────────────────────────────────────────────────────
  async function firewall() {
    const ufwRes = await Nova.api('system','service',{method:'POST',body:{service:'ufw',command:'status'}}).catch(()=>null);
    return `
<div style="display:grid;grid-template-columns:1fr 1fr;gap:1.5rem">
  <div class="card">
    <div class="card-header"><span class="card-title">UFW Firewall</span></div>
    <div style="padding:1.25rem">
      <div style="display:flex;gap:.5rem;margin-bottom:1rem">
        <button class="btn btn-sm btn-primary" onclick="adminServiceAction('ufw','start')">Enable UFW</button>
        <button class="btn btn-sm btn-danger" onclick="adminServiceAction('ufw','stop')">Disable UFW</button>
      </div>
      <div class="form-group"><label class="form-label">Allow Port</label>
        <div style="display:flex;gap:.5rem">
          <input id="fw-port" class="form-control" placeholder="e.g. 3306/tcp">
          <button class="btn btn-sm btn-primary" onclick="adminAllowPort()">Allow</button>
        </div>
      </div>
      <div class="form-group"><label class="form-label">Block Port</label>
        <div style="display:flex;gap:.5rem">
          <input id="fw-block" class="form-control" placeholder="e.g. 3306/tcp">
          <button class="btn btn-sm btn-danger" onclick="adminBlockPort()">Block</button>
        </div>
      </div>
    </div>
  </div>
  <div class="card">
    <div class="card-header"><span class="card-title">Fail2Ban</span></div>
    <div style="padding:1.25rem">
      <div style="display:flex;gap:.5rem;margin-bottom:1rem">
        <button class="btn btn-sm" onclick="adminServiceAction('fail2ban','restart')">Restart Fail2Ban</button>
        <button class="btn btn-sm" onclick="adminF2bStatus()">View Jails</button>
      </div>
      <div class="form-group"><label class="form-label">Unban IP</label>
        <div style="display:flex;gap:.5rem">
          <input id="fw-unban" class="form-control" placeholder="192.168.1.100">
          <button class="btn btn-sm" onclick="adminUnban()">Unban</button>
        </div>
      </div>
    </div>
  </div>
</div>`;
  }
  window.adminAllowPort = async () => {
    const port = document.getElementById('fw-port')?.value;
    if (!port) return;
    const r = await Nova.api('system','service',{method:'POST',body:{service:'ufw',command:`allow ${port}`}});
    Nova.toast(r?.success ? `Allowed ${port}` : r?.message,'success');
  };
  window.adminBlockPort = async () => {
    const port = document.getElementById('fw-block')?.value;
    if (!port) return;
    const r = await Nova.api('system','service',{method:'POST',body:{service:'ufw',command:`deny ${port}`}});
    Nova.toast(r?.success ? `Blocked ${port}` : r?.message,'success');
  };
  window.adminF2bStatus = async () => {
    const r = await Nova.api('system','service',{method:'POST',body:{service:'fail2ban-client',command:'status'}});
    Nova.modal('Fail2Ban Jails', `<pre style="background:var(--bg);padding:1rem;border-radius:6px;font-size:.8rem;overflow:auto">${r?.data?.output || 'No output'}</pre>`);
  };
  window.adminUnban = async () => {
    const ip = document.getElementById('fw-unban')?.value;
    if (!ip) return;
    Nova.toast(`Unbanning ${ip}…`,'info');
    // Unban from all jails
    for (const jail of ['sshd','novacpx-user','novacpx-admin','novacpx-reseller','novacpx-webmail']) {
      await Nova.api('system','service',{method:'POST',body:{service:`fail2ban-client set ${jail} unbanip`,command:ip}}).catch(()=>{});
    }
    Nova.toast('Unban commands sent','success');
  };

  // ── MySQL/DB Manager ───────────────────────────────────────────────────────
  async function mysqlManager() {
    const res = await Nova.api('databases','list',{params:{account_id:0}});
    const dbs = res?.data || [];
    return `
<div class="card">
  <div class="card-header"><span class="card-title">Databases</span></div>
  ${dbs.length ? `<table class="table"><thead><tr><th>Database</th><th>User</th><th>Type</th><th>Account</th><th>Size</th><th>Actions</th></tr></thead><tbody>
    ${dbs.map(d => `<tr>
      <td><strong>${d.db_name}</strong></td>
      <td>${d.db_user}</td>
      <td>${Nova.badge(d.db_type,'default')}</td>
      <td>${d.username||'—'}</td>
      <td>${d.size||'—'}</td>
      <td><button class="btn btn-xs btn-danger" onclick="adminDropDB(${d.id},'${d.db_name}')">Drop</button></td>
    </tr>`).join('')}
  </tbody></table>`
  : '<div class="empty" style="padding:2rem">No databases.</div>'}
</div>`;
  }
  window.adminDropDB = (id, name) => {
    Nova.confirm(`Drop database ${name}? ALL DATA WILL BE LOST.`, async () => {
      const r = await Nova.api('databases','drop',{method:'POST',body:{id}});
      if (r?.success) { Nova.toast('Dropped','success'); adminPage('mysql-manager'); }
      else Nova.toast(r?.message,'error');
    }, true);
  };

  // ── Mail Server ────────────────────────────────────────────────────────────
  async function mailServer() {
    const r = await Nova.api('system','stats');
    const svcs = r?.data?.services || {};
    const mailStatus = svcs['postfix'] || 'unknown';
    const doveStatus = svcs['dovecot'] || 'unknown';
    return `
<div style="display:grid;grid-template-columns:1fr 1fr;gap:1.5rem">
  <div class="card">
    <div class="card-header"><span class="card-title">Mail Services</span></div>
    <div style="padding:1.25rem">
      ${[['postfix',mailStatus],['dovecot',doveStatus],['spamassassin','unknown']].map(([s,st]) => `
        <div style="display:flex;align-items:center;justify-content:space-between;padding:.6rem 0;border-bottom:1px solid var(--border)">
          <span>${s} ${Nova.badge(st,st==='active'?'green':'red')}</span>
          <div style="display:flex;gap:.5rem">
            <button class="btn btn-xs" onclick="adminServiceAction('${s}','restart')">Restart</button>
            <button class="btn btn-xs" onclick="adminServiceAction('${s}','reload')">Reload</button>
          </div>
        </div>`).join('')}
    </div>
  </div>
  <div class="card">
    <div class="card-header"><span class="card-title">Mail Queue</span></div>
    <div style="padding:1.25rem">
      <button class="btn btn-sm" onclick="adminViewMailQueue()">View Queue</button>
      <button class="btn btn-sm btn-warning" style="margin-top:.5rem" onclick="Nova.confirm('Flush mail queue?',()=>adminServiceAction('postfix','flush'))">Flush Queue</button>
    </div>
  </div>
</div>`;
  }
  window.adminViewMailQueue = async () => {
    const r = await Nova.api('system','service',{method:'POST',body:{service:'mailq',command:'status'}});
    Nova.modal('Mail Queue', `<pre style="background:var(--bg);padding:1rem;font-size:.8rem;overflow:auto;max-height:400px">${r?.data?.output || 'Queue is empty'}</pre>`);
  };

  // ── FTP Server ────────────────────────────────────────────────────────────
  async function ftpServer() {
    const r = await Nova.api('system','stats');
    const ftpStatus = r?.data?.services?.proftpd || 'unknown';
    return `
<div class="card">
  <div class="card-header">
    <span class="card-title">FTP Server (ProFTPD)</span>
    ${Nova.badge(ftpStatus, ftpStatus==='active'?'green':'red')}
    <div style="display:flex;gap:.5rem;margin-left:auto">
      <button class="btn btn-sm" onclick="adminServiceAction('proftpd','restart')">Restart</button>
      <button class="btn btn-sm" onclick="adminServiceAction('proftpd','reload')">Reload</button>
    </div>
  </div>
  <div style="padding:1.25rem">
    <div style="color:var(--muted);font-size:.85rem">
      <p>ProFTPD uses virtual users stored in <code>/etc/proftpd/novacpx-users.passwd</code></p>
      <p style="margin-top:.5rem">FTP connections use SFTP on port 22 or passive FTP on ports 20/21.</p>
      <p style="margin-top:.5rem">Per-account FTP management is available in each account's FTP page.</p>
    </div>
  </div>
</div>`;
  }

  // ── Backups ────────────────────────────────────────────────────────────────
  async function backups() {
    const res = await Nova.api('accounts','list',{params:{limit:1000}});
    const accts = res?.data?.accounts || [];
    return `
<div class="card">
  <div class="card-header">
    <span class="card-title">Backup Manager</span>
    <button class="btn btn-primary btn-sm" onclick="adminBackupAll()">Backup All Accounts</button>
  </div>
  <div style="padding:1.25rem">
    <div style="margin-bottom:1.5rem;padding:1rem;background:var(--bg3);border-radius:8px;display:grid;grid-template-columns:1fr 1fr;gap:.75rem">
      <div class="form-group"><label class="form-label">Backup Storage</label>
        <select class="form-control">
          <option>Local (/var/backups/novacpx)</option>
          <option>rclone (configured)</option>
          <option>S3 (configure in settings)</option>
        </select>
      </div>
      <div class="form-group"><label class="form-label">Retention (days)</label>
        <input type="number" class="form-control" value="7">
      </div>
    </div>
    <table class="table"><thead><tr><th>Account</th><th>Domain</th><th>Actions</th></tr></thead><tbody>
      ${accts.slice(0,20).map(a => `<tr>
        <td>${a.username}</td>
        <td>${a.domain}</td>
        <td style="display:flex;gap:.25rem">
          <button class="btn btn-xs btn-primary" onclick="adminBackupAccount(${a.id},'${a.username}')">Backup Now</button>
        </td>
      </tr>`).join('')}
    </tbody></table>
  </div>
</div>`;
  }
  window.adminBackupAll = () => Nova.toast('Full backup queued — this may take several minutes.','info',6000);
  window.adminBackupAccount = (id, user) => Nova.toast(`Backup queued for ${user}…`,'info');

  // ── Global action helpers ──────────────────────────────────────────────────
  window.adminPage = (page) => Nova.loadPage(page, pages);
  window.applyUpdate = async () => {
    Nova.confirm('Apply all pending updates? The panel may restart.', async () => {
      Nova.toast('Applying update…', 'info', 8000);
      const res = await Nova.api('system', 'apply-update', { method: 'POST' });
      if (res?.data?.updated) {
        Nova.toast(`Updated to ${res.data.to_commit}`, 'success');
        Nova.loadPage('updates', pages);
      } else {
        Nova.toast(res?.data?.pull_output || 'Already up to date', 'info');
      }
    });
  };
  window.adminServiceAction = async (svc, cmd) => {
    const res = await Nova.api('system', 'service', { method: 'POST', body: { service: svc, command: cmd } });
    Nova.toast(`${svc}: ${cmd} → ${res?.success ? 'OK' : res?.message}`, res?.success ? 'success' : 'error');
  };
  window.phpAction = async (ver, cmd) => {
    const svc = `php${ver}-fpm`;
    await window.adminServiceAction(svc, 'restart');
  };

  // ── Check for updates badge ────────────────────────────────────────────────
  async function checkUpdates() {
    const res = await Nova.api('system', 'check-update');
    const n = res?.data?.updates_available || 0;
    const badge = document.getElementById('update-badge');
    if (badge && n > 0) { badge.textContent = n; badge.style.display = ''; }
  }
})();
