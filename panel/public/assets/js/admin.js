/**
 * NovaCPX Admin Panel — page controllers
 */
(async () => {
  // ── Auth guard ─────────────────────────────────────────────────────────────
  // Inline login handler on port 8882
  let _loginCredentials = null;
  const loginForm = document.getElementById('login-form');
  if (loginForm) {
    loginForm.addEventListener('submit', async e => {
      e.preventDefault();
      const btn = document.getElementById('l-btn');
      const err = document.getElementById('login-err');
      btn.disabled = true; err.style.display = 'none';

      // Step 2: TOTP code entry
      const totpInput = document.getElementById('l-totp');
      if (totpInput && _loginCredentials) {
        btn.textContent = 'Verifying…';
        const res = await Nova.api('auth', 'login', {
          method: 'POST',
          body: { ..._loginCredentials, totp_code: totpInput.value.trim() }
        });
        if (res?.success && res.data?.user?.role === 'admin') {
          location.reload();
        } else {
          err.textContent = res?.message || 'Invalid 2FA code';
          err.style.display = '';
          btn.disabled = false; btn.textContent = 'Verify';
        }
        return;
      }

      // Step 1: username + password
      btn.textContent = 'Signing in…';
      const creds = { username: document.getElementById('l-user').value, password: document.getElementById('l-pass').value };
      const res = await Nova.api('auth', 'login', { method: 'POST', body: creds });
      if (res?.success && res.data?.user?.role === 'admin') {
        location.reload();
      } else if (res?.totp_required) {
        // Show TOTP step
        _loginCredentials = creds;
        document.getElementById('l-user').closest('.form-group').style.display = 'none';
        document.getElementById('l-pass').closest('.form-group').style.display = 'none';
        const totpGroup = document.createElement('div');
        totpGroup.className = 'form-group';
        totpGroup.innerHTML = '<label>2FA Code</label><input id="l-totp" type="text" inputmode="numeric" maxlength="6" autocomplete="one-time-code" placeholder="6-digit code" autofocus>';
        loginForm.insertBefore(totpGroup, btn.parentNode || btn);
        btn.textContent = 'Verify'; btn.disabled = false;
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
    'nginx-proxy': nginxProxy,
    wordpress,
    'ssl-manager': sslManager,
    firewall,
    'audit-log': auditLog,
    twofa,
    updates,
    backups,
    cloudflare,
    settings,
  };

  window._novaPages = pages;
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
    const [ver, ncpxCheck, osCheck] = await Promise.all([
      Nova.api('system', 'version'),
      Nova.api('system', 'check-novacpx-update'),
      Nova.api('system', 'check-os-update'),
    ]);
    const v     = ver?.data || {};
    const ncpx  = ncpxCheck?.data || {};
    const os    = osCheck?.data || {};
    const ncpxCount = ncpx.updates_available || 0;
    const osCount   = os.upgradable || 0;

    return `
<div class="page-header mb-3">
  <h2 class="page-title">Updates</h2>
  <p class="text-muted text-sm">Manage NovaCPX panel updates and OS package upgrades.</p>
</div>

<!-- NovaCPX Panel Updates -->
<div class="card mb-3">
  <div class="card-header">
    <span class="card-title">
      <svg class="icon-sm mr-1"><use href="/assets/img/nova-icons.svg#ni-updates"/></svg>
      NovaCPX Panel
    </span>
    ${ncpxCount > 0 ? Nova.badge(ncpxCount + ' commit' + (ncpxCount > 1 ? 's' : '') + ' available', 'yellow') : Nova.badge('Up to date', 'green')}
    <button class="btn btn-ghost btn-sm ml-auto" onclick="adminPage('updates')">
      <svg class="icon-xs"><use href="/assets/img/nova-icons.svg#ni-search"/></svg> Refresh
    </button>
  </div>
  <div class="card-body">
    <div class="grid-4 mb-3">
      <div><p class="text-muted text-sm">Installed</p><p class="font-bold">${v.installed_version || '—'}</p></div>
      <div><p class="text-muted text-sm">Commit</p><code>${ncpx.current_commit || v.git_commit || '—'}</code></div>
      <div><p class="text-muted text-sm">Branch</p><code>${ncpx.branch || 'main'}</code></div>
      <div><p class="text-muted text-sm">PHP</p><code>${v.php_version || '—'}</code></div>
    </div>

    ${ncpxCount > 0 ? `
    <div class="card mb-2" style="background:var(--bg3)">
      <div class="card-header"><span class="card-title">Pending Commits</span></div>
      <div class="card-body terminal" style="max-height:140px;overflow-y:auto">
        ${ncpx.commits?.map(c => `<div>${Nova.escHtml(c)}</div>`).join('') || 'None'}
      </div>
    </div>
    <p class="text-muted text-sm mb-2">PHP syntax is validated before deploy. If the panel goes down after update, it will automatically restore from backup.</p>
    <button class="btn btn-primary" id="ncpx-update-btn" onclick="applyNovaCPXUpdate()">
      <svg class="icon-xs mr-1"><use href="/assets/img/nova-icons.svg#ni-updates"/></svg>
      Update NovaCPX
    </button>
    ` : `<p class="text-muted">NovaCPX is up to date.</p>`}
  </div>
</div>

<!-- OS Updates -->
<div class="card">
  <div class="card-header">
    <span class="card-title">
      <svg class="icon-sm mr-1"><use href="/assets/img/nova-icons.svg#ni-server"/></svg>
      Operating System Packages
    </span>
    ${os.security_updates > 0 ? Nova.badge(os.security_updates + ' security', 'red') : ''}
    ${osCount > 0 ? Nova.badge(osCount + ' upgradable', 'yellow') : Nova.badge('All current', 'green')}
  </div>
  <div class="card-body">
    ${osCount > 0 ? `
    <div class="table-wrap mb-2" style="max-height:200px;overflow-y:auto">
      <table>
        <thead><tr><th>Package</th><th>From</th><th>To</th></tr></thead>
        <tbody>
          ${os.packages?.map(p => `<tr>
            <td><code>${Nova.escHtml(p.name)}</code></td>
            <td class="text-muted text-sm">${Nova.escHtml(p.from || '(new)')}</td>
            <td class="text-sm">${Nova.escHtml(p.to)}</td>
          </tr>`).join('') || ''}
        </tbody>
      </table>
    </div>
    <p class="text-muted text-sm mb-2">Services are automatically restarted if an upgrade stops them. The NovaCPX web root is backed up before upgrade and restored if panel ports go down.</p>
    <button class="btn btn-warning" id="os-update-btn" onclick="applyOSUpdate()">
      <svg class="icon-xs mr-1"><use href="/assets/img/nova-icons.svg#ni-server"/></svg>
      Apply OS Upgrade
    </button>
    ` : `<p class="text-muted">All OS packages are current.</p>`}
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
  // ── Firewall ───────────────────────────────────────────────────────────────
  async function firewall() {
    const [fwRes, f2bRes, ipRes, ignoreipRes] = await Promise.all([
      Nova.api('firewall','status'),
      Nova.api('firewall','f2b-status'),
      Nova.api('firewall','ip-lists'),
      Nova.api('firewall','f2b-ignoreip-list'),
    ]);
    const fw           = fwRes?.data  || {};
    const jails        = f2bRes?.data?.jails || [];
    const trusted      = ipRes?.data?.trusted || [];
    const blocked      = ipRes?.data?.blocked || [];
    const fwIgnoreips  = ignoreipRes?.data?.ignoreip || ignoreipRes?.data?.detected || [];
    const rules   = fw.rules || [];
    const active  = fw.active;

    const totalBanned = jails.reduce((s,j) => s + (j.currently_banned||0), 0);

    return `
<div class="page-header mb-3">
  <h2 class="page-title">Firewall</h2>
  <div style="display:flex;gap:.5rem;align-items:center">
    ${active ? Nova.badge('UFW Active','green') : Nova.badge('UFW Disabled','red')}
    ${active
      ? `<button class="btn btn-sm btn-ghost" onclick="fwToggle(false)">Disable UFW</button>`
      : `<button class="btn btn-sm btn-primary" onclick="fwToggle(true)">Enable UFW</button>`}
    <button class="btn btn-sm btn-ghost" onclick="adminPage('firewall')">
      <svg class="icon-xs"><use href="/assets/img/nova-icons.svg#ni-search"/></svg> Refresh
    </button>
  </div>
</div>

<div class="grid-2 mb-3" style="gap:1.5rem">
  <!-- Default Policies -->
  <div class="card">
    <div class="card-header">
      <span class="card-title">Default Policies</span>
    </div>
    <div class="card-body">
      <div class="grid-2 mb-3">
        <div>
          <p class="text-muted text-sm">Incoming</p>
          <select id="pol-incoming" class="form-control form-control-sm" style="width:auto">
            ${['deny','allow','reject'].map(p=>`<option value="${p}" ${fw.default_incoming===p?'selected':''}>${p.charAt(0).toUpperCase()+p.slice(1)}</option>`).join('')}
          </select>
        </div>
        <div>
          <p class="text-muted text-sm">Outgoing</p>
          <select id="pol-outgoing" class="form-control form-control-sm" style="width:auto">
            ${['allow','deny','reject'].map(p=>`<option value="${p}" ${fw.default_outgoing===p?'selected':''}>${p.charAt(0).toUpperCase()+p.slice(1)}</option>`).join('')}
          </select>
        </div>
      </div>
      <button class="btn btn-sm btn-primary" onclick="fwSavePolicies()">Save Policies</button>
    </div>
  </div>

  <!-- Fail2Ban summary -->
  <div class="card">
    <div class="card-header">
      <span class="card-title">Fail2Ban</span>
      ${totalBanned > 0 ? Nova.badge(totalBanned + ' banned', 'red') : Nova.badge('0 banned','green')}
    </div>
    <div class="card-body">
      <div class="grid-2 mb-2">
        <div><p class="text-muted text-sm">Active Jails</p><p class="font-bold">${jails.length}</p></div>
        <div><p class="text-muted text-sm">Currently Banned</p><p class="font-bold">${totalBanned}</p></div>
      </div>
      <div style="display:flex;gap:.5rem;flex-wrap:wrap">
        <button class="btn btn-sm btn-ghost" onclick="fwF2bReload()">Reload Config</button>
        <button class="btn btn-sm btn-ghost" onclick="fwF2bRestart()">Restart</button>
      </div>
    </div>
  </div>
</div>

<!-- UFW Rules -->
<div class="card mb-3">
  <div class="card-header">
    <span class="card-title">
      <svg class="icon-sm mr-1"><use href="/assets/img/nova-icons.svg#ni-firewall"/></svg>
      UFW Rules
    </span>
    <span class="text-muted text-sm ml-2">${rules.length} rule${rules.length!==1?'s':''}</span>
    <button class="btn btn-sm btn-primary ml-auto" onclick="fwAddRuleModal()">+ Add Rule</button>
    <button class="btn btn-sm btn-ghost" onclick="fwResetModal()">Reset to Defaults</button>
  </div>
  ${rules.length ? `
  <div class="table-wrap">
    <table>
      <thead><tr><th>#</th><th>To / Port</th><th>Action</th><th>From</th><th></th></tr></thead>
      <tbody>
        ${rules.map(r => `<tr>
          <td class="text-muted text-sm">${r.num}</td>
          <td><code>${Nova.escHtml(r.to)}</code></td>
          <td>${fwActionBadge(r.action)}</td>
          <td class="text-sm">${Nova.escHtml(r.from)}</td>
          <td>
            <button class="btn btn-xs btn-ghost" style="color:var(--red)" onclick="fwDeleteRule(${r.num})">Delete</button>
          </td>
        </tr>`).join('')}
      </tbody>
    </table>
  </div>` : `<div class="card-body"><p class="text-muted">No rules defined.</p></div>`}
</div>

<!-- Add Quick Rule (inline) -->
<div class="card mb-3">
  <div class="card-header"><span class="card-title">Quick Rule</span></div>
  <div class="card-body">
    <div style="display:flex;gap:.75rem;flex-wrap:wrap;align-items:flex-end">
      <div class="form-group mb-0">
        <label class="form-label text-sm">Action</label>
        <select id="qr-action" class="form-control form-control-sm">
          <option value="allow">Allow</option>
          <option value="deny">Deny</option>
          <option value="reject">Reject</option>
          <option value="limit">Limit</option>
        </select>
      </div>
      <div class="form-group mb-0">
        <label class="form-label text-sm">Direction</label>
        <select id="qr-dir" class="form-control form-control-sm">
          <option value="in">In</option>
          <option value="out">Out</option>
        </select>
      </div>
      <div class="form-group mb-0">
        <label class="form-label text-sm">Port / Service</label>
        <input id="qr-port" class="form-control form-control-sm" placeholder="80, 8000:9000, ssh…" style="width:160px">
      </div>
      <div class="form-group mb-0">
        <label class="form-label text-sm">Protocol</label>
        <select id="qr-proto" class="form-control form-control-sm">
          <option value="tcp">TCP</option>
          <option value="udp">UDP</option>
          <option value="any">Any</option>
        </select>
      </div>
      <div class="form-group mb-0">
        <label class="form-label text-sm">From IP (optional)</label>
        <input id="qr-from" class="form-control form-control-sm" placeholder="any" style="width:150px">
      </div>
      <div class="form-group mb-0">
        <label class="form-label text-sm">Comment</label>
        <input id="qr-comment" class="form-control form-control-sm" placeholder="optional" style="width:140px">
      </div>
      <button class="btn btn-primary btn-sm mb-0" onclick="fwQuickRule()">Add Rule</button>
    </div>
  </div>
</div>

<div class="grid-2 mb-3" style="gap:1.5rem">
  <!-- Trusted IPs -->
  <div class="card">
    <div class="card-header">
      <span class="card-title">Trusted IPs</span>
      <span class="text-muted text-sm ml-2">${trusted.length} IPs</span>
    </div>
    <div class="card-body">
      <div style="display:flex;gap:.5rem;margin-bottom:.75rem">
        <input id="fw-trust-ip" class="form-control form-control-sm" placeholder="IP or CIDR e.g. 192.168.1.0/24" style="flex:1">
        <button class="btn btn-sm btn-primary" onclick="fwAllowIp()">Allow</button>
      </div>
      ${trusted.length ? `<div style="display:flex;flex-wrap:wrap;gap:.35rem">
        ${trusted.map(ip => `<span class="badge badge-green" style="cursor:pointer" onclick="fwRemoveIp('${ip}','allow')" title="Click to remove">${Nova.escHtml(ip)} ×</span>`).join('')}
      </div>` : `<p class="text-muted text-sm">No trusted IPs.</p>`}
    </div>
  </div>

  <!-- Blocked IPs -->
  <div class="card">
    <div class="card-header">
      <span class="card-title">Blocked IPs</span>
      <span class="text-muted text-sm ml-2">${blocked.length} IPs</span>
    </div>
    <div class="card-body">
      <div style="display:flex;gap:.5rem;margin-bottom:.75rem">
        <input id="fw-block-ip" class="form-control form-control-sm" placeholder="IP or CIDR e.g. 1.2.3.4" style="flex:1">
        <button class="btn btn-sm" style="background:var(--red);color:#fff" onclick="fwBlockIp()">Block</button>
      </div>
      ${blocked.length ? `<div style="display:flex;flex-wrap:wrap;gap:.35rem">
        ${blocked.map(ip => `<span class="badge badge-red" style="cursor:pointer" onclick="fwRemoveIp('${ip}','deny')" title="Click to remove">${Nova.escHtml(ip)} ×</span>`).join('')}
      </div>` : `<p class="text-muted text-sm">No blocked IPs.</p>`}
    </div>
  </div>
</div>

<!-- Fail2Ban Jails -->
<div class="card mb-3">
  <div class="card-header">
    <span class="card-title">Fail2Ban Jails</span>
  </div>
  ${jails.length ? `
  <div class="table-wrap">
    <table>
      <thead><tr><th>Jail</th><th>Currently Banned</th><th>Total Banned</th><th>Failed</th><th>Actions</th></tr></thead>
      <tbody>
        ${jails.map(j => `<tr>
          <td><strong>${Nova.escHtml(j.jail)}</strong></td>
          <td>${j.currently_banned > 0 ? `<span style="color:var(--red);font-weight:600">${j.currently_banned}</span>` : '0'}</td>
          <td class="text-muted">${j.total_banned}</td>
          <td class="text-muted">${j.currently_failed}</td>
          <td style="display:flex;gap:.35rem;flex-wrap:wrap">
            ${j.currently_banned > 0 ? `<button class="btn btn-xs btn-ghost" onclick="fwJailDetail('${j.jail}')">View Banned</button>` : ''}
            <button class="btn btn-xs btn-ghost" onclick="fwManualBanModal('${j.jail}')">Ban IP</button>
          </td>
        </tr>`).join('')}
      </tbody>
    </table>
  </div>` : `<div class="card-body"><p class="text-muted">Fail2Ban not running or no jails configured.</p></div>`}
</div>

<!-- Fail2Ban Whitelist (ignoreip) -->
<div class="card mb-3">
  <div class="card-header">
    <span class="card-title">Fail2Ban Whitelist</span>
    <span class="text-muted text-sm ml-2">IPs that will <strong>never</strong> be banned</span>
    <button class="btn btn-xs btn-ghost ml-auto" onclick="fwIgnoreipReset()" title="Reset to server defaults">Reset to Defaults</button>
  </div>
  <div class="card-body" id="ignoreip-body">
    <div style="display:flex;gap:.5rem;margin-bottom:.75rem">
      <input id="fw-ignoreip-input" class="form-control form-control-sm"
        placeholder="IP or CIDR — e.g. 192.168.1.50 or 10.0.0.0/8" style="flex:1">
      <button class="btn btn-sm btn-primary" onclick="fwIgnoreipAdd()">Add to Whitelist</button>
    </div>
    <div id="ignoreip-chips" style="display:flex;flex-wrap:wrap;gap:.35rem">
      ${(fwIgnoreips||[]).map(ip => fwIgnoreipChip(ip)).join('')}
    </div>
    <p class="text-muted text-sm mt-2" style="font-size:.75rem">
      Loopback (127.0.0.0/8, ::1) and the server's own LAN IPs are added automatically.
      Add your home/office IP or subnet here so you never lock yourself out.
    </p>
  </div>
</div>

<!-- UFW Logging -->
<div class="card">
  <div class="card-header"><span class="card-title">UFW Logging</span></div>
  <div class="card-body">
    <div style="display:flex;gap:.75rem;align-items:flex-end;flex-wrap:wrap">
      <div class="form-group mb-0">
        <label class="form-label text-sm">Log Level</label>
        <select id="fw-log-level" class="form-control form-control-sm">
          ${['off','on','low','medium','high','full'].map(l=>`<option value="${l}">${l.charAt(0).toUpperCase()+l.slice(1)}</option>`).join('')}
        </select>
      </div>
      <button class="btn btn-sm btn-primary" onclick="fwSetLogging()">Apply</button>
      <span class="text-muted text-sm">Logs at <code>/var/log/ufw.log</code></span>
    </div>
  </div>
</div>`;
  }

  function fwActionBadge(action) {
    const a = (action||'').toLowerCase();
    if (a.includes('allow')) return Nova.badge('ALLOW','green');
    if (a.includes('deny'))  return Nova.badge('DENY','red');
    if (a.includes('reject'))return Nova.badge('REJECT','red');
    if (a.includes('limit')) return Nova.badge('LIMIT','yellow');
    return `<span class="text-sm">${Nova.escHtml(action)}</span>`;
  }

  window.fwToggle = async (enable) => {
    const label = enable ? 'Enable' : 'Disable';
    Nova.confirm(`${label} UFW firewall?`, async () => {
      const r = await Nova.api('firewall', enable ? 'enable' : 'disable', {method:'POST'});
      Nova.toast(r?.message || label + 'd', r?.success ? 'success' : 'error');
      adminPage('firewall');
    }, !enable);
  };

  window.fwSavePolicies = async () => {
    const inc = document.getElementById('pol-incoming')?.value;
    const out = document.getElementById('pol-outgoing')?.value;
    await Nova.api('firewall','default-policy',{method:'POST',body:{direction:'incoming',policy:inc}});
    const r2 = await Nova.api('firewall','default-policy',{method:'POST',body:{direction:'outgoing',policy:out}});
    Nova.toast(r2?.success ? 'Policies saved' : r2?.message, r2?.success ? 'success' : 'error');
    adminPage('firewall');
  };

  window.fwDeleteRule = (num) => {
    Nova.confirm(`Delete rule #${num}? This cannot be undone.`, async () => {
      const r = await Nova.api('firewall','delete-rule',{method:'POST',body:{num}});
      Nova.toast(r?.message || 'Deleted', r?.success ? 'success' : 'error');
      adminPage('firewall');
    }, true);
  };

  window.fwResetModal = () => {
    Nova.confirm('Reset ALL firewall rules to NovaCPX defaults? SSH, HTTP, HTTPS, and panel ports will be re-allowed automatically.', async () => {
      Nova.toast('Resetting firewall…','info',5000);
      const r = await Nova.api('firewall','reset',{method:'POST'});
      Nova.toast(r?.message || 'Reset complete','success');
      adminPage('firewall');
    }, true);
  };

  window.fwQuickRule = async () => {
    const body = {
      action:    document.getElementById('qr-action')?.value,
      direction: document.getElementById('qr-dir')?.value,
      port:      document.getElementById('qr-port')?.value,
      proto:     document.getElementById('qr-proto')?.value,
      from_ip:   document.getElementById('qr-from')?.value || 'any',
      comment:   document.getElementById('qr-comment')?.value,
    };
    if (!body.port) { Nova.toast('Port/service is required','error'); return; }
    const r = await Nova.api('firewall','add-rule',{method:'POST',body});
    Nova.toast(r?.message || (r?.success ? 'Rule added' : 'Failed'), r?.success ? 'success' : 'error');
    if (r?.success) adminPage('firewall');
  };

  window.fwAddRuleModal = () => {
    Nova.modal('Add Firewall Rule',`
<div class="form-group"><label>Action</label>
  <select id="m-action" class="form-control">
    <option value="allow">Allow</option><option value="deny">Deny</option>
    <option value="reject">Reject</option><option value="limit">Limit (rate-limit)</option>
  </select></div>
<div class="form-group"><label>Direction</label>
  <select id="m-dir" class="form-control">
    <option value="in">Incoming</option><option value="out">Outgoing</option>
  </select></div>
<div class="form-group"><label>Port / Service</label>
  <input id="m-port" class="form-control" placeholder="e.g. 443, 8000:9000, ssh, smtp"></div>
<div class="form-group"><label>Protocol</label>
  <select id="m-proto" class="form-control">
    <option value="tcp">TCP</option><option value="udp">UDP</option><option value="any">Any</option>
  </select></div>
<div class="form-group"><label>From IP / CIDR (leave blank for any)</label>
  <input id="m-from" class="form-control" placeholder="any  or  192.168.1.0/24"></div>
<div class="form-group"><label>To IP / CIDR (leave blank for any)</label>
  <input id="m-to" class="form-control" placeholder="any"></div>
<div class="form-group"><label>Comment</label>
  <input id="m-comment" class="form-control" placeholder="optional description"></div>
`,`<button class="btn btn-ghost" onclick="this.closest('.modal-overlay').remove()">Cancel</button>
   <button class="btn btn-primary" onclick="fwSubmitAddRule()">Add Rule</button>`);
  };

  window.fwSubmitAddRule = async () => {
    const body = {
      action:    document.getElementById('m-action')?.value,
      direction: document.getElementById('m-dir')?.value,
      port:      document.getElementById('m-port')?.value,
      proto:     document.getElementById('m-proto')?.value,
      from_ip:   document.getElementById('m-from')?.value || 'any',
      to_ip:     document.getElementById('m-to')?.value   || 'any',
      comment:   document.getElementById('m-comment')?.value,
    };
    if (!body.port) { Nova.toast('Port is required','error'); return; }
    document.querySelector('.modal-overlay')?.remove();
    const r = await Nova.api('firewall','add-rule',{method:'POST',body});
    Nova.toast(r?.message || (r?.success ? 'Rule added' : 'Failed'), r?.success ? 'success' : 'error');
    if (r?.success) adminPage('firewall');
  };

  window.fwAllowIp = async () => {
    const ip = document.getElementById('fw-trust-ip')?.value?.trim();
    if (!ip) { Nova.toast('Enter an IP or CIDR','error'); return; }
    const r = await Nova.api('firewall','allow-ip',{method:'POST',body:{ip}});
    Nova.toast(r?.message || (r?.success ? 'IP allowed' : 'Failed'), r?.success ? 'success' : 'error');
    if (r?.success) adminPage('firewall');
  };

  window.fwBlockIp = async () => {
    const ip = document.getElementById('fw-block-ip')?.value?.trim();
    if (!ip) { Nova.toast('Enter an IP or CIDR','error'); return; }
    Nova.confirm(`Block ${ip}? This will deny all incoming traffic from this address.`, async () => {
      const r = await Nova.api('firewall','block-ip',{method:'POST',body:{ip}});
      Nova.toast(r?.message || (r?.success ? 'IP blocked' : 'Failed'), r?.success ? 'success' : 'error');
      if (r?.success) adminPage('firewall');
    }, true);
  };

  window.fwRemoveIp = (ip, action) => {
    Nova.confirm(`Remove ${action} rule for ${ip}?`, async () => {
      const r = await Nova.api('firewall','remove-ip',{method:'POST',body:{ip,action}});
      Nova.toast(r?.message || 'Removed', r?.success ? 'success' : 'error');
      if (r?.success) adminPage('firewall');
    }, true);
  };

  window.fwJailDetail = async (jail) => {
    const r = await Nova.api('firewall','f2b-jail',{method:'POST',body:{jail}});
    const d = r?.data || {};
    const ips = d.banned_ips || [];
    Nova.modal(`Fail2Ban: ${jail}`,`
<div class="grid-2 mb-2" style="gap:.75rem">
  <div><p class="text-muted text-sm">Currently Banned</p><p class="font-bold">${d.currently_banned}</p></div>
  <div><p class="text-muted text-sm">Total Banned</p><p class="font-bold">${d.total_banned}</p></div>
</div>
${ips.length ? `
<table style="width:100%;font-size:.85rem">
  <thead><tr><th style="text-align:left;padding:.35rem .5rem">Banned IP</th><th></th></tr></thead>
  <tbody>
    ${ips.map(ip=>`<tr>
      <td style="padding:.3rem .5rem"><code>${Nova.escHtml(ip)}</code></td>
      <td style="padding:.3rem .5rem;text-align:right">
        <button class="btn btn-xs btn-primary" onclick="fwUnbanIp('${Nova.escHtml(ip)}','${jail}',this)">Unban</button>
      </td>
    </tr>`).join('')}
  </tbody>
</table>` : '<p class="text-muted">No IPs currently banned in this jail.</p>'}`);
  };

  window.fwUnbanIp = async (ip, jail, btn) => {
    if (btn) btn.disabled = true;
    const r = await Nova.api('firewall','f2b-unban',{method:'POST',body:{ip,jail}});
    Nova.toast(r?.message || 'Unbanned', r?.success ? 'success' : 'error');
    if (r?.success && btn) btn.closest('tr')?.remove();
  };

  window.fwManualBanModal = (jail) => {
    Nova.modal(`Manual Ban — ${jail}`,`
<div class="form-group">
  <label>IP Address to Ban</label>
  <input id="mb-ip" class="form-control" placeholder="1.2.3.4" autofocus>
</div>`,`
<button class="btn btn-ghost" onclick="this.closest('.modal-overlay').remove()">Cancel</button>
<button class="btn" style="background:var(--red);color:#fff" onclick="fwSubmitManualBan('${jail}')">Ban IP</button>`);
  };

  window.fwSubmitManualBan = async (jail) => {
    const ip = document.getElementById('mb-ip')?.value?.trim();
    if (!ip) { Nova.toast('Enter an IP','error'); return; }
    document.querySelector('.modal-overlay')?.remove();
    const r = await Nova.api('firewall','f2b-ban',{method:'POST',body:{ip,jail}});
    Nova.toast(r?.message || (r?.success ? 'Banned' : 'Failed'), r?.success ? 'success' : 'error');
    if (r?.success) adminPage('firewall');
  };

  window.fwF2bReload = async () => {
    const r = await Nova.api('firewall','f2b-reload',{method:'POST'});
    Nova.toast(r?.message || 'Reloaded', r?.success ? 'success' : 'error');
  };

  window.fwF2bRestart = async () => {
    Nova.confirm('Restart Fail2Ban? Active bans will be preserved.', async () => {
      const r = await Nova.api('firewall','f2b-restart',{method:'POST'});
      Nova.toast(r?.message || 'Restarted', r?.success ? 'success' : 'error');
      adminPage('firewall');
    });
  };

  window.fwSetLogging = async () => {
    const level = document.getElementById('fw-log-level')?.value;
    const r = await Nova.api('firewall','set-logging',{method:'POST',body:{level}});
    Nova.toast(r?.message || 'Logging updated', r?.success ? 'success' : 'error');
  };

  function fwIgnoreipChip(ip) {
    const isLoopback = ip === '127.0.0.0/8' || ip === '127.0.0.1' || ip === '::1';
    return `<span class="badge ${isLoopback ? 'badge-blue' : 'badge-green'}" style="cursor:${isLoopback?'default':'pointer'}"
      ${isLoopback ? '' : `onclick="fwIgnoreipRemove('${Nova.escHtml(ip)}')" title="Click to remove"`}>
      ${Nova.escHtml(ip)}${isLoopback ? ' 🔒' : ' ×'}
    </span>`;
  }

  window.fwIgnoreipAdd = async () => {
    const ip = document.getElementById('fw-ignoreip-input')?.value?.trim();
    if (!ip) { Nova.toast('Enter an IP address or CIDR range', 'error'); return; }
    const r = await Nova.api('firewall','f2b-ignoreip-add',{method:'POST',body:{ip}});
    Nova.toast(r?.message || (r?.success ? 'Added' : 'Failed'), r?.success ? 'success' : 'error');
    if (r?.success) {
      const chips = document.getElementById('ignoreip-chips');
      if (chips) chips.innerHTML = (r.data?.ignoreip || []).map(fwIgnoreipChip).join('');
      const inp = document.getElementById('fw-ignoreip-input');
      if (inp) inp.value = '';
    }
  };

  window.fwIgnoreipRemove = async (ip) => {
    Nova.confirm(`Remove ${ip} from Fail2Ban whitelist? They could get banned if they fail too many login attempts.`, async () => {
      const r = await Nova.api('firewall','f2b-ignoreip-remove',{method:'POST',body:{ip}});
      Nova.toast(r?.message || (r?.success ? 'Removed' : 'Failed'), r?.success ? 'success' : 'error');
      if (r?.success) {
        const chips = document.getElementById('ignoreip-chips');
        if (chips) chips.innerHTML = (r.data?.ignoreip || []).map(fwIgnoreipChip).join('');
      }
    }, true);
  };

  window.fwIgnoreipReset = () => {
    Nova.confirm('Reset Fail2Ban whitelist to server defaults (loopback + local IPs)?', async () => {
      const r = await Nova.api('firewall','f2b-ignoreip-reset',{method:'POST'});
      Nova.toast(r?.message || 'Reset', r?.success ? 'success' : 'error');
      if (r?.success) adminPage('firewall');
    });
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

  // ── Backups — delegates to backupsFull() defined in additions ─────────────
  async function backups() { return backupsFull(); }

  // ── Stubs for new pages — implementations in additions block below ─────────
  async function wordpress() { return `<p class="text-muted" style="padding:2rem">Loading…</p>`; }
  async function cloudflare() { return `<p class="text-muted" style="padding:2rem">Loading…</p>`; }
  async function twofa() { return `<p class="text-muted" style="padding:2rem">Loading…</p>`; }
  async function nginxProxy() { return `<p class="text-muted" style="padding:2rem">Loading…</p>`; }

  // ── Global action helpers ──────────────────────────────────────────────────
  window.adminPage = (page) => Nova.loadPage(page, pages);

  window.applyNovaCPXUpdate = async () => {
    Nova.confirm('Apply NovaCPX update? PHP syntax is checked first, and a backup is taken automatically. The panel will self-restore if anything breaks.', async () => {
      const btn = document.getElementById('ncpx-update-btn');
      if (btn) { btn.disabled = true; btn.textContent = 'Updating…'; }
      Nova.toast('Pulling update from GitHub…', 'info', 12000);
      const res = await Nova.api('system', 'apply-novacpx-update', { method: 'POST' });
      if (res?.data?.updated) {
        Nova.toast(`Updated to ${res.data.to_commit}`, 'success', 6000);
        setTimeout(() => Nova.loadPage('updates', pages), 2000);
      } else if (res?.error) {
        Nova.toast(res.error, 'error', 8000);
        if (btn) { btn.disabled = false; btn.textContent = 'Update NovaCPX'; }
      } else {
        Nova.toast('Already up to date.', 'info');
        if (btn) { btn.disabled = false; btn.textContent = 'Update NovaCPX'; }
      }
    });
  };

  window.applyOSUpdate = async () => {
    Nova.confirm('Apply OS package upgrades? Services will be automatically restarted if needed. The NovaCPX panel will self-restore from backup if any ports go down.', async () => {
      const btn = document.getElementById('os-update-btn');
      if (btn) { btn.disabled = true; btn.textContent = 'Upgrading…'; }
      Nova.toast('Running apt-get upgrade — this may take a few minutes…', 'info', 20000);
      const res = await Nova.api('system', 'apply-os-update', { method: 'POST', timeout: 120000 });
      if (res?.data) {
        const d = res.data;
        const healed = Object.entries(d.services_healed || {}).map(([s,r]) => `${s}: ${r}`).join(', ');
        let msg = 'OS upgrade complete.';
        if (healed) msg += ` Auto-healed: ${healed}.`;
        if (!d.panel_ports_ok) msg += ' ⚠ Panel ports were down — auto-restored from backup.';
        Nova.toast(msg, d.panel_ports_ok ? 'success' : 'warning', 10000);
        Nova.loadPage('updates', pages);
      } else {
        Nova.toast(res?.error || 'Upgrade failed', 'error', 8000);
        if (btn) { btn.disabled = false; btn.textContent = 'Apply OS Upgrade'; }
      }
    });
  };

  // keep old alias for any lingering references
  window.applyUpdate = window.applyNovaCPXUpdate;
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
    const [ncpx, os] = await Promise.all([
      Nova.api('system', 'check-novacpx-update'),
      Nova.api('system', 'check-os-update'),
    ]);
    const ncpxN = ncpx?.data?.updates_available || 0;
    const osN   = os?.data?.upgradable || 0;
    const total = ncpxN + osN;
    const badge = document.getElementById('update-badge');
    if (badge && total > 0) { badge.textContent = total; badge.style.display = ''; }
  }
})();
// ── ADDITIONS: appended by features #14-17 ────────────────────────────────

