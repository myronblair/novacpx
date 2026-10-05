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

  /* ══════════════════════════ Firewall (WAF) ══════════════════════════ */
  let _waf = null;
  window.wafPage = async (el) => {
    el.innerHTML = '<div class="loading">Loading...</div>';
    const res = await Nova.api('waf', 'get');
    if (!res?.success) { el.innerHTML = '<div class="empty">Could not load the firewall.</div>'; return; }
    _waf = res.data;
    const warn = _waf.web_server !== 'nginx'
      ? `<div class="alert alert-warning" style="margin-bottom:1rem">The firewall needs the nginx web server. This server uses <strong>${esc(_waf.web_server)}</strong>, so saving will be refused.</div>` : '';
    el.innerHTML = `
<div class="page-header"><h2 class="page-title">Firewall</h2>
  <button class="btn btn-primary btn-sm" onclick="wafSave()">Save &amp; apply</button></div>
${warn}
<div class="card" style="margin-bottom:1rem"><div style="padding:1rem">
  <label style="display:flex;gap:.5rem;align-items:center"><input type="checkbox" id="waf-on" ${_waf.mode === 'block' ? 'checked' : ''}>
    <strong>Block suspicious requests</strong></label>
  <div class="form-hint" style="margin-top:.4rem">Requests that match a rule below are refused with a 403 error before they reach your site. This is a first line of defence; it does not replace keeping your site's software up to date.</div>
</div></div>
<div class="card" style="margin-bottom:1rem">
  <div class="card-header"><span class="card-title">Rules</span></div>
  <div style="padding:1rem">${_waf.catalog.map(r => `
    <label style="display:flex;gap:.6rem;align-items:flex-start;margin-bottom:.7rem">
      <input type="checkbox" class="waf-rule" value="${esc(r.slug)}" ${_waf.rules.includes(r.slug) ? 'checked' : ''} style="margin-top:.2rem">
      <span><strong>${esc(r.name)}</strong><br><small style="color:var(--text-muted)">${esc(r.description)}</small></span></label>`).join('')}
  </div>
</div>
<div class="card">
  <div class="card-header"><span class="card-title">Pages the rules skip</span></div>
  <div style="padding:1rem">
    <textarea id="waf-exempt" class="form-control" rows="3" placeholder="/wp-admin&#10;/api/search">${esc((_waf.exempt || []).join('\n'))}</textarea>
    <div class="form-hint" style="margin-top:.4rem">One path per line. Everything under that path is left alone - use this if a rule blocks something legitimate, such as an editor that posts code.</div>
  </div>
</div>`;
  };
  window.wafSave = async () => {
    const body = {
      mode: document.getElementById('waf-on')?.checked ? 'block' : 'off',
      rules: [...document.querySelectorAll('.waf-rule:checked')].map(c => c.value),
      exempt: (document.getElementById('waf-exempt')?.value || '').split(/\r?\n/).map(x => x.trim()).filter(Boolean),
    };
    Nova.loading?.('Applying firewall...');
    const res = await Nova.api('waf', 'save', { method: 'POST', body });
    Nova.loadingDone?.();
    Nova.toast(res?.success ? res.message : (res?.message || 'Could not apply the firewall'), res?.success ? 'success' : 'error');
  };

  /* ══════════════════════════ Package tool lists ══════════════════════════ */
  const PKG_TOOLS = [['shield', 'Site Shield and firewall'], ['traffic', 'Traffic meter'], ['pulse', 'Uptime monitor'], ['sweep', 'Malware sweep'],
                     ['gitdeploy', 'Git Deploy'], ['docker', 'Docker apps'], ['wordpress', 'WordPress manager'], ['cron', 'Cron jobs'], ['backups', 'Backups']];
  /** Checkbox block for a package form; tools = JSON text from the package row (null/empty = everything). */
  window.pkgToolsHtml = (tools) => {
    let sel = null;
    try { sel = tools ? JSON.parse(tools) : null; } catch (e) { sel = null; }
    return `<div class="form-group" style="grid-column:1/-1"><label class="form-label">Tools included</label>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:.3rem">${PKG_TOOLS.map(([k, n]) =>
        `<label style="display:flex;gap:.4rem;align-items:center"><input type="checkbox" class="pkg-tool" value="${k}" ${(!sel || sel.includes(k)) ? 'checked' : ''}> ${esc(n)}</label>`).join('')}</div>
      <div class="form-hint">Customers on this package only see the ticked tools.</div></div>`;
  };
  window.pkgToolsCollect = () => [...document.querySelectorAll('.pkg-tool:checked')].map(c => c.value);

  /* ══════════════════════════ Account Transfer (admin) ══════════════════════════ */
  window.transferAdminPage = async () => {
    const [accts, log] = await Promise.all([Nova.api('accounts', 'list', { params: { per_page: 100 } }), Nova.api('transfer', 'log')]);
    const list = (accts?.data?.accounts || accts?.data || []);
    const rows = (log?.data || []);
    return `
<div class="page-header"><h1 class="page-title">Account Transfer</h1></div>
<div class="panel" style="margin-bottom:1rem"><div class="panel-header"><h3 class="panel-title">Send an account to another NovaCPX server</h3></div>
  <div style="padding:1rem">
    <div style="display:flex;gap:.6rem;flex-wrap:wrap;align-items:end">
      <div class="form-group" style="margin:0;min-width:260px"><label class="form-label">Account</label>
        <select id="tr-acct" class="form-control">${list.map(a => `<option value="${a.id}">${esc(a.username)} - ${esc(a.domain)}</option>`).join('')}</select></div>
      <button class="btn btn-primary" onclick="trExport()">Create transfer link</button>
    </div>
    <div id="tr-out" style="margin-top:.8rem"></div>
    <div class="form-hint" style="margin-top:.6rem">The link works once and for 2 hours. Paste it into <strong>Account Transfer</strong> on the other server. The other server must be able to reach this one over the internet.</div>
  </div></div>
<div class="panel" style="margin-bottom:1rem"><div class="panel-header"><h3 class="panel-title">Receive an account from another NovaCPX server</h3></div>
  <div style="padding:1rem">
    <input id="tr-link" class="form-control" placeholder="https://other-server:8882/api/transferdl/get?token=...">
    <div style="margin-top:.6rem"><button class="btn btn-primary" onclick="trImport()">Import account</button></div>
    <div id="tr-in" style="margin-top:.8rem"></div>
    <div class="form-hint" style="margin-top:.6rem">Carried over: website files, MySQL databases (with their passwords), the customer login, PHP version and package (matched by name). Not carried over: mailboxes, DNS records, SSL certificates, cron jobs, FTP users. If anything fails the new account is removed again.</div>
  </div></div>
<div class="panel"><div class="panel-header"><h3 class="panel-title">Recent transfers</h3></div>
  ${rows.length ? `<div style="overflow-x:auto"><table class="table"><thead><tr><th>When</th><th>Direction</th><th>Account</th><th>Status</th><th>Detail</th></tr></thead><tbody>
  ${rows.map(r => `<tr><td>${esc(r.created_at)}</td><td>${esc(r.direction)}</td><td>${esc(r.username)}</td><td>${Nova.badge(r.status, r.status === 'failed' ? 'red' : 'green')}</td><td><small>${esc(r.detail || '')}</small></td></tr>`).join('')}
  </tbody></table></div>` : '<div style="padding:1.5rem;color:var(--text-muted)">No transfers yet.</div>'}</div>`;
  };
  /** Start a background job and poll until it finishes; onDone(job) gets the finished job. */
  async function trRun(kind, body, out, working, onDone) {
    out.innerHTML = `<div class="loading">${working}</div>`;
    const res = await Nova.api('transfer', kind, { method: 'POST', body });
    if (!res?.success) { out.innerHTML = `<div class="alert alert-danger">${esc(res?.message || 'Failed')}</div>`; return; }
    const id = res.data.job;
    for (let i = 0; i < 2400; i++) {            // up to ~2 hours at 3 s
      await new Promise(r => setTimeout(r, 3000));
      if (!document.body.contains(out)) return;   // the admin left the page; the job carries on
      const j = await Nova.api('transfer', 'job', { params: { id } });
      if (j?.success && j.data.status === 'done') { onDone(j.data); return; }
      if (j?.success && j.data.status === 'failed') { out.innerHTML = `<div class="alert alert-danger">${esc(j.data.error || 'Failed')}</div>`; return; }
    }
    out.innerHTML = '<div class="alert alert-warning">Still running - check Recent transfers later.</div>';
  }
  window.trExport = () => {
    const out = document.getElementById('tr-out');
    trRun('export', { account_id: +document.getElementById('tr-acct').value }, out, 'Packing the account - this can take a few minutes...', (j) => {
      out.innerHTML = `<div class="alert alert-success">Ready (${fmtMb(j.result.size / 1048576)}), valid until ${esc(j.result.expires)}.</div>
        <input class="form-control" readonly onclick="this.select()" value="${esc(j.result.url)}">`;
    });
  };
  window.trImport = () => {
    const out = document.getElementById('tr-in');
    trRun('import', { link: document.getElementById('tr-link').value.trim() }, out, 'Downloading and building the account - this can take a few minutes...', (j) => {
      const d = j.result;
      out.innerHTML = `<div class="alert alert-success">Imported ${esc(d.username)} (${esc(d.domain)}) with ${d.databases.length} database(s).</div>
        <ul>${d.notes.map(n => `<li>${esc(n)}</li>`).join('')}</ul>`;
    });
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
    const acct = window._sweepAcct || null;
    const res = await Nova.api('sweep', 'status', acct ? { params: { account_id: acct } } : {});
    if (!res?.success) { el.innerHTML = `<div class="empty">${esc(res?.message || 'Could not load Sweep.')}</div>`; return; }
    const { run, findings, clamav, enabled } = res.data;
    if (enabled === false) { el.innerHTML = '<div class="page-header"><h2 class="page-title">Sweep</h2></div><div class="alert alert-warning">Malware scanning is switched off for this account. Ask your hosting provider to switch it on.</div>'; return; }
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
  window.sweepPage = async (el) => { el.innerHTML = '<div class="loading">Loading...</div>'; window._sweepAcct = null; window._sweepEl = el; await sweepRender(el); };
  window.sweepScan = async () => {
    const res = await Nova.api('sweep', 'scan', { method: 'POST', body: { account_id: window._sweepAcct || undefined } });
    Nova.toast(res?.message || 'Failed', res?.success ? 'success' : 'error');
    if (res?.success) setTimeout(() => sweepRender(window._sweepEl), 1500);
  };
  window.sweepAct = (act, id) => {
    const go = async () => {
      const res = await Nova.api('sweep', act, { method: 'POST', body: { finding_id: id, account_id: window._sweepAcct || undefined } });
      Nova.toast(res?.message || 'Failed', res?.success ? 'success' : 'error');
      sweepRender(window._sweepEl);
    };
    if (act === 'quarantine') Nova.confirm('Move this file out of your website into quarantine? You can restore it later.', go, true); else go();
  };

  const sweepRefresh = () => { if (window.adminPage) adminPage('sweep-overview'); else if (window.resellerNav) resellerNav('sweep'); };
  const sweepSwitch = (id, on) => `<label style="display:inline-flex;gap:.4rem;align-items:center;cursor:pointer"><input type="checkbox" ${on ? 'checked' : ''} onchange="sweepSetAccess(${id}, this.checked)"> <span class="text-muted">${on ? 'On' : 'Off'}</span></label>`;

  function clamCard(c, cfg) {
    if (!c) return '';
    const running = c.state === 'running';
    let body;
    if (running) {
      body = `<div class="alert alert-info">Working... this takes a few minutes. This page updates by itself.</div>
        <pre style="background:var(--bg);padding:.75rem;font-size:.75rem;max-height:160px;overflow:auto;white-space:pre-wrap">${esc(c.log || '')}</pre>`;
    } else if (!c.installed) {
      body = `<p class="text-muted">ClamAV is not installed on this server. It adds a real antivirus engine on top of the built-in pattern scan.${c.ram_mb && c.ram_mb < 1800 ? ' This server has ' + c.ram_mb + ' MB of RAM, so the faster background daemon will be skipped.' : ''}</p>
        ${c.state === 'failed' ? '<div class="alert alert-warning">The last install did not finish. Check the log below and try again.<pre style="font-size:.75rem;white-space:pre-wrap">' + esc(c.log || '') + '</pre></div>' : ''}
        <button class="btn btn-primary" onclick="sweepClam('clamav-install')">Install ClamAV on this server</button>`;
    } else {
      body = `<div style="display:flex;gap:1.5rem;flex-wrap:wrap;margin-bottom:.75rem">
          <div><div class="text-muted text-sm">Version</div><strong>${esc(c.version || '-')}</strong></div>
          <div><div class="text-muted text-sm">Scan mode</div><strong>${c.daemon ? 'Fast (daemon running)' : 'Standard (command line)'}</strong></div>
          <div><div class="text-muted text-sm">Signatures updated</div><strong>${c.signatures_updated ? esc(new Date(c.signatures_updated * 1000).toLocaleString()) : 'not yet'}</strong></div>
          <div><div class="text-muted text-sm">Auto-update</div><strong>${c.freshclam ? 'On' : 'Off'}</strong></div></div>
        <label style="display:flex;gap:.4rem;align-items:center;margin-bottom:.75rem"><input type="checkbox" id="sw-clam" ${cfg.clamav ? 'checked' : ''} onchange="sweepSaveSettings()"> Use ClamAV in scans</label>
        <button class="btn btn-sm" onclick="sweepClam('clamav-update')">Update signatures now</button>
        <button class="btn btn-sm btn-danger" onclick="sweepClamRemove()">Remove ClamAV</button>`;
    }
    return `<div class="panel" style="margin-bottom:1rem"><div class="panel-header"><h3 class="panel-title">ClamAV antivirus</h3>
      ${Nova.badge(running ? 'Working' : (c.installed ? 'Installed' : 'Not installed'), running ? 'yellow' : (c.installed ? 'green' : 'gray'))}</div>
      <div style="padding:1rem 1.25rem">${body}</div></div>`;
  }

  window.sweepAdminPage = async (mode) => {
    const isRes = mode === 'reseller';
    const calls = [Nova.api('sweep', 'overview'), Nova.api('sweep', 'access')];
    if (!isRes) calls.push(Nova.api('sweep', 'clamav-status'), Nova.api('sweep', 'settings'));
    const [ov, ac, cs, st] = await Promise.all(calls);
    const rows = ov?.data || [];
    const access = ac?.data || { resellers: [], users: [] };
    const clam = cs?.data || null, cfg = st?.data || {};
    if (clam && clam.state === 'running') setTimeout(() => sweepRefresh(), 5000);
    const resellers = access.resellers || [];
    return `
<div class="page-header"><h1 class="page-title">Malware Sweep</h1></div>
${isRes ? '' : `<div class="panel" style="margin-bottom:1rem"><div class="panel-header"><h3 class="panel-title">Built-in scan</h3></div>
  <div style="padding:1rem 1.25rem">Pattern scan: <strong>always on</strong> for every account that has Sweep switched on, nightly at 03:30.</div></div>
${clamCard(clam, cfg)}`}
${resellers.length ? `<div class="panel" style="margin-bottom:1rem"><div class="panel-header"><h3 class="panel-title">Resellers</h3><span class="form-hint">Off = the reseller and all their customers lose Sweep</span></div>
  <div style="overflow-x:auto"><table class="table"><thead><tr><th>Reseller</th><th>Email</th><th>Sweep</th></tr></thead><tbody>
  ${resellers.map(r => `<tr><td><strong>${esc(r.username)}</strong></td><td>${esc(r.email || '')}</td><td>${sweepSwitch(r.id, Number(r.enabled) === 1)}</td></tr>`).join('')}
  </tbody></table></div></div>` : ''}
<div class="panel"><div class="panel-header"><h3 class="panel-title">Accounts</h3></div>
  ${rows.length === 0 ? '<div style="padding:2rem;text-align:center;color:var(--text-muted)">No accounts</div>' : `
  <div style="overflow-x:auto"><table class="table"><thead><tr><th>Account</th><th>Domain</th><th>Last scan</th><th>Open findings</th><th>High severity</th><th>Sweep</th><th></th></tr></thead><tbody>
  ${rows.map(r => `<tr><td><strong>${esc(r.username)}</strong></td><td>${esc(r.domain)}</td><td>${esc(r.last_scan || 'never')}</td>
    <td>${r.open_findings > 0 ? Nova.badge(String(r.open_findings), 'yellow') : '0'}</td><td>${r.high_findings > 0 ? Nova.badge(String(r.high_findings), 'red') : '0'}</td>
    <td>${sweepSwitch(r.user_id, Number(r.enabled) === 1)}</td>
    <td><button class="btn btn-xs btn-primary" onclick="sweepManage(${r.id},'${esc(r.username)}')">Manage</button></td></tr>`).join('')}
  </tbody></table></div>`}</div>`;
  };
  window.sweepSaveSettings = async () => {
    const res = await Nova.api('sweep', 'settings', { method: 'POST', body: { clamav: document.getElementById('sw-clam').checked } });
    Nova.toast(res?.success ? 'Saved' : (res?.message || 'Failed'), res?.success ? 'success' : 'error');
  };
  window.sweepSetAccess = async (userId, enabled) => {
    const res = await Nova.api('sweep', 'set-access', { method: 'POST', body: { user_id: userId, enabled } });
    Nova.toast(res?.message || (res?.success ? 'Saved' : 'Failed'), res?.success ? 'success' : 'error');
    sweepRefresh();
  };
  window.sweepClam = async (act) => {
    const res = await Nova.api('sweep', act, { method: 'POST', body: {} });
    Nova.toast(res?.message || 'Failed', res?.success ? 'success' : 'error');
    setTimeout(sweepRefresh, 1500);
  };
  window.sweepClamRemove = () => Nova.confirm('Remove ClamAV from this server? Sweep keeps working with the built-in pattern scan only.', () => sweepClam('clamav-remove'), true);
  window.sweepManage = async (accountId, name) => {
    Nova.modal('Sweep - ' + name, '<div id="sweep-manage" style="min-width:min(900px,90vw)"><div class="loading">Loading...</div></div>');
    window._sweepAcct = accountId;
    window._sweepEl = document.getElementById('sweep-manage');
    await sweepRender(window._sweepEl);
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
  /* ══════════════════════════ Web Terminal (admin) ══════════════════════════ */
  window.terminalAdminPage = async () => {
    const res = await Nova.api('terminal', 'status');
    if (!res?.success) return `<div class="page-header"><h1 class="page-title">Web Terminal</h1></div><div class="empty">${esc(res?.message || 'Could not load')}</div>`;
    const d = res.data, sv = d.service || {};
    const nginxOk = sv.webserver === 'nginx';
    let action = '';
    if (!d.enabled) {
      action = !nginxOk ? `<div class="alert alert-warning">The web terminal needs the nginx web server. This server uses <strong>${esc(sv.webserver)}</strong>.</div>`
        : !d.twofa ? `<div class="alert alert-warning">Turn on two-factor authentication for your admin login first (Security &gt; 2FA). The terminal will not run without it.</div>`
        : `<button class="btn btn-primary" onclick="termEnable()">Enable web terminal</button>
           <div class="form-hint" style="margin-top:.6rem">Installs the ttyd program, starts it, and adds it to the admin panel at <code>/terminal/</code>. Nothing is reachable until you enter an authenticator code.</div>`;
    } else {
      action = `<div style="display:flex;gap:.6rem;align-items:center;flex-wrap:wrap">
          <input id="term-code" class="form-control" style="max-width:11rem" inputmode="numeric" maxlength="6" placeholder="6-digit code" onkeydown="if(event.key==='Enter')termUnlock()">
          <button class="btn btn-primary" onclick="termUnlock()">Unlock &amp; open terminal</button>
          <button class="btn" onclick="termOpen()" ${d.unlocked_for ? '' : 'disabled'}>Open (unlocked ${d.unlocked_for ? Math.ceil(d.unlocked_for / 60) + ' min left' : 'no'})</button>
          <button class="btn btn-danger" style="margin-left:auto" onclick="termDisable()">Disable</button></div>
        <div class="form-hint" style="margin-top:.6rem">Root shell in your browser. A fresh code opens it for ${Math.round(d.unlock_seconds / 60)} minutes; one session at a time; idle for 15 minutes or 4 hours total ends it. Everything typed and shown is recorded below.</div>`;
    }
    const rec = d.recordings || [];
    return `
<div class="page-header"><h1 class="page-title">Web Terminal</h1></div>
<div class="panel" style="margin-bottom:1.25rem"><div class="panel-header"><h3 class="panel-title">Status</h3>
  ${Nova.badge(d.enabled ? (sv.active ? 'Running' : 'Enabled, not running') : 'Off', d.enabled && sv.active ? 'green' : (d.enabled ? 'yellow' : 'gray'))}</div>
  <div style="padding:1rem 1.25rem">${action}</div></div>
<div class="panel"><div class="panel-header"><h3 class="panel-title">Recorded sessions</h3><span class="form-hint">Kept 90 days</span></div>
  ${rec.length ? `<div style="overflow-x:auto"><table class="table"><thead><tr><th>Started</th><th>Size</th><th></th></tr></thead><tbody>
  ${rec.map(r => `<tr><td>${esc(new Date(r.time * 1000).toLocaleString())}</td><td>${Nova.bytes(r.size)}</td>
    <td><button class="btn btn-xs" onclick="termView('${esc(r.name)}')">View</button></td></tr>`).join('')}</tbody></table></div>`
  : '<div style="padding:1.5rem;color:var(--text-muted)">No sessions recorded yet.</div>'}</div>`;
  };
  window.termOpen = () => { window.open('/terminal/', '_blank', 'noopener'); };
  window.termUnlock = async () => {
    const code = (document.getElementById('term-code')?.value || '').trim();
    const res = await Nova.api('terminal', 'unlock', { method: 'POST', body: { code } });
    if (!res?.success) return Nova.toast(res?.message || 'Could not unlock', 'error');
    Nova.toast('Unlocked', 'success');
    termOpen();
    if (window.adminPage) adminPage('terminal');
  };
  window.termEnable = async () => {
    Nova.loading('Installing and starting the terminal...');
    const res = await Nova.api('terminal', 'enable', { method: 'POST', body: {} });
    Nova.loadingDone();
    Nova.toast(res?.success ? 'Web terminal enabled' : (res?.message || 'Failed'), res?.success ? 'success' : 'error');
    if (window.adminPage) adminPage('terminal');
  };
  window.termDisable = () => Nova.confirm('Switch the web terminal off? Open sessions end.', async () => {
    const res = await Nova.api('terminal', 'disable', { method: 'POST', body: {} });
    Nova.toast(res?.success ? 'Web terminal disabled' : (res?.message || 'Failed'), res?.success ? 'success' : 'error');
    if (window.adminPage) adminPage('terminal');
  });
  window.termView = async (name) => {
    const res = await Nova.api('terminal', 'log', { params: { name } });
    if (!res?.success) return Nova.toast(res?.message || 'Could not read the recording', 'error');
    Nova.modal('Session ' + name, `${res.data.truncated ? '<div class="form-hint">Showing the last 200 KB.</div>' : ''}<pre style="background:var(--bg);padding:1rem;font-size:.78rem;overflow:auto;max-height:420px;white-space:pre-wrap">${esc(res.data.text)}</pre>`);
  };
  /* ══════════════════════════ Two-factor authentication (own login) ══════════════════════════ */
  function twofaLoadQr() {
    if (window.qrcode) return Promise.resolve();
    return new Promise((ok, fail) => {
      const s = document.createElement('script');
      s.src = 'https://cdnjs.cloudflare.com/ajax/libs/qrcode-generator/1.4.4/qrcode.min.js';
      s.onload = ok; s.onerror = fail; document.head.appendChild(s);
    });
  }

  window.twofaRefresh = () => {
    if (window.adminPage) adminPage('twofa');
    else if (window.resellerNav) resellerNav('security');
    else if (window.userNav) userNav('security');
  };
  window.twofaSecurityPage = async (el) => {
    el.innerHTML = '<div class="page-header"><h2 class="page-title">Two-Factor Authentication</h2></div>' + await window.twofaSelfCard();
  };

  window.twofaSelfCard = async () => {
    const st = await Nova.api('totp', 'status');
    const on = !!st?.data?.enabled;
    return `<div class="panel" style="margin-bottom:1.25rem"><div class="panel-header"><h3 class="panel-title">Your two-factor authentication</h3>
      ${Nova.badge(on ? 'On' : 'Off', on ? 'green' : 'gray')}</div>
      <div style="padding:1rem 1.25rem" id="twofa-self">
      ${on ? `<p class="text-muted">Signing in asks for a code from your authenticator app. Keep your backup codes somewhere safe.</p>
        <div style="display:flex;gap:.5rem;flex-wrap:wrap">
          <button class="btn btn-sm" onclick="twofaRegen()">New backup codes</button>
          <button class="btn btn-sm btn-danger" onclick="twofaDisable()">Turn off 2FA</button></div>`
      : `<p class="text-muted">Add a second step to your login with an authenticator app (Google Authenticator, Authy, 1Password, Microsoft Authenticator...). Some features, like the Web Terminal, need it.</p>
        <button class="btn btn-primary" onclick="twofaSetup()">Set up 2FA</button>`}
      </div></div>`;
  };

  window.twofaSetup = async () => {
    const res = await Nova.api('totp', 'setup', { method: 'POST', body: {} });
    if (!res?.success) return Nova.toast(res?.message || 'Could not start setup', 'error');
    const { secret, otpauth } = res.data;
    const box = document.getElementById('twofa-self');
    box.innerHTML = `<p>1. Scan this with your authenticator app (or type the key in by hand).</p>
      <div id="twofa-qr" style="background:#fff;display:inline-block;padding:10px;border-radius:8px;min-width:120px;min-height:120px"></div>
      <p style="margin-top:.5rem">Key: <code style="user-select:all">${esc(secret)}</code></p>
      <p>2. Enter the 6-digit code it shows.</p>
      <div style="display:flex;gap:.5rem;flex-wrap:wrap"><input id="twofa-code" class="form-control" style="max-width:11rem" inputmode="numeric" maxlength="6" placeholder="123456" onkeydown="if(event.key==='Enter')twofaEnable()">
      <button class="btn btn-primary" onclick="twofaEnable()">Turn on 2FA</button>
      <button class="btn" onclick="twofaRefresh()">Cancel</button></div>`;
    try {
      await twofaLoadQr();
      const qr = window.qrcode(0, 'M'); qr.addData(otpauth); qr.make();
      document.getElementById('twofa-qr').innerHTML = qr.createSvgTag(5, 0);
    } catch (e) {
      document.getElementById('twofa-qr').innerHTML = '<small style="color:#333">QR code could not load - type the key in by hand.</small>';
    }
  };

  const twofaShowCodes = (codes, title) => Nova.modal(title,
    `<p>Save these backup codes now - each works once if you lose your phone, and they will not be shown again.</p>
     <pre style="background:var(--bg);padding:1rem;font-size:1rem;user-select:all">${codes.map(esc).join('\n')}</pre>`,
    `<button class="btn btn-primary" onclick="this.closest('.modal-overlay').remove();twofaRefresh()">I saved them</button>`);

  window.twofaEnable = async () => {
    const code = (document.getElementById('twofa-code')?.value || '').trim();
    const res = await Nova.api('totp', 'enable', { method: 'POST', body: { code } });
    if (!res?.success) return Nova.toast(res?.message || 'Code incorrect', 'error');
    twofaShowCodes(res.data.backup_codes || [], '2FA is on');
  };
  window.twofaRegen = () => {
    const code = prompt('Enter a current code from your authenticator app:');
    if (!code) return;
    Nova.api('totp', 'regen-backup-codes', { method: 'POST', body: { code: code.trim() } }).then(res => {
      if (!res?.success) return Nova.toast(res?.message || 'Code incorrect', 'error');
      twofaShowCodes(res.data.backup_codes || [], 'New backup codes');
    });
  };
  window.twofaDisable = () => {
    const password = prompt('Enter your password to turn off 2FA:');
    if (!password) return;
    Nova.api('totp', 'disable', { method: 'POST', body: { password } }).then(res => {
      Nova.toast(res?.success ? '2FA turned off' : (res?.message || 'Failed'), res?.success ? 'success' : 'error');
      if (res?.success) twofaRefresh();
    });
  };
  /* ══════════════════════════ Sign-in with a 2FA code (reseller and customer panels) ══════════════════════════ */
  let _login2fa = null;
  window.novaDoLogin = async (port) => {
    const err = document.getElementById('li-err');
    const codeEl = document.getElementById('li-totp');
    const creds = (_login2fa && codeEl)
      ? { ..._login2fa, totp_code: codeEl.value.replace(/\s+/g, '') }
      : { username: document.getElementById('li-user')?.value, password: document.getElementById('li-pass')?.value };
    Nova.loading('Signing in...');
    const res = await Nova.api('auth', 'login', { method: 'POST', body: creds });
    Nova.loadingDone();
    if (res?.success) {
      if (res.data?.portal_url && !res.data.portal_url.includes(':' + port)) location.href = res.data.portal_url;
      else location.reload();
    } else if (res?.totp_required) {
      _login2fa = creds;
      const passGroup = document.getElementById('li-pass')?.closest('.form-group');
      document.getElementById('li-user')?.closest('.form-group')?.style.setProperty('display', 'none');
      passGroup?.style.setProperty('display', 'none');
      const g = document.createElement('div');
      g.className = 'form-group';
      g.innerHTML = '<label class="form-label">2FA code</label><input id="li-totp" type="text" class="form-control" inputmode="numeric" autocomplete="one-time-code" maxlength="9" placeholder="6-digit code or a backup code">';
      passGroup?.after(g);
      if (err) err.style.display = 'none';
      document.getElementById('li-totp')?.focus();
    } else if (err) {
      err.textContent = res?.message || 'Login failed'; err.style.display = 'block';
    }
  };
})();
