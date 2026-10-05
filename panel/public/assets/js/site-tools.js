/* Copyright (c) 2026 TomTom Enterprises. Licensed under the MIT License (see LICENSE). */
/**
 * NovaCPX site tools: Site Shield, Traffic Meter, Pulse (uptime).
 * Pages are plain functions; the panel scripts (user.js / admin.js) register them in their page tables.
 */
(function () {
  const esc = (s) => Nova.escHtml(String(s ?? ''));
  const fmtMb = (mb) => mb >= 1024 ? (mb / 1024).toFixed(2) + ' GB' : Number(mb).toFixed(1) + ' MB';

  /* ══════════════════════════ Site Shield ══════════════════════════ */
  let _sh = null;

  async function shieldLoad() {
    const res = await Nova.api('shield', 'get');
    if (!res?.success) return null;
    const d = res.data;
    _sh = {
      web_server: d.web_server,
      blocked: (d.blocked_ips || []).join('\n'),
      hot: { enabled: !!d.hotlink.enabled, allowed: (d.hotlink.allowed || []).join('\n') },
      folders: (d.folders || []).map(f => ({ path: f.path, realm: f.realm, users: f.users.map(u => ({ name: u.name, password: '', has_password: u.has_password })) })),
      errors: Object.assign({ 404: '', 403: '', 500: '', 503: '' }, d.error_pages || {}),
    };
    return _sh;
  }

  function shieldHtml() {
    const s = _sh;
    const warn = s.web_server !== 'nginx'
      ? `<div class="alert alert-warning" style="margin-bottom:1rem">Site Shield needs the nginx web server. This server currently uses <strong>${esc(s.web_server)}</strong>, so saving will be refused.</div>` : '';
    return `
<div class="page-header">
  <h2 class="page-title">Site Shield</h2>
  <button class="btn btn-primary btn-sm" onclick="shieldSave()">Save &amp; apply</button>
</div>
${warn}
<div class="card" style="margin-bottom:1rem">
  <div class="card-header"><span class="card-title">Blocked addresses</span></div>
  <div style="padding:1rem">
    <textarea id="sh-blocked" class="form-control" rows="4" placeholder="203.0.113.7&#10;198.51.100.0/24">${esc(s.blocked)}</textarea>
    <div class="form-hint" style="margin-top:.4rem">One IP address or range (CIDR) per line. Visitors from these addresses get an error instead of your site.</div>
  </div>
</div>
<div class="card" style="margin-bottom:1rem">
  <div class="card-header"><span class="card-title">Image &amp; file hotlink guard</span></div>
  <div style="padding:1rem">
    <label style="display:flex;gap:.5rem;align-items:center"><input type="checkbox" id="sh-hot-on" ${s.hot.enabled ? 'checked' : ''}> Stop other websites from embedding your images, video, audio, PDFs and zips</label>
    <label class="form-label" style="margin-top:.8rem">Other sites that may still use them (optional)</label>
    <textarea id="sh-hot-allowed" class="form-control" rows="2" placeholder="partner-site.com">${esc(s.hot.allowed)}</textarea>
    <div class="form-hint" style="margin-top:.4rem">One domain per line. Your own domains and direct visits always work.</div>
  </div>
</div>
<div class="card" style="margin-bottom:1rem">
  <div class="card-header"><span class="card-title">Password-locked folders</span>
    <button class="btn btn-xs" onclick="shieldAddFolder()">+ Lock a folder</button></div>
  <div id="sh-folders" style="padding:1rem">${shieldFoldersHtml()}</div>
</div>
<div class="card">
  <div class="card-header"><span class="card-title">Custom error pages</span></div>
  <div style="padding:1rem;display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:.8rem">
    ${[[404, 'Page not found'], [403, 'Forbidden'], [500, 'Server error'], [503, 'Unavailable']].map(([c, n]) => `
      <div class="form-group"><label class="form-label">${c} - ${n}</label>
      <input class="form-control" id="sh-err-${c}" value="${esc(s.errors[c] || '')}" placeholder="/errors/${c}.html"></div>`).join('')}
    <div class="form-hint" style="grid-column:1/-1">Path of a page inside your site, for example /errors/404.html. Leave empty for the default page.</div>
  </div>
</div>`;
  }

  function shieldFoldersHtml() {
    if (!_sh.folders.length) return '<div class="empty">No locked folders. Lock a folder to ask visitors for a user name and password.</div>';
    return _sh.folders.map((f, i) => `
      <div style="border:1px solid var(--border);border-radius:var(--radius);padding:.8rem;margin-bottom:.8rem">
        <div style="display:grid;grid-template-columns:1fr 1fr auto;gap:.6rem;align-items:end">
          <div class="form-group"><label class="form-label">Folder</label><input class="form-control" id="sh-f-path-${i}" value="${esc(f.path)}" placeholder="/members"></div>
          <div class="form-group"><label class="form-label">Prompt text</label><input class="form-control" id="sh-f-realm-${i}" value="${esc(f.realm)}"></div>
          <button class="btn btn-xs btn-danger" onclick="shieldDelFolder(${i})">Remove</button>
        </div>
        <div style="margin-top:.4rem"><strong style="font-size:.8rem">Users</strong>
          ${f.users.map((u, j) => `
            <div style="display:grid;grid-template-columns:1fr 1fr auto;gap:.5rem;margin-top:.4rem">
              <input class="form-control" id="sh-u-name-${i}-${j}" value="${esc(u.name)}" placeholder="user name">
              <input class="form-control" type="password" id="sh-u-pw-${i}-${j}" placeholder="${u.has_password ? 'unchanged - type to replace' : 'password (8+ characters)'}">
              <button class="btn btn-xs" onclick="shieldDelUser(${i},${j})">x</button>
            </div>`).join('')}
          <button class="btn btn-xs" style="margin-top:.5rem" onclick="shieldAddUser(${i})">+ Add user</button>
        </div>
      </div>`).join('');
  }

  function shieldCollect() {
    const v = (id) => document.getElementById(id)?.value ?? '';
    _sh.blocked = v('sh-blocked');
    _sh.hot.enabled = !!document.getElementById('sh-hot-on')?.checked;
    _sh.hot.allowed = v('sh-hot-allowed');
    _sh.folders.forEach((f, i) => {
      f.path = v(`sh-f-path-${i}`); f.realm = v(`sh-f-realm-${i}`);
      f.users.forEach((u, j) => { u.name = v(`sh-u-name-${i}-${j}`); u.password = v(`sh-u-pw-${i}-${j}`); });
    });
    [404, 403, 500, 503].forEach(c => { _sh.errors[c] = v(`sh-err-${c}`); });
  }

  function shieldRefreshFolders() { const el = document.getElementById('sh-folders'); if (el) el.innerHTML = shieldFoldersHtml(); }
  window.shieldAddFolder = () => { shieldCollect(); _sh.folders.push({ path: '', realm: 'Restricted', users: [{ name: '', password: '', has_password: false }] }); shieldRefreshFolders(); };
  window.shieldDelFolder = (i) => { shieldCollect(); _sh.folders.splice(i, 1); shieldRefreshFolders(); };
  window.shieldAddUser   = (i) => { shieldCollect(); _sh.folders[i].users.push({ name: '', password: '', has_password: false }); shieldRefreshFolders(); };
  window.shieldDelUser   = (i, j) => { shieldCollect(); _sh.folders[i].users.splice(j, 1); shieldRefreshFolders(); };

  window.shieldSave = async () => {
    shieldCollect();
    const lines = (t) => t.split(/\r?\n/).map(x => x.trim()).filter(Boolean);
    const body = {
      blocked_ips: lines(_sh.blocked),
      hotlink: { enabled: _sh.hot.enabled, allowed: lines(_sh.hot.allowed) },
      folders: _sh.folders.filter(f => f.path.trim()).map(f => ({ path: f.path.trim(), realm: f.realm, users: f.users.filter(u => u.name.trim()).map(u => ({ name: u.name.trim(), password: u.password })) })),
      error_pages: _sh.errors,
    };
    Nova.loading?.('Applying rules...');
    const res = await Nova.api('shield', 'save', { method: 'POST', body });
    Nova.loadingDone?.();
    if (res?.success) { Nova.toast('Site Shield rules applied', 'success'); await shieldLoad(); shieldRefreshFolders(); }
    else Nova.toast(res?.message || 'Could not apply the rules', 'error');
  };

  window.shieldPage = async (el) => {
    el.innerHTML = '<div class="loading">Loading...</div>';
    if (!(await shieldLoad())) { el.innerHTML = '<div class="empty">Could not load Site Shield.</div>'; return; }
    el.innerHTML = shieldHtml();
  };

  /* ══════════════════════════ Traffic Meter ══════════════════════════ */
  function dailyBars(daily) {
    if (!daily.length) return '<div class="empty">No traffic recorded yet. The meter reads your access log every 5 minutes.</div>';
    const max = Math.max(...daily.map(d => d.mb), 0.01);
    const w = 640, h = 140, bw = Math.max(4, Math.floor((w - 20) / daily.length) - 3);
    const bars = daily.map((d, i) => {
      const bh = Math.max(1, Math.round((d.mb / max) * (h - 30)));
      const x = 10 + i * (bw + 3);
      return `<rect x="${x}" y="${h - 20 - bh}" width="${bw}" height="${bh}" rx="2" fill="var(--primary)"><title>${esc(d.day)}: ${fmtMb(d.mb)} (${d.requests} requests)</title></rect>`;
    }).join('');
    return `<svg viewBox="0 0 ${w} ${h}" style="width:100%;height:auto" role="img" aria-label="Daily traffic, last 30 days">${bars}
      <text x="10" y="${h - 4}" font-size="10" fill="var(--text-muted)">${esc(daily[0].day)}</text>
      <text x="${w - 10}" y="${h - 4}" font-size="10" text-anchor="end" fill="var(--text-muted)">${esc(daily[daily.length - 1].day)}</text></svg>`;
  }

  window.trafficPage = async (el) => {
    el.innerHTML = '<div class="loading">Loading...</div>';
    const res = await Nova.api('traffic', 'summary');
    if (!res?.success) { el.innerHTML = '<div class="empty">Could not load traffic.</div>'; return; }
    const s = res.data;
    const pct = s.percent;
    el.innerHTML = `
<div class="page-header"><h2 class="page-title">Traffic</h2></div>
<div class="stats-grid" style="margin-bottom:1rem">
  <div class="stat-card"><div class="stat-label">Served this month (${esc(s.month)})</div><div class="stat-value">${fmtMb(s.used_mb)}</div></div>
  <div class="stat-card"><div class="stat-label">Monthly allowance</div><div class="stat-value">${s.allowance_mb > 0 ? fmtMb(s.allowance_mb) : 'Unlimited'}</div></div>
  <div class="stat-card"><div class="stat-label">Used</div><div class="stat-value">${pct === null ? '-' : pct + '%'}</div>${pct === null ? '' : Nova.progressBar(Math.min(100, pct))}</div>
</div>
<div class="card"><div class="card-header"><span class="card-title">Last 30 days</span></div><div style="padding:1rem">${dailyBars(s.daily)}</div></div>`;
  };

  window.trafficAdminPage = async () => {
    const [ov, st] = await Promise.all([Nova.api('traffic', 'overview'), Nova.api('traffic', 'settings')]);
    const rows = ov?.data || [];
    const action = st?.data?.bandwidth_action || 'notify';
    return `
<div class="page-header"><h1 class="page-title">Traffic &amp; Usage</h1></div>
<div class="panel" style="margin-bottom:1rem">
  <div class="panel-header"><h3 class="panel-title">When an account reaches 100% of its monthly allowance</h3></div>
  <div style="padding:1rem;display:flex;gap:.6rem;align-items:center">
    <select id="tr-action" class="form-control" style="max-width:320px">
      <option value="notify" ${action === 'notify' ? 'selected' : ''}>Only send a warning email</option>
      <option value="suspend" ${action === 'suspend' ? 'selected' : ''}>Warn and suspend the account</option>
    </select>
    <button class="btn btn-primary btn-sm" onclick="trafficSaveAction()">Save</button>
    <span class="text-muted" style="font-size:.8rem">Warnings are also sent at 80%.</span>
  </div>
</div>
<div class="panel">
  <div class="panel-header"><h3 class="panel-title">This month</h3><span class="badge badge-blue">${rows.length} accounts</span></div>
  ${rows.length === 0 ? '<div style="padding:2rem;text-align:center;color:var(--text-muted)">No accounts</div>' : `
  <div style="overflow-x:auto"><table class="table"><thead><tr><th>Account</th><th>Domain</th><th>Package</th><th>Served</th><th>Allowance</th><th style="min-width:160px">Used</th></tr></thead><tbody>
  ${rows.map(r => `<tr><td><strong>${esc(r.username)}</strong>${r.status !== 'active' ? ' ' + Nova.badge(esc(r.status), 'red') : ''}</td><td>${esc(r.domain)}</td><td>${esc(r.package || '-')}</td>
    <td>${fmtMb(r.used_mb)}</td><td>${r.allowance_mb > 0 ? fmtMb(r.allowance_mb) : 'Unlimited'}</td>
    <td>${r.percent === null ? '-' : r.percent + '%' + Nova.progressBar(Math.min(100, r.percent))}</td></tr>`).join('')}
  </tbody></table></div>`}
</div>`;
  };
  window.trafficSaveAction = async () => {
    const res = await Nova.api('traffic', 'settings', { method: 'POST', body: { bandwidth_action: document.getElementById('tr-action').value } });
    Nova.toast(res?.success ? 'Saved' : (res?.message || 'Failed'), res?.success ? 'success' : 'error');
  };

  /* ══════════════════════════ Pulse (uptime) ══════════════════════════ */
  function strip(recent) {
    if (!recent.length) return '<span class="text-muted" style="font-size:.8rem">Waiting for the first checks...</span>';
    return `<div style="display:flex;gap:2px;align-items:flex-end;height:28px">${recent.map(c =>
      `<span title="${esc(c.at)} - ${c.ok ? c.ms + ' ms' : 'failed'}" style="flex:1;max-width:10px;min-width:3px;border-radius:2px;height:${c.ok ? Math.max(8, Math.min(28, Math.round(c.ms / 40) + 8)) : 28}px;background:${c.ok ? 'var(--green)' : 'var(--red)'}"></span>`).join('')}</div>`;
  }

  window.pulsePage = async (el) => {
    el.innerHTML = '<div class="loading">Loading...</div>';
    const res = await Nova.api('pulse', 'status');
    const sites = res?.data || [];
    el.innerHTML = `
<div class="page-header"><h2 class="page-title">Uptime</h2></div>
<p class="text-muted" style="margin-bottom:1rem">Your sites are checked every 5 minutes. You get an email when one stops answering and another when it is back.</p>
${sites.length === 0 ? '<div class="empty">No checks yet - the first results appear within 5 minutes.</div>' : sites.map(s => `
<div class="card" style="margin-bottom:1rem"><div style="padding:1rem">
  <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:.5rem">
    <strong>${esc(s.domain)}</strong>${s.up ? Nova.badge('Online', 'green') : Nova.badge('Down', 'red')}
  </div>
  <div style="display:flex;gap:2rem;flex-wrap:wrap;margin:.7rem 0;font-size:.85rem">
    <span>Uptime (24h): <strong>${s.uptime_24h === null ? '-' : s.uptime_24h + '%'}</strong></span>
    <span>Response: <strong>${s.last_ms ?? '-'} ms</strong> (avg ${s.avg_ms_24h} ms)</span>
    <span>${s.up ? 'Up' : 'Down'} since: <strong>${esc(s.since || '-')}</strong></span>
  </div>
  ${strip(s.recent)}
</div></div>`).join('')}`;
  };

  window.pulseAdminPage = async () => {
    const [ov, st] = await Promise.all([Nova.api('pulse', 'overview'), Nova.api('pulse', 'settings')]);
    const rows = ov?.data || [];
    const cfg = st?.data || { enabled: true, fail_count: 2 };
    const down = rows.filter(r => !r.up).length;
    return `
<div class="page-header"><h1 class="page-title">Site Uptime</h1></div>
<div class="stats-grid" style="margin-bottom:1rem">
  <div class="stat-card"><div class="stat-label">Sites monitored</div><div class="stat-value">${rows.length}</div></div>
  <div class="stat-card"><div class="stat-label">Down right now</div><div class="stat-value" style="color:${down ? 'var(--red)' : 'var(--green)'}">${down}</div></div>
</div>
<div class="panel" style="margin-bottom:1rem">
  <div class="panel-header"><h3 class="panel-title">Settings</h3></div>
  <div style="padding:1rem;display:flex;gap:1rem;align-items:center;flex-wrap:wrap">
    <label style="display:flex;gap:.4rem;align-items:center"><input type="checkbox" id="pu-on" ${cfg.enabled ? 'checked' : ''}> Monitoring on</label>
    <label>Failed checks in a row before a site counts as down
      <input type="number" id="pu-fails" class="form-control" style="width:80px;display:inline-block" min="1" max="10" value="${cfg.fail_count}"></label>
    <button class="btn btn-primary btn-sm" onclick="pulseSaveSettings()">Save</button>
  </div>
</div>
<div class="panel">
  <div class="panel-header"><h3 class="panel-title">All sites</h3></div>
  ${rows.length === 0 ? '<div style="padding:2rem;text-align:center;color:var(--text-muted)">No checks yet</div>' : `
  <div style="overflow-x:auto"><table class="table"><thead><tr><th>Site</th><th>Account</th><th>State</th><th>Since</th><th>Last answer</th><th>24h uptime</th></tr></thead><tbody>
  ${rows.map(r => `<tr><td><strong>${esc(r.domain)}</strong></td><td>${esc(r.username)}</td>
    <td>${r.up ? Nova.badge('Online', 'green') : Nova.badge('Down', 'red')}</td><td>${esc(r.since || '-')}</td>
    <td>${r.last_code ? 'HTTP ' + r.last_code : 'no answer'}${r.last_ms ? ' - ' + r.last_ms + ' ms' : ''}</td>
    <td>${r.uptime_24h === null ? '-' : r.uptime_24h + '%'}</td></tr>`).join('')}
  </tbody></table></div>`}
</div>`;
  };
  window.pulseSaveSettings = async () => {
    const res = await Nova.api('pulse', 'settings', { method: 'POST', body: { enabled: document.getElementById('pu-on').checked, fail_count: parseInt(document.getElementById('pu-fails').value, 10) || 2 } });
    Nova.toast(res?.success ? 'Saved' : (res?.message || 'Failed'), res?.success ? 'success' : 'error');
  };
  /* ══════════════════════════ Sweep (malware scan) ══════════════════════════ */
  const sevBadge = (s) => Nova.badge(esc(s), s === 'high' ? 'red' : s === 'medium' ? 'yellow' : 'blue');

  async function sweepRender(el) {
    const res = await Nova.api('sweep', 'status');
    if (!res?.success) { el.innerHTML = '<div class="empty">Could not load Sweep.</div>'; return; }
    const { run, findings, clamav } = res.data;
    const running = run && run.status === 'running';
    const open = findings.filter(f => f.status === 'open').length;
    el.innerHTML = `
<div class="page-header"><h2 class="page-title">Sweep</h2>
  <button class="btn btn-primary btn-sm" onclick="sweepScan()" ${running ? 'disabled' : ''}>${running ? 'Scanning...' : 'Scan now'}</button></div>
<p class="text-muted" style="margin-bottom:1rem">Sweep looks through your site's PHP files for the usual signs of web shells and backdoors${clamav ? ' and runs the antivirus engine on top' : ''}. It runs every night and whenever you press Scan now. Nothing is changed unless you quarantine a file.</p>
<div class="stats-grid" style="margin-bottom:1rem">
  <div class="stat-card"><div class="stat-label">Last scan</div><div class="stat-value" style="font-size:1rem">${run ? esc(run.finished_at || run.started_at) : 'never'}</div></div>
  <div class="stat-card"><div class="stat-label">Files checked</div><div class="stat-value">${run ? run.files : '-'}</div></div>
  <div class="stat-card"><div class="stat-label">Open findings</div><div class="stat-value" style="color:${open ? 'var(--red)' : 'var(--green)'}">${open}</div></div>
</div>
${run && run.status === 'failed' ? `<div class="alert alert-warning" style="margin-bottom:1rem">The last scan did not finish: ${esc(run.note || '')}</div>` : ''}
<div class="card">${findings.length === 0 ? '<div class="empty" style="padding:2rem">Nothing suspicious found.</div>' : `
  <div style="overflow-x:auto"><table class="table"><thead><tr><th>Severity</th><th>File</th><th>What was found</th><th>State</th><th>Actions</th></tr></thead><tbody>
  ${findings.map(f => `<tr>
    <td>${sevBadge(f.severity)}</td><td><code>${esc(f.path)}</code></td>
    <td><strong>${esc(f.rule)}</strong><br><small class="text-muted">${esc(f.snippet || '')}</small></td>
    <td>${f.status === 'quarantined' ? Nova.badge('Quarantined', 'green') : 'Open'}</td>
    <td style="white-space:nowrap">${f.status === 'open'
      ? `<button class="btn btn-xs btn-danger" onclick="sweepAct('quarantine',${f.id})">Quarantine</button> <button class="btn btn-xs" onclick="sweepAct('ignore',${f.id})">Harmless</button>`
      : `<button class="btn btn-xs" onclick="sweepAct('restore',${f.id})">Restore</button>`}</td></tr>`).join('')}
  </tbody></table></div>`}</div>`;
    if (running) setTimeout(() => { if (el.isConnected) sweepRender(el); }, 5000);
  }
  window.sweepPage = async (el) => { el.innerHTML = '<div class="loading">Loading...</div>'; window._sweepEl = el; await sweepRender(el); };
  window.sweepScan = async () => {
    const res = await Nova.api('sweep', 'scan', { method: 'POST', body: {} });
    Nova.toast(res?.message || 'Failed', res?.success ? 'success' : 'error');
    if (res?.success) setTimeout(() => sweepRender(window._sweepEl), 1500);
  };
  window.sweepAct = (act, id) => {
    const go = async () => {
      const res = await Nova.api('sweep', act, { method: 'POST', body: { finding_id: id } });
      Nova.toast(res?.message || 'Failed', res?.success ? 'success' : 'error');
      sweepRender(window._sweepEl);
    };
    if (act === 'quarantine') Nova.confirm('Move this file out of your website into quarantine? You can restore it later.', go, true); else go();
  };

  window.sweepAdminPage = async () => {
    const [ov, st] = await Promise.all([Nova.api('sweep', 'overview'), Nova.api('sweep', 'settings')]);
    const rows = ov?.data || [];
    const cfg = st?.data || {};
    return `
<div class="page-header"><h1 class="page-title">Malware Sweep</h1></div>
<div class="panel" style="margin-bottom:1rem"><div class="panel-header"><h3 class="panel-title">Engine</h3></div>
  <div style="padding:1rem;display:flex;gap:1rem;align-items:center;flex-wrap:wrap">
    <span>Built-in pattern scan: <strong>always on</strong> (nightly at 03:30)</span>
    <label style="display:flex;gap:.4rem;align-items:center"><input type="checkbox" id="sw-clam" ${cfg.clamav ? 'checked' : ''} ${cfg.clamav_installed ? '' : 'disabled'}> Also use ClamAV ${cfg.clamav_installed ? '' : '(not installed on this server)'}</label>
    <button class="btn btn-primary btn-sm" onclick="sweepSaveSettings()" ${cfg.clamav_installed ? '' : 'disabled'}>Save</button>
  </div></div>
<div class="panel"><div class="panel-header"><h3 class="panel-title">Accounts</h3></div>
  ${rows.length === 0 ? '<div style="padding:2rem;text-align:center;color:var(--text-muted)">No accounts</div>' : `
  <div style="overflow-x:auto"><table class="table"><thead><tr><th>Account</th><th>Domain</th><th>Last scan</th><th>Open findings</th><th>High severity</th></tr></thead><tbody>
  ${rows.map(r => `<tr><td><strong>${esc(r.username)}</strong></td><td>${esc(r.domain)}</td><td>${esc(r.last_scan || 'never')}</td>
    <td>${r.open_findings > 0 ? Nova.badge(String(r.open_findings), 'yellow') : '0'}</td><td>${r.high_findings > 0 ? Nova.badge(String(r.high_findings), 'red') : '0'}</td></tr>`).join('')}
  </tbody></table></div>`}</div>`;
  };
  window.sweepSaveSettings = async () => {
    const res = await Nova.api('sweep', 'settings', { method: 'POST', body: { clamav: document.getElementById('sw-clam').checked } });
    Nova.toast(res?.success ? 'Saved' : (res?.message || 'Failed'), res?.success ? 'success' : 'error');
  };

  /* ══════════════════════════ Git Deploy ══════════════════════════ */
  async function gitRender(el) {
    const res = await Nova.api('gitdeploy', 'get');
    const g = res?.data || null;
    el.innerHTML = `
<div class="page-header"><h2 class="page-title">Git Deploy</h2>
  ${g ? '<button class="btn btn-primary btn-sm" onclick="gitDeployNow()">Deploy now</button>' : ''}</div>
<p class="text-muted" style="margin-bottom:1rem">Keep your site in step with a Git repository. Connect an https repository and a branch; the latest code is pulled into your site when you press Deploy now, and automatically on every push once you add the webhook.</p>
<div class="card" style="margin-bottom:1rem"><div class="card-header"><span class="card-title">${g ? 'Connected repository' : 'Connect a repository'}</span></div>
  <div style="padding:1rem;display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:.8rem">
    <div class="form-group" style="grid-column:1/-1"><label class="form-label">Repository address (https)</label><input id="gd-url" class="form-control" placeholder="https://github.com/you/site.git" value="${esc(g?.repo_url || '')}"></div>
    <div class="form-group"><label class="form-label">Branch</label><input id="gd-branch" class="form-control" value="${esc(g?.branch || 'main')}"></div>
    <div class="form-group"><label class="form-label">Folder inside public_html (optional)</label><input id="gd-sub" class="form-control" placeholder="leave empty for the whole site" value="${esc(g?.subdir || '')}"></div>
    <div class="form-group"><label class="form-label">Access token (private repositories)</label><input id="gd-token" type="password" class="form-control" placeholder="${g?.has_token ? 'stored - type to replace' : 'not needed for public repositories'}"></div>
    <div class="form-group" style="align-self:end"><label style="display:flex;gap:.4rem;align-items:center"><input type="checkbox" id="gd-over"> Replace existing files in the folder</label></div>
  </div>
  <div style="padding:0 1rem 1rem;display:flex;gap:.5rem">
    <button class="btn btn-primary btn-sm" onclick="gitSave()">${g ? 'Save & deploy' : 'Connect & deploy'}</button>
    ${g ? '<button class="btn btn-sm btn-danger" onclick="gitDisconnect()">Disconnect</button>' : ''}
  </div></div>
${g ? `
<div class="card" style="margin-bottom:1rem"><div class="card-header"><span class="card-title">Last deploy</span>${g.last_status ? Nova.badge(g.last_status === 'ok' ? 'OK' : 'Failed', g.last_status === 'ok' ? 'green' : 'red') : ''}</div>
  <div style="padding:1rem"><div style="font-size:.85rem;margin-bottom:.5rem">${esc(g.last_deploy || 'never')} ${g.last_commit ? ' - <code>' + esc(g.last_commit) + '</code>' : ''}</div>
  ${g.last_output ? `<pre style="max-height:200px;overflow:auto;font-size:.75rem;background:var(--bg3);padding:.7rem;border-radius:6px">${esc(g.last_output)}</pre>` : ''}</div></div>
<div class="card"><div class="card-header"><span class="card-title">Deploy on every push (webhook)</span></div>
  <div style="padding:1rem;font-size:.85rem">
    <p>In your repository settings add a webhook (JSON, push events only) with:</p>
    <div class="form-group"><label class="form-label">Payload URL</label><input class="form-control" readonly value="${esc(g.webhook_url)}" onclick="this.select()"></div>
    <div class="form-group"><label class="form-label">Secret</label><input class="form-control" readonly value="${esc(g.secret)}" onclick="this.select()"></div>
    <button class="btn btn-xs" onclick="gitNewSecret()">Create a new secret</button>
  </div></div>` : ''}`;
  }
  window.gitPage = async (el) => { el.innerHTML = '<div class="loading">Loading...</div>'; window._gitEl = el; await gitRender(el); };
  const gitDone = (res, okMsg) => {
    Nova.loadingDone?.();
    Nova.toast(res?.success ? (res.message || okMsg) : (res?.message || 'Failed'), res?.success ? 'success' : 'error');
    gitRender(window._gitEl);
  };
  window.gitSave = async () => {
    const v = (id) => document.getElementById(id)?.value ?? '';
    Nova.loading?.('Connecting and deploying...');
    gitDone(await Nova.api('gitdeploy', 'save', { method: 'POST', body: { repo_url: v('gd-url').trim(), branch: v('gd-branch').trim(), subdir: v('gd-sub').trim(), token: v('gd-token'), overwrite: document.getElementById('gd-over').checked } }), 'Deployed');
  };
  window.gitDeployNow = async () => { Nova.loading?.('Deploying...'); gitDone(await Nova.api('gitdeploy', 'deploy', { method: 'POST', body: {} }), 'Deployed'); };
  window.gitNewSecret = async () => gitDone(await Nova.api('gitdeploy', 'secret', { method: 'POST', body: {} }), 'New secret created');
  window.gitDisconnect = () => Nova.confirm('Disconnect the repository? Your site files stay as they are.', async () => gitDone(await Nova.api('gitdeploy', 'disconnect', { method: 'POST', body: {} }), 'Disconnected'), true);

  /* ══════════════════════════ Mail queue (admin) ══════════════════════════ */
  window.mailQueueAdminPage = async () => {
    const res = await Nova.api('mailqueue', 'list');
    if (!res?.success) return `<div class="page-header"><h1 class="page-title">Mail Queue</h1></div><div class="empty">${esc(res?.message || 'Could not read the mail queue')}</div>`;
    const msgs = res.data.messages;
    return `
<div class="page-header"><h1 class="page-title">Mail Queue</h1>
  <div class="page-actions"><button class="btn btn-sm" onclick="mqAct('flush')">Retry all now</button>
  <button class="btn btn-sm btn-danger" onclick="mqPurge()">Delete everything</button></div></div>
<div class="panel"><div class="panel-header"><h3 class="panel-title">Waiting to be delivered</h3><span class="badge badge-${msgs.length ? 'yellow' : 'green'}">${msgs.length} message${msgs.length === 1 ? '' : 's'}</span></div>
  ${msgs.length === 0 ? '<div style="padding:2rem;text-align:center;color:var(--text-muted)">The queue is empty - everything has been delivered.</div>' : `
  <div style="overflow-x:auto"><table class="table"><thead><tr><th>Queued</th><th>From</th><th>To</th><th>Why it is waiting</th><th>Size</th><th>Actions</th></tr></thead><tbody>
  ${msgs.map(m => `<tr><td>${esc(new Date(m.time * 1000).toLocaleString())}${m.queue === 'hold' ? '<br>' + Nova.badge('On hold', 'blue') : ''}</td>
    <td>${esc(m.sender || '(none)')}</td><td>${m.recipients.map(r => esc(r.address)).join('<br>')}</td>
    <td><small>${m.recipients.map(r => esc(r.reason || '-')).join('<br>')}</small></td><td>${Nova.bytes(m.size)}</td>
    <td style="white-space:nowrap"><button class="btn btn-xs" onclick="mqAct('retry','${esc(m.id)}')">Retry</button>
      <button class="btn btn-xs" onclick="mqAct('${m.queue === 'hold' ? 'release' : 'hold'}','${esc(m.id)}')">${m.queue === 'hold' ? 'Release' : 'Hold'}</button>
      <button class="btn btn-xs btn-danger" onclick="mqAct('delete','${esc(m.id)}')">Delete</button></td></tr>`).join('')}
  </tbody></table></div>`}</div>`;
  };
  window.mqAct = async (action, id) => {
    const res = await Nova.api('mailqueue', 'action', { method: 'POST', body: { action, id } });
    Nova.toast(res?.success ? 'Done' : (res?.message || 'Failed'), res?.success ? 'success' : 'error');
    if (window.adminPage) adminPage('mail-queue');
  };
  window.mqPurge = () => Nova.confirm('Delete EVERY message in the mail queue? This cannot be undone.', async () => {
    const res = await Nova.api('mailqueue', 'action', { method: 'POST', body: { action: 'purge', confirm_all: true } });
    Nova.toast(res?.success ? 'Queue emptied' : (res?.message || 'Failed'), res?.success ? 'success' : 'error');
    if (window.adminPage) adminPage('mail-queue');
  }, true);
})();