// ── WordPress Manager (#14) ────────────────────────────────────────────────
async function wordpress() {
  const [acctRes, wpRes] = await Promise.all([
    Nova.api('accounts','list',{params:{limit:500}}),
    Nova.api('wordpress','list'),
  ]);
  const accts = acctRes?.data?.accounts || [];
  const installs = wpRes?.data?.installs || [];
  window._adminAcctsWP = accts;

  return `
<div class="page-header mb-3">
  <h2 class="page-title">WordPress Manager</h2>
  <button class="btn btn-primary" onclick="wpInstallModal()">+ Install WordPress</button>
</div>

<div class="card">
  <div class="card-header">
    <span class="card-title">WordPress Installs</span>
    <span class="text-muted text-sm ml-2">${installs.length} install${installs.length!==1?'s':''}</span>
    <button class="btn btn-ghost btn-sm ml-auto" onclick="adminPage('wordpress')">&#x21bb; Refresh</button>
  </div>
  ${installs.length ? `
  <div class="table-wrap">
    <table>
      <thead><tr><th>Domain</th><th>Path</th><th>Account</th><th>Version</th><th>Status</th><th>Actions</th></tr></thead>
      <tbody>
        ${installs.map(w => `<tr>
          <td><strong>${Nova.escHtml(w.domain)}</strong></td>
          <td><code>${Nova.escHtml(w.path||'/')}</code></td>
          <td>${Nova.escHtml(w.username||'—')}</td>
          <td>${w.wp_version ? `<code>${Nova.escHtml(w.wp_version)}</code>` : '—'}</td>
          <td>${Nova.badge(w.status||'active', w.status==='active'?'green':w.status==='updating'?'yellow':'red')}</td>
          <td style="display:flex;gap:.25rem;flex-wrap:wrap">
            <button class="btn btn-xs" onclick="wpInfo(${w.id},'${Nova.escHtml(w.domain)}')">Info</button>
            <button class="btn btn-xs btn-primary" onclick="wpUpdate(${w.id},'core')">Update Core</button>
            <button class="btn btn-xs" onclick="wpUpdate(${w.id},'plugins')">Plugins</button>
            <button class="btn btn-xs" onclick="wpUpdate(${w.id},'themes')">Themes</button>
            ${!w.staging_of ? `<button class="btn btn-xs" onclick="wpCloneStaging(${w.id},'${Nova.escHtml(w.domain)}')">Clone Staging</button>` : `<span class="badge badge-yellow">staging</span>`}
            <button class="btn btn-xs btn-danger" onclick="wpDelete(${w.id},'${Nova.escHtml(w.domain)}')">Delete</button>
          </td>
        </tr>`).join('')}
      </tbody>
    </table>
  </div>` : `<div class="empty" style="padding:2rem">No WordPress installs yet. Click "Install WordPress" to get started.</div>`}
</div>`;
}

