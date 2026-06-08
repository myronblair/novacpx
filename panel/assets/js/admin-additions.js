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
