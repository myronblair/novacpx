/**
 * NovaCPX Admin Panel — page controllers
 */
(async () => {
  // ── Auth guard ─────────────────────────────────────────────────────────────
  const me = await Nova.api('auth', 'me');
  if (!me?.success || me.data.role !== 'admin') {
    location.href = '/?redirect=/admin/';
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

  // ── Stub pages ─────────────────────────────────────────────────────────────
  function stubPage(title, desc) {
    return `<div class="card"><div class="card-header"><span class="card-title">${title}</span></div>
      <div class="card-body"><p class="text-muted">${desc}</p>
      <div class="mt-2">${Nova.badge('Coming Soon','yellow')}</div></div></div>`;
  }
  function accounts()      { return stubPage('All Accounts', 'View and manage all hosting accounts on this server.'); }
  function resellers()     { return stubPage('Resellers', 'Create and manage reseller accounts with custom packages and resource limits.'); }
  function packages()      { return stubPage('Packages', 'Define hosting packages with disk, bandwidth, email, FTP, and database limits.'); }
  function createAccount() { return stubPage('Create Account', 'Create a new hosting account and assign it a package.'); }
  function dnsZones()      { return stubPage('DNS Zones', 'View, add, and edit all DNS zones on this nameserver.'); }
  function nameservers()   { return stubPage('Nameservers', 'Configure primary and secondary nameservers for all hosted domains.'); }
  function webServer()     { return stubPage('Web Server', 'Manage Apache2 / nginx virtual hosts, modules, and configuration.'); }
  function mysqlManager()  { return stubPage('MySQL / PostgreSQL', 'Create databases, users, and manage remote access.'); }
  function mailServer()    { return stubPage('Mail Server', 'Manage Postfix/Dovecot configuration, spam filters, and mail queues.'); }
  function ftpServer()     { return stubPage('FTP Server', 'Configure ProFTPD, manage FTP accounts and access rules.'); }
  function sslManager()    { return stubPage('SSL Manager', 'Issue, install, and auto-renew Let\'s Encrypt SSL certificates for all domains.'); }
  function firewall()      { return stubPage('Firewall / Fail2Ban', 'Manage UFW rules and review Fail2Ban bans.'); }
  function backups()       { return stubPage('Backups', 'Configure automated backups, restore accounts, and manage backup storage.'); }

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