window.wpInstallModal = () => {
  const accts = window._adminAcctsWP || [];
  const opts = accts.map(a => `<option value="${a.id}">${a.username} — ${a.domain}</option>`).join('');
  Nova.modal('Install WordPress', `
    <div class="form-group"><label>Account</label><select id="wp-acct" class="form-control">${opts}</select></div>
    <div class="form-group"><label>Domain</label><input id="wp-domain" class="form-control" placeholder="example.com"></div>
    <div class="form-group"><label>Path (leave / for root)</label><input id="wp-path" class="form-control" value="/"></div>
    <div class="form-group"><label>Site Title</label><input id="wp-title" class="form-control" placeholder="My WordPress Site"></div>
    <div class="form-group"><label>WP Admin Username</label><input id="wp-admin" class="form-control" value="admin"></div>
    <div class="form-group"><label>WP Admin Password</label><input id="wp-adminpass" type="password" class="form-control"></div>
    <div class="form-group"><label>WP Admin Email</label><input id="wp-email" type="email" class="form-control"></div>
    <p class="text-muted text-sm">wp-cli will be downloaded automatically if not installed. This may take 1-2 minutes.</p>`,
    `<button class="btn btn-ghost" onclick="this.closest('.modal-overlay').remove()">Cancel</button>
     <button class="btn btn-primary" id="wp-install-btn" onclick="wpSubmitInstall()">Install</button>`);
};

window.wpSubmitInstall = async () => {
  const btn = document.getElementById('wp-install-btn');
  if (btn) { btn.disabled = true; btn.textContent = 'Installing…'; }
  Nova.toast('Installing WordPress — this may take 1-2 minutes…', 'info', 90000);
  const res = await Nova.api('wordpress','install',{method:'POST',body:{
    account_id: +document.getElementById('wp-acct')?.value,
    domain:     document.getElementById('wp-domain')?.value,
    path:       document.getElementById('wp-path')?.value || '/',
    site_title: document.getElementById('wp-title')?.value,
    admin_user: document.getElementById('wp-admin')?.value,
    admin_pass: document.getElementById('wp-adminpass')?.value,
    admin_email:document.getElementById('wp-email')?.value,
  }});
  document.querySelector('.modal-overlay')?.remove();
  if (res?.success) { Nova.toast('WordPress installed!','success'); adminPage('wordpress'); }
  else Nova.toast(res?.message || 'Install failed','error');
};

window.wpUpdate = async (id, type) => {
  const action = type === 'core' ? 'update-core' : type === 'plugins' ? 'update-plugins' : 'update-themes';
  Nova.toast(`Updating ${type}…`,'info',15000);
  const r = await Nova.api('wordpress', action, {method:'POST',body:{install_id:id}});
  Nova.toast(r?.message || (r?.success ? 'Updated' : 'Failed'), r?.success ? 'success' : 'error');
  if (r?.success) adminPage('wordpress');
};

window.wpInfo = async (id, domain) => {
  Nova.toast('Loading info…','info',5000);
  const r = await Nova.api('wordpress','info',{params:{install_id:id}});
  if (!r?.success) { Nova.toast(r?.message,'error'); return; }
  const d = r.data || {};
  const plugins = (d.plugins||[]).map(p => `<tr><td>${Nova.escHtml(p.name)}</td><td>${Nova.escHtml(p.version||'')}</td><td>${Nova.badge(p.status||'inactive',p.status==='active'?'green':'muted')}</td></tr>`).join('');
  const themes = (d.themes||[]).map(t => `<tr><td>${Nova.escHtml(t.name)}</td><td>${Nova.escHtml(t.version||'')}</td><td>${Nova.badge(t.status||'inactive',t.status==='active'?'green':'muted')}</td></tr>`).join('');
  Nova.modal(`WordPress: ${domain}`,`
    <div class="grid-2 mb-2" style="gap:.75rem">
      <div><p class="text-muted text-sm">Core Version</p><p class="font-bold">${Nova.escHtml(d.version||'—')}</p></div>
      <div><p class="text-muted text-sm">Site URL</p><p>${Nova.escHtml(d.siteurl||'—')}</p></div>
    </div>
    <h4 class="mb-1">Plugins (${(d.plugins||[]).length})</h4>
    ${plugins ? `<table class="table" style="font-size:.82rem"><thead><tr><th>Plugin</th><th>Version</th><th>Status</th></tr></thead><tbody>${plugins}</tbody></table>` : '<p class="text-muted text-sm">None</p>'}
    <h4 class="mb-1 mt-2">Themes (${(d.themes||[]).length})</h4>
    ${themes ? `<table class="table" style="font-size:.82rem"><thead><tr><th>Theme</th><th>Version</th><th>Status</th></tr></thead><tbody>${themes}</tbody></table>` : '<p class="text-muted text-sm">None</p>'}`);
};

window.wpCloneStaging = (id, domain) => {
  Nova.confirm(`Clone ${domain} to a staging environment? This copies all files and the database.`, async () => {
    Nova.toast('Cloning to staging…','info',30000);
    const r = await Nova.api('wordpress','clone-staging',{method:'POST',body:{install_id:id}});
    Nova.toast(r?.message || (r?.success ? 'Staging created' : 'Failed'), r?.success ? 'success' : 'error');
    if (r?.success) adminPage('wordpress');
  });
};

window.wpDelete = (id, domain) => {
  Nova.confirm(`DELETE WordPress install on ${domain}? This removes all files AND drops the database. IRREVERSIBLE.`, async () => {
    const r = await Nova.api('wordpress','delete',{method:'POST',body:{install_id:id}});
    Nova.toast(r?.message || (r?.success ? 'Deleted' : 'Failed'), r?.success ? 'success' : 'error');
    if (r?.success) adminPage('wordpress');
  }, true);
};

// ── Backup Manager — full implementation (#15) ─────────────────────────────
async function backupsFull() {
  const [acctRes, bkRes] = await Promise.all([
    Nova.api('accounts','list',{params:{limit:500}}),
    Nova.api('backup','list'),
  ]);
  const accts = acctRes?.data?.accounts || [];
  const backupList = bkRes?.data?.backups || [];
  const diskUsed = bkRes?.data?.disk_used || 0;
  window._adminAcctsBK = accts;

  return `
<div class="page-header mb-3">
  <h2 class="page-title">Backup Manager</h2>
  <div style="display:flex;gap:.5rem">
    <button class="btn btn-primary" onclick="bkCreateModal()">+ New Backup</button>
    <button class="btn btn-ghost btn-sm" onclick="adminPage('backups')">&#x21bb; Refresh</button>
  </div>
</div>

<div class="stats-grid mb-3" style="grid-template-columns:repeat(3,1fr)">
  <div class="stat-card">
    <div class="stat-label">Total Backups</div>
    <div class="stat-value stat-blue">${backupList.length}</div>
  </div>
  <div class="stat-card">
    <div class="stat-label">Disk Used</div>
    <div class="stat-value">${Nova.bytes(diskUsed)}</div>
  </div>
  <div class="stat-card">
    <div class="stat-label">Accounts</div>
    <div class="stat-value">${accts.length}</div>
  </div>
</div>

<div class="card mb-3">
  <div class="card-header">
    <span class="card-title">Backup Schedules</span>
    <button class="btn btn-sm" onclick="bkScheduleModal()">Configure Schedule</button>
  </div>
  <div class="card-body">
    <p class="text-muted text-sm">Set per-account backup schedules. Cron runs backups automatically based on the configured frequency.</p>
    <div style="display:flex;gap:.5rem;flex-wrap:wrap;margin-top:.75rem">
      ${accts.slice(0,8).map(a => `<button class="btn btn-xs" onclick="bkScheduleForAccount(${a.id},'${Nova.escHtml(a.username)}')">${Nova.escHtml(a.username)}</button>`).join('')}
      ${accts.length>8?`<span class="text-muted text-sm">+${accts.length-8} more</span>`:''}
    </div>
  </div>
</div>

<div class="card">
  <div class="card-header"><span class="card-title">All Backups</span></div>
  ${backupList.length ? `
  <div class="table-wrap">
    <table>
      <thead><tr><th>Account</th><th>Type</th><th>Size</th><th>Status</th><th>Storage</th><th>Created</th><th>Actions</th></tr></thead>
      <tbody>
        ${backupList.map(b => `<tr>
          <td>${Nova.escHtml(b.username||b.account_id||'—')}</td>
          <td>${Nova.badge(b.type,'default')}</td>
          <td>${Nova.bytes(b.size||0)}</td>
          <td>${Nova.badge(b.status, b.status==='complete'?'green':b.status==='failed'?'red':'yellow')}</td>
          <td>${b.remote_path ? Nova.badge('remote','blue') : Nova.badge('local','muted')}</td>
          <td class="text-muted text-sm">${Nova.relTime(b.created_at)}</td>
          <td style="display:flex;gap:.25rem">
            ${b.status==='complete'?`<a class="btn btn-xs" href="/api/backup/download?id=${b.id}" target="_blank">Download</a>`:''}
            <button class="btn btn-xs btn-warning" onclick="bkRestore(${b.id})">Restore</button>
            <button class="btn btn-xs btn-danger" onclick="bkDelete(${b.id})">Del</button>
          </td>
        </tr>`).join('')}
      </tbody>
    </table>
  </div>` : `<div class="empty" style="padding:2rem">No backups yet.</div>`}
</div>`;
}

window.bkCreateModal = () => {
  const accts = window._adminAcctsBK || [];
  const opts = accts.map(a => `<option value="${a.id}">${a.username} — ${a.domain}</option>`).join('');
  Nova.modal('Create Backup', `
    <div class="form-group"><label>Account</label><select id="bk-acct" class="form-control">${opts}</select></div>
    <div class="form-group"><label>Type</label>
      <select id="bk-type" class="form-control">
        <option value="full">Full (files + database)</option>
        <option value="files">Files only</option>
        <option value="database">Database only</option>
      </select>
    </div>`,
    `<button class="btn btn-ghost" onclick="this.closest('.modal-overlay').remove()">Cancel</button>
     <button class="btn btn-primary" onclick="bkSubmitCreate()">Create Backup</button>`);
};

window.bkSubmitCreate = async () => {
  const id   = +document.getElementById('bk-acct')?.value;
  const type = document.getElementById('bk-type')?.value;
  document.querySelector('.modal-overlay')?.remove();
  Nova.toast('Creating backup…','info',30000);
  const r = await Nova.api('backup','create',{method:'POST',body:{account_id:id,type}});
  Nova.toast(r?.message||(r?.success?'Backup complete':'Failed'), r?.success?'success':'error');
  if (r?.success) adminPage('backups');
};

window.bkRestore = (id) => {
  Nova.confirm('Restore this backup? Current files and databases will be overwritten. IRREVERSIBLE.', async () => {
    Nova.toast('Restoring…','info',30000);
    const r = await Nova.api('backup','restore',{method:'POST',body:{id}});
    Nova.toast(r?.message||(r?.success?'Restored':'Failed'), r?.success?'success':'error');
  }, true);
};

window.bkDelete = (id) => {
  Nova.confirm('Delete this backup?', async () => {
    const r = await Nova.api('backup','delete',{method:'POST',body:{id}});
    Nova.toast(r?.message||(r?.success?'Deleted':'Failed'), r?.success?'success':'error');
    if (r?.success) adminPage('backups');
  }, true);
};

window.bkScheduleModal = () => {
  const accts = window._adminAcctsBK || [];
  const opts = accts.map(a => `<option value="${a.id}">${a.username}</option>`).join('');
  Nova.modal('Configure Backup Schedule', `
    <div class="form-group"><label>Account</label><select id="bks-acct" class="form-control">${opts}</select></div>
    <div class="form-group"><label>Frequency</label>
      <select id="bks-freq" class="form-control">
        <option value="hourly">Hourly</option>
        <option value="daily" selected>Daily</option>
        <option value="weekly">Weekly</option>
        <option value="monthly">Monthly</option>
      </select>
    </div>
    <div class="form-group"><label>Type</label>
      <select id="bks-type" class="form-control">
        <option value="full">Full</option>
        <option value="files">Files only</option>
        <option value="database">Database only</option>
      </select>
    </div>
    <div class="form-group"><label>Keep (# backups)</label><input id="bks-retain" type="number" class="form-control" value="7"></div>`,
    `<button class="btn btn-ghost" onclick="this.closest('.modal-overlay').remove()">Cancel</button>
     <button class="btn btn-primary" onclick="bkSaveSchedule()">Save Schedule</button>`);
};

window.bkScheduleForAccount = async (id, user) => {
  const r = await Nova.api('backup','get-schedule',{params:{account_id:id}});
  const s = r?.data || {};
  Nova.modal(`Schedule: ${user}`, `
    <div class="form-group"><label>Frequency</label>
      <select id="bks-freq" class="form-control">
        ${['hourly','daily','weekly','monthly'].map(f=>`<option value="${f}"${s.frequency===f?' selected':''}>${f.charAt(0).toUpperCase()+f.slice(1)}</option>`).join('')}
      </select>
    </div>
    <div class="form-group"><label>Type</label>
      <select id="bks-type" class="form-control">
        ${['full','files','database'].map(t=>`<option value="${t}"${s.type===t?' selected':''}>${t.charAt(0).toUpperCase()+t.slice(1)}</option>`).join('')}
      </select>
    </div>
    <div class="form-group"><label>Keep (# backups)</label><input id="bks-retain" type="number" class="form-control" value="${s.retain_count||7}"></div>`,
    `<button class="btn btn-ghost" onclick="this.closest('.modal-overlay').remove()">Cancel</button>
     <button class="btn btn-primary" onclick="bkSaveScheduleFor(${id})">Save</button>`);
};

window.bkSaveSchedule = async () => {
  const id = +document.getElementById('bks-acct')?.value;
  await bkSaveScheduleFor(id);
};

window.bkSaveScheduleFor = async (id) => {
  const r = await Nova.api('backup','schedule',{method:'POST',body:{
    account_id: id,
    frequency:  document.getElementById('bks-freq')?.value,
    type:       document.getElementById('bks-type')?.value,
    retain:     +document.getElementById('bks-retain')?.value,
  }});
  document.querySelector('.modal-overlay')?.remove();
  Nova.toast(r?.message||(r?.success?'Schedule saved':'Failed'), r?.success?'success':'error');
};

// ── Cloudflare Integration (#16) ──────────────────────────────────────────
async function cloudflare() {
  const acctRes = await Nova.api('accounts','list',{params:{limit:500}});
  const accts = acctRes?.data?.accounts || [];
  window._adminAcctsCF = accts;

  return `
<div class="page-header mb-3">
  <h2 class="page-title">Cloudflare Integration</h2>
  <p class="text-muted text-sm">Manage Cloudflare API credentials and DNS sync per account.</p>
</div>

<div class="card mb-3">
  <div class="card-header"><span class="card-title">Account Credentials</span></div>
  <div class="card-body">
    <p class="text-muted text-sm mb-2">Select an account to configure or view its Cloudflare API key.</p>
    <div style="display:flex;gap:.75rem;flex-wrap:wrap;align-items:flex-end">
      <div class="form-group mb-0">
        <label class="form-label text-sm">Account</label>
        <select id="cf-acct-sel" class="form-control form-control-sm" onchange="cfLoadAccount(this.value)">
          <option value="">— Select Account —</option>
          ${accts.map(a=>`<option value="${a.id}">${a.username} — ${a.domain}</option>`).join('')}
        </select>
      </div>
    </div>
    <div id="cf-acct-panel" style="margin-top:1rem"></div>
  </div>
</div>

<div class="card" id="cf-zones-panel" style="display:none">
  <div class="card-header">
    <span class="card-title">Cloudflare Zones</span>
    <button class="btn btn-ghost btn-sm" onclick="cfRefreshZones()">&#x21bb; Refresh Zones</button>
  </div>
  <div id="cf-zones-body" class="card-body">
    <p class="text-muted text-sm">Save credentials first, then click Refresh Zones.</p>
  </div>
</div>`;
}

window.cfLoadAccount = async (id) => {
  if (!id) { document.getElementById('cf-acct-panel').innerHTML=''; return; }
  const r = await Nova.api('cloudflare','get-credentials',{params:{account_id:id}});
  const c = r?.data || {};
  document.getElementById('cf-acct-panel').innerHTML = `
    <div class="grid-2" style="gap:.75rem;max-width:600px">
      <div class="form-group"><label class="form-label">API Email</label>
        <input id="cf-email" class="form-control" type="email" value="${Nova.escHtml(c.cf_api_email||'')}" placeholder="you@example.com"></div>
      <div class="form-group"><label class="form-label">Global API Key</label>
        <input id="cf-apikey" class="form-control" type="text" value="${Nova.escHtml(c.cf_api_key||'')}" placeholder="API key from Cloudflare dashboard"></div>
    </div>
    <div style="display:flex;gap:.5rem;margin-top:.5rem">
      <button class="btn btn-sm btn-primary" onclick="cfSaveCredentials(${id})">Save Credentials</button>
      <button class="btn btn-sm btn-ghost" onclick="cfTestKey(${id})">Test API Key</button>
    </div>
    ${c.cf_api_key ? `<p class="text-muted text-sm mt-1">Key on file: <code>${Nova.escHtml(c.cf_api_key)}</code></p>` : ''}`;
  document.getElementById('cf-zones-panel').style.display = '';
  window._cfCurrentAcct = id;
};

window.cfSaveCredentials = async (id) => {
  const r = await Nova.api('cloudflare','save-credentials',{method:'POST',body:{
    account_id: id,
    api_key:    document.getElementById('cf-apikey')?.value,
    email:      document.getElementById('cf-email')?.value,
  }});
  Nova.toast(r?.message||(r?.success?'Saved':'Failed'), r?.success?'success':'error');
};

window.cfTestKey = async (id) => {
  const r = await Nova.api('cloudflare','test-key',{method:'POST',body:{
    account_id: id,
    api_key:    document.getElementById('cf-apikey')?.value,
    email:      document.getElementById('cf-email')?.value,
  }});
  Nova.toast(r?.message||(r?.data?.valid?'API key is valid':'Invalid key'), r?.data?.valid?'success':'error');
};

window.cfRefreshZones = async () => {
  const id = window._cfCurrentAcct;
  if (!id) { Nova.toast('Select an account first','error'); return; }
  const r = await Nova.api('cloudflare','list-zones',{params:{account_id:id}});
  const zones = r?.data?.zones || r?.data || [];
  const body = document.getElementById('cf-zones-body');
  if (!body) return;
  if (!r?.success) { body.innerHTML=`<p class="text-muted">${Nova.escHtml(r?.message||'Failed to load zones')}</p>`; return; }
  if (!zones.length) { body.innerHTML='<p class="text-muted text-sm">No zones found for these credentials.</p>'; return; }
  body.innerHTML = `
    <table class="table">
      <thead><tr><th>Zone</th><th>Status</th><th>Plan</th><th>Actions</th></tr></thead>
      <tbody>
        ${zones.map(z=>`<tr>
          <td><strong>${Nova.escHtml(z.name)}</strong><br><code style="font-size:.75rem">${Nova.escHtml(z.id)}</code></td>
          <td>${Nova.badge(z.status,z.status==='active'?'green':'yellow')}</td>
          <td class="text-muted text-sm">${Nova.escHtml(z.plan?.name||'—')}</td>
          <td style="display:flex;gap:.25rem">
            <button class="btn btn-xs" onclick="cfViewRecords('${Nova.escHtml(z.id)}','${Nova.escHtml(z.name)}',${id})">DNS Records</button>
            <button class="btn btn-xs btn-primary" onclick="cfSync('${Nova.escHtml(z.id)}','${Nova.escHtml(z.name)}','to',${id})">Push to CF</button>
            <button class="btn btn-xs" onclick="cfSync('${Nova.escHtml(z.id)}','${Nova.escHtml(z.name)}','from',${id})">Pull from CF</button>
            <button class="btn btn-xs btn-warning" onclick="cfPurge('${Nova.escHtml(z.id)}',${id})">Purge Cache</button>
          </td>
        </tr>`).join('')}
      </tbody>
    </table>`;
};

window.cfViewRecords = async (zoneId, domain, acctId) => {
  const r = await Nova.api('cloudflare','list-records',{method:'POST',body:{zone_id:zoneId,account_id:acctId}});
  const records = r?.data?.records || r?.data || [];
  Nova.modal(`CF DNS: ${domain}`, !records.length ? '<p class="text-muted">No records.</p>' : `
    <table class="table" style="font-size:.82rem">
      <thead><tr><th>Name</th><th>Type</th><th>Value</th><th>Proxy</th></tr></thead>
      <tbody>
        ${records.map(rec=>`<tr>
          <td>${Nova.escHtml(rec.name)}</td>
          <td>${Nova.badge(rec.type,'default')}</td>
          <td style="max-width:200px;overflow:hidden;text-overflow:ellipsis"><code>${Nova.escHtml(rec.content)}</code></td>
          <td>
            <label style="display:flex;align-items:center;gap:.35rem;cursor:pointer">
              <input type="checkbox" ${rec.proxiable&&rec.proxied?'checked':''} ${!rec.proxiable?'disabled':''}
                onchange="cfToggleProxy('${zoneId}','${rec.id}',this.checked,${acctId})">
              ${rec.proxied?Nova.badge('proxied','orange'):Nova.badge('DNS only','muted')}
            </label>
          </td>
        </tr>`).join('')}
      </tbody>
    </table>`);
};

window.cfToggleProxy = async (zoneId, recordId, proxied, acctId) => {
  const r = await Nova.api('cloudflare','toggle-proxy',{method:'POST',body:{zone_id:zoneId,record_id:recordId,proxied,account_id:acctId}});
  Nova.toast(r?.message||(r?.success?'Updated':'Failed'), r?.success?'success':'error');
};

window.cfSync = async (zoneId, domain, dir, acctId) => {
  const action = dir==='to' ? 'sync-to-cf' : 'sync-from-cf';
  const label  = dir==='to' ? 'Pushing to Cloudflare' : 'Pulling from Cloudflare';
  Nova.toast(`${label}…`,'info',10000);
  const r = await Nova.api('cloudflare',action,{method:'POST',body:{zone_id:zoneId,domain,account_id:acctId}});
  Nova.toast(r?.message||(r?.success?'Done':'Failed'), r?.success?'success':'error');
};

window.cfPurge = async (zoneId, acctId) => {
  Nova.confirm('Purge all Cloudflare cache for this zone?', async () => {
    const r = await Nova.api('cloudflare','purge-cache',{method:'POST',body:{zone_id:zoneId,account_id:acctId}});
    Nova.toast(r?.message||(r?.success?'Cache purged':'Failed'), r?.success?'success':'error');
  });
};

// ── TOTP / 2FA Admin (#17) ────────────────────────────────────────────────
async function twofa() {
  const res = await Nova.api('accounts','list',{params:{limit:500}});
  const users = res?.data?.accounts || [];
  return `
<div class="page-header mb-3">
  <h2 class="page-title">Two-Factor Authentication</h2>
  <p class="text-muted text-sm">View 2FA status for all users. Force-disable for account recovery.</p>
</div>

<div class="card">
  <div class="card-header">
    <span class="card-title">User 2FA Status</span>
    <button class="btn btn-ghost btn-sm" onclick="adminPage('twofa')">&#x21bb; Refresh</button>
  </div>
  <div class="table-wrap">
    <table>
      <thead><tr><th>Username</th><th>Email</th><th>Role</th><th>2FA Status</th><th>Actions</th></tr></thead>
      <tbody id="totp-user-rows">
        ${users.map(u=>`<tr>
          <td><strong>${Nova.escHtml(u.username)}</strong></td>
          <td class="text-muted text-sm">${Nova.escHtml(u.email||'—')}</td>
          <td>${Nova.badge(u.role||'user','default')}</td>
          <td id="totp-status-${u.id}">
            <span class="text-muted text-sm">—</span>
          </td>
          <td>
            <button class="btn btn-xs btn-ghost" onclick="totpCheckStatus(${u.id})">Check</button>
            <button class="btn btn-xs btn-warning" onclick="totpAdminDisable(${u.id},'${Nova.escHtml(u.username)}')">Force Disable</button>
          </td>
        </tr>`).join('')}
      </tbody>
    </table>
  </div>
</div>`;
}

window.totpCheckStatus = async (userId) => {
  const r = await Nova.api('totp','admin-status',{method:'POST',body:{user_id:userId}});
  const el = document.getElementById(`totp-status-${userId}`);
  if (!el) return;
  const enabled = r?.data?.totp_enabled;
  el.innerHTML = enabled
    ? Nova.badge('Enabled','green')
    : Nova.badge('Disabled','muted');
};

window.totpAdminDisable = (userId, username) => {
  Nova.confirm(`Force-disable 2FA for ${username}? Use only for account recovery when user cannot log in.`, async () => {
    const r = await Nova.api('totp','admin-disable',{method:'POST',body:{user_id:userId}});
    Nova.toast(r?.message||(r?.success?'2FA disabled':'Failed'), r?.success?'success':'error');
    if (r?.success) {
      const el = document.getElementById(`totp-status-${userId}`);
      if (el) el.innerHTML = Nova.badge('Disabled','muted');
    }
  }, true);
};

// ── Nginx Proxy Manager ───────────────────────────────────────────────────────
async function nginxProxy() {
  const [statusR, hostsR] = await Promise.all([
    Nova.api('proxy', 'status'),
    Nova.api('proxy', 'hosts'),
  ]);
  const s     = statusR?.data  || {};
  const hosts = hostsR?.data   || (Array.isArray(hostsR) ? hostsR : []);
  const run   = s.running;
  const inst  = s.installed;

  return `
<div class="page-header">
  <h1 class="page-title">Nginx Proxy Manager</h1>
  <div class="page-actions">
    ${inst ? `
      <button class="btn btn-ghost btn-sm" onclick="proxySetupInstructions()">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4M12 8h.01"/></svg>
        Setup Guide
      </button>
      <button class="btn btn-sm btn-secondary" onclick="proxySync()">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14"><polyline points="23 4 23 10 17 10"/><polyline points="1 20 1 14 7 14"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/></svg>
        Sync Accounts
      </button>
      <button class="btn btn-sm btn-primary" onclick="proxyAddHost()">+ Add Host</button>
    ` : ''}
  </div>
</div>

<div class="stats-grid" style="margin-bottom:1.5rem">
  <div class="stat-card">
    <div class="stat-label">Nginx Status</div>
    <div class="stat-value ${run ? 'stat-green' : 'stat-red'}">${inst ? (run ? 'Running' : 'Stopped') : 'Not Installed'}</div>
    <div class="stat-sub">${s.version || (inst ? 'nginx' : 'click Install to set up')}</div>
  </div>
  <div class="stat-card">
    <div class="stat-label">Proxy Hosts</div>
    <div class="stat-value">${hosts.length}</div>
    <div class="stat-sub">${hosts.filter(h => h.enabled).length} active</div>
  </div>
  <div class="stat-card">
    <div class="stat-label">SSL Enabled</div>
    <div class="stat-value">${hosts.filter(h => h.ssl_enabled).length}</div>
    <div class="stat-sub">of ${hosts.length} hosts</div>
  </div>
</div>

${!inst ? `
<div class="panel" style="text-align:center;padding:3rem">
  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" width="48" height="48" style="color:var(--text-muted);margin-bottom:1rem"><rect x="2" y="2" width="20" height="8" rx="2"/><rect x="2" y="14" width="20" height="8" rx="2"/><line x1="6" y1="6" x2="6.01" y2="6"/><line x1="6" y1="18" x2="6.01" y2="18"/></svg>
  <h3 style="margin-bottom:0.5rem">Nginx Not Installed</h3>
  <p style="color:var(--text-muted);margin-bottom:1.5rem">Install Nginx on this VM to use it as a reverse proxy in front of Apache, or use a separate proxy VM (see Setup Guide).</p>
  <div style="display:flex;gap:0.75rem;justify-content:center;flex-wrap:wrap">
    <button class="btn btn-primary" onclick="proxyInstall()">Install Nginx Locally</button>
    <button class="btn btn-secondary" onclick="proxySetupInstructions()">Setup Guide / Remote VM</button>
  </div>
</div>
` : `
<div class="panel" style="margin-bottom:1.5rem">
  <div class="panel-header">
    <h3 class="panel-title">Service Controls</h3>
    <div style="display:flex;gap:0.5rem">
      <button class="btn btn-sm btn-success" onclick="proxyControl('start')">Start</button>
      <button class="btn btn-sm btn-warning" onclick="proxyControl('restart')">Restart</button>
      <button class="btn btn-sm btn-danger"  onclick="proxyControl('stop')">Stop</button>
      <button class="btn btn-sm btn-ghost"   onclick="proxyControl('reload')">Reload Config</button>
    </div>
  </div>
</div>

<div class="panel">
  <div class="panel-header">
    <h3 class="panel-title">Proxy Hosts</h3>
    <span class="badge badge-blue">${hosts.length} total</span>
  </div>
  ${hosts.length === 0 ? `
  <div style="text-align:center;padding:2rem;color:var(--text-muted)">
    No proxy hosts yet. Click <strong>Sync Accounts</strong> to auto-add all hosted domains, or <strong>+ Add Host</strong> to add manually.
  </div>
  ` : `
  <div style="overflow-x:auto">
  <table class="table">
    <thead><tr>
      <th>Domain</th>
      <th>Upstream</th>
      <th>SSL</th>
      <th>Status</th>
      <th>Actions</th>
    </tr></thead>
    <tbody>
    ${hosts.map(h => `
      <tr id="proxy-row-${h.id}">
        <td><strong>${Nova.escHtml(h.domain)}</strong></td>
        <td style="font-family:monospace;font-size:0.8rem">${Nova.escHtml(h.upstream)}</td>
        <td>${h.ssl_enabled ? Nova.badge('SSL','green') : Nova.badge('HTTP','muted')}</td>
        <td>${h.enabled ? Nova.badge('Active','green') : Nova.badge('Disabled','red')}</td>
        <td>
          <button class="btn btn-xs btn-ghost" onclick="proxyEditHost(${h.id})">Edit</button>
          <button class="btn btn-xs ${h.enabled ? 'btn-warning' : 'btn-success'}" onclick="proxyToggle(${h.id},${h.enabled ? 0 : 1})">${h.enabled ? 'Disable' : 'Enable'}</button>
          <button class="btn btn-xs btn-danger" onclick="proxyDeleteHost(${h.id},'${Nova.escHtml(h.domain)}')">Delete</button>
        </td>
      </tr>`).join('')}
    </tbody>
  </table>
  </div>
  `}
</div>
`}`;
}

window.proxyInstall = async () => {
  if (!confirm('Install Nginx on this VM? This will run apt-get install nginx.')) return;
  Nova.toast('Installing nginx...', 'info');
  const r = await Nova.api('proxy', 'install', { method: 'POST' });
  Nova.toast(r?.data?.result || r?.message || 'Done', r?.data?.result === 'installed' ? 'success' : 'info');
  Nova.loadPage('nginx-proxy', window._novaPages);
};

window.proxyControl = async (action) => {
  const r = await Nova.api('proxy', 'control', { method: 'POST', body: { action } });
  Nova.toast(r?.data?.result || r?.message || action + ' done', 'success');
  setTimeout(() => Nova.loadPage('nginx-proxy', window._novaPages), 800);
};

window.proxySync = async () => {
  const r = await Nova.api('proxy', 'sync', { method: 'POST' });
  Nova.toast(`Synced: ${r?.data?.added ?? 0} new hosts added`, 'success');
  Nova.loadPage('nginx-proxy', window._novaPages);
};

window.proxyAddHost = () => {
  Nova.modal('Add Proxy Host', `
    <div class="form-group"><label>Domain</label>
      <input id="ph-domain" type="text" placeholder="example.com" class="form-control"></div>
    <div class="form-group"><label>Upstream URL</label>
      <input id="ph-upstream" type="text" value="http://127.0.0.1:80" class="form-control">
      <small class="text-muted">e.g. http://127.0.0.1:80 or http://10.0.0.2:8080</small></div>
    <div class="form-group">
      <label><input type="checkbox" id="ph-ssl"> Enable SSL</label></div>
    <div class="form-group"><label>Notes (optional)</label>
      <input id="ph-notes" type="text" class="form-control"></div>
  `, async () => {
    const domain   = document.getElementById('ph-domain')?.value?.trim();
    const upstream = document.getElementById('ph-upstream')?.value?.trim();
    if (!domain || !upstream) { Nova.toast('Domain and upstream required', 'error'); return; }
    const r = await Nova.api('proxy', 'hosts', {
      method: 'POST',
      body: { domain, upstream, ssl_enabled: document.getElementById('ph-ssl')?.checked ? 1 : 0 }
    });
    Nova.toast(r?.success ? 'Host added' : (r?.message || 'Failed'), r?.success ? 'success' : 'error');
    if (r?.success) Nova.loadPage('nginx-proxy', window._novaPages);
  });
};

window.proxyEditHost = async (id) => {
  const hostsR = await Nova.api('proxy', 'hosts');
  const hosts  = hostsR?.data || (Array.isArray(hostsR) ? hostsR : []);
  const h      = hosts.find(x => x.id == id);
  if (!h) return;
  Nova.modal('Edit Proxy Host', `
    <div class="form-group"><label>Domain</label>
      <input id="phe-domain" type="text" value="${Nova.escHtml(h.domain)}" class="form-control"></div>
    <div class="form-group"><label>Upstream URL</label>
      <input id="phe-upstream" type="text" value="${Nova.escHtml(h.upstream)}" class="form-control"></div>
    <div class="form-group">
      <label><input type="checkbox" id="phe-ssl" ${h.ssl_enabled ? 'checked' : ''}> Enable SSL</label></div>
    <div class="form-group"><label>Custom Nginx Config (overrides auto-generated)</label>
      <textarea id="phe-custom" rows="6" class="form-control" style="font-family:monospace;font-size:0.78rem">${Nova.escHtml(h.custom_config || '')}</textarea>
      <small class="text-muted">Leave blank to use auto-generated config</small></div>
  `, async () => {
    const r = await Nova.api('proxy', `hosts/${id}`, {
      method: 'PUT',
      body: {
        domain:        document.getElementById('phe-domain')?.value?.trim(),
        upstream:      document.getElementById('phe-upstream')?.value?.trim(),
        ssl_enabled:   document.getElementById('phe-ssl')?.checked ? 1 : 0,
        custom_config: document.getElementById('phe-custom')?.value?.trim() || null,
      }
    });
    Nova.toast(r?.success ? 'Updated' : (r?.message || 'Failed'), r?.success ? 'success' : 'error');
    if (r?.success) Nova.loadPage('nginx-proxy', window._novaPages);
  });
};

window.proxyToggle = async (id, enable) => {
  const r = await Nova.api('proxy', `hosts/${id}/toggle`, { method: 'POST', body: { enabled: enable } });
  Nova.toast(r?.success ? (enable ? 'Enabled' : 'Disabled') : 'Failed', r?.success ? 'success' : 'error');
  if (r?.success) Nova.loadPage('nginx-proxy', window._novaPages);
};

window.proxyDeleteHost = (id, domain) => {
  Nova.confirm(`Delete proxy host for ${domain}?`, async () => {
    const r = await Nova.api('proxy', `hosts/${id}`, { method: 'DELETE' });
    Nova.toast(r?.success ? 'Deleted' : 'Failed', r?.success ? 'success' : 'error');
    if (r?.success) Nova.loadPage('nginx-proxy', window._novaPages);
  }, true);
};

window.proxySetupInstructions = async () => {
  const scriptUrl = '/api/proxy/setup-script';
  Nova.modal('Nginx Proxy Setup Guide', `
<div style="max-height:60vh;overflow-y:auto">
  <h4 style="margin-bottom:0.75rem">Option A — Local (Nginx on this VM)</h4>
  <p style="color:var(--text-muted);margin-bottom:1rem">Install Nginx alongside Apache on this VM. Nginx listens on ports 80/443 and forwards to Apache. Best for SSL termination and caching.</p>
  <ol style="color:var(--text-muted);margin-bottom:1.5rem;padding-left:1.2rem;line-height:1.8">
    <li>Click <strong>Install Nginx Locally</strong> on the main Nginx Proxy page</li>
    <li>Move Apache to port 8080: edit <code>/etc/apache2/ports.conf</code> → change <code>Listen 80</code> to <code>Listen 8080</code></li>
    <li>Update upstream in all proxy hosts to <code>http://127.0.0.1:8080</code></li>
    <li>Click <strong>Sync Accounts</strong> to auto-populate proxy hosts from your hosted accounts</li>
    <li>Click <strong>Reload Config</strong> to apply changes</li>
  </ol>

  <h4 style="margin-bottom:0.75rem">Option B — Remote Proxy VM (Recommended for production)</h4>
  <p style="color:var(--text-muted);margin-bottom:1rem">Run a dedicated Nginx proxy VM in front of this NovaCPX VM. Traffic flows: Internet → FortiGate → Nginx Proxy VM → NovaCPX VM (Apache).</p>
  <ol style="color:var(--text-muted);margin-bottom:1.5rem;padding-left:1.2rem;line-height:1.8">
    <li>Create a new VM on Proxmox (Ubuntu 22.04, 1 vCPU, 1GB RAM)</li>
    <li>Run the setup script below on the new VM as root</li>
    <li>Point FortiGate VIPs to the proxy VM IP (ports 80/443)</li>
    <li>Set the proxy upstream to this NovaCPX VM IP (<code>http://10.48.200.110:80</code>)</li>
    <li>Add proxy hosts for each domain from your NovaCPX admin panel</li>
  </ol>

  <h4 style="margin-bottom:0.75rem">Automated Setup Script</h4>
  <p style="color:var(--text-muted);margin-bottom:0.75rem">Run this on the target VM (local or remote) as root:</p>
  <div style="background:var(--bg-secondary);padding:0.75rem;border-radius:6px;font-family:monospace;font-size:0.8rem;margin-bottom:0.75rem">
    curl -sk https://YOUR_NOVACPX_IP:8882/api/proxy/setup-script | bash
  </div>
  <p style="color:var(--text-muted);font-size:0.85rem">Or download and review before running:</p>
  <div style="background:var(--bg-secondary);padding:0.75rem;border-radius:6px;font-family:monospace;font-size:0.8rem">
    curl -sk https://YOUR_NOVACPX_IP:8882/api/proxy/setup-script -o proxy-setup.sh<br>
    cat proxy-setup.sh   # review<br>
    bash proxy-setup.sh
  </div>

  <h4 style="margin-bottom:0.75rem;margin-top:1.5rem">Integration with VirtualHost Manager</h4>
  <p style="color:var(--text-muted);margin-bottom:0.75rem">When proxy mode is active, NovaCPX automatically:</p>
  <ul style="color:var(--text-muted);padding-left:1.2rem;line-height:1.8">
    <li>Creates a proxy host entry for every new account</li>
    <li>Removes the proxy host when an account is terminated</li>
    <li>Re-generates Nginx config on every account change</li>
    <li>Uses account SSL certs automatically if SSL is enabled on the proxy host</li>
  </ul>
</div>
  `, null, { cancelLabel: 'Close', showConfirm: false });
};
