<?php
// NovaCPX User Panel — End-user hosting dashboard
// Design: Horizontal feature cards with usage rings, NOT cPanel icon grid
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>NovaCPX — My Hosting</title>
<link rel="icon" type="image/svg+xml" href="/assets/img/favicon.svg">
<link rel="stylesheet" href="/assets/css/nova.css">
<style>
/* ── User panel specific ─────────────────────────────── */
.feature-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
  gap: 1rem;
}
.feature-card {
  background: var(--bg2);
  border: 1px solid var(--border);
  border-radius: 12px;
  padding: 1.25rem;
  text-decoration: none;
  color: var(--text);
  display: flex;
  align-items: flex-start;
  gap: 1rem;
  transition: border-color .15s, transform .1s;
  cursor: pointer;
}
.feature-card:hover {
  border-color: var(--primary);
  transform: translateY(-1px);
}
.feature-icon {
  width: 44px; height: 44px; flex-shrink: 0;
  border-radius: 10px;
  display: flex; align-items: center; justify-content: center;
}
.fi-purple { background: rgba(99,102,241,.15); color: var(--primary); }
.fi-sky    { background: rgba(14,165,233,.15);  color: var(--sky); }
.fi-green  { background: rgba(16,185,129,.15);  color: var(--green); }
.fi-yellow { background: rgba(245,158,11,.15);  color: var(--yellow); }
.fi-red    { background: rgba(239,68,68,.15);   color: var(--red); }
.fi-pink   { background: rgba(236,72,153,.15);  color: #f472b6; }
.fi-teal   { background: rgba(20,184,166,.15);  color: #2dd4bf; }
.fi-orange { background: rgba(249,115,22,.15);  color: #fb923c; }
.feature-icon svg { width: 22px; height: 22px; }
.feature-info { flex: 1; min-width: 0; }
.feature-name { font-weight: 600; font-size: .9rem; margin-bottom: .2rem; }
.feature-desc { font-size: .78rem; color: var(--text-muted); line-height: 1.4; }
.feature-meta { font-size: .75rem; color: var(--primary); margin-top: .3rem; }

/* Usage ring */
.usage-rings {
  display: flex; gap: 2rem; align-items: center;
  background: var(--bg2); border: 1px solid var(--border);
  border-radius: 12px; padding: 1.25rem 1.5rem; margin-bottom: 1.5rem;
}
.ring-item { text-align: center; }
.ring-label { font-size: .72rem; text-transform: uppercase; letter-spacing: .06em; color: var(--text-muted); margin-top: .5rem; }
.ring-val   { font-size: .85rem; font-weight: 600; margin-top: .15rem; }
svg.ring { transform: rotate(-90deg); }
svg.ring circle { transition: stroke-dashoffset .5s; }

/* Breadcrumb / section tabs */
.section-header { display: flex; align-items: center; gap: 1rem; margin-bottom: 1.25rem; }
.section-header h2 { font-size: 1rem; font-weight: 700; flex: 1; }
</style>
</head>
<body>

<div class="panel-layout" id="app" style="display:none">
  <aside class="sidebar" id="sidebar">
    <div class="sidebar-brand">
      <svg class="logo-icon" viewBox="0 0 40 40" fill="none">
        <circle cx="20" cy="20" r="18" stroke="url(#ulg1)" stroke-width="2"/>
        <path d="M12 28 L20 8 L28 28" stroke="url(#ulg2)" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"/>
        <path d="M14 22 H26" stroke="url(#ulg2)" stroke-width="2" stroke-linecap="round"/>
        <defs>
          <linearGradient id="ulg1" x1="2" y1="2" x2="38" y2="38"><stop offset="0%" stop-color="#6366f1"/><stop offset="100%" stop-color="#0ea5e9"/></linearGradient>
          <linearGradient id="ulg2" x1="12" y1="8" x2="28" y2="28"><stop offset="0%" stop-color="#6366f1"/><stop offset="100%" stop-color="#0ea5e9"/></linearGradient>
        </defs>
      </svg>
      <span class="logo-text">Nova<strong>CPX</strong></span>
    </div>
    <nav>
      <div class="sidebar-section">
        <div class="sidebar-section-label">My Account</div>
        <a href="#" class="sidebar-link active" data-page="home">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/></svg> Home
        </a>
        <a href="#" class="sidebar-link" data-page="domains">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/></svg> Domains
        </a>
        <a href="#" class="sidebar-link" data-page="files">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/></svg> File Manager
        </a>
      </div>
      <div class="sidebar-section">
        <div class="sidebar-section-label">Email</div>
        <a href="#" class="sidebar-link" data-page="email-accounts">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg> Email Accounts
        </a>
        <a href="#" class="sidebar-link" data-page="forwarders">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="17 1 21 5 17 9"/><path d="M3 11V9a4 4 0 0 1 4-4h14"/><polyline points="7 23 3 19 7 15"/><path d="M21 13v2a4 4 0 0 1-4 4H3"/></svg> Forwarders
        </a>
        <a href="#" class="sidebar-link" data-page="autoresponders">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg> Autoresponders
        </a>
      </div>
      <div class="sidebar-section">
        <div class="sidebar-section-label">Databases</div>
        <a href="#" class="sidebar-link" data-page="mysql">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><ellipse cx="12" cy="5" rx="9" ry="3"/><path d="M21 12c0 1.66-4 3-9 3s-9-1.34-9-3"/><path d="M3 5v14c0 1.66 4 3 9 3s9-1.34 9-3V5"/></svg> MySQL
        </a>
        <a href="#" class="sidebar-link" data-page="postgres">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><ellipse cx="12" cy="5" rx="9" ry="3"/><path d="M21 12c0 1.66-4 3-9 3s-9-1.34-9-3"/><path d="M3 5v14c0 1.66 4 3 9 3s9-1.34 9-3V5"/></svg> PostgreSQL
        </a>
      </div>
      <div class="sidebar-section">
        <div class="sidebar-section-label">Advanced</div>
        <a href="#" class="sidebar-link" data-page="ftp">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="4 17 10 11 4 5"/><line x1="12" y1="19" x2="20" y2="19"/></svg> FTP Accounts
        </a>
        <a href="#" class="sidebar-link" data-page="dns">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="16.5" y1="9.4" x2="7.5" y2="4.21"/><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/></svg> DNS Editor
        </a>
        <a href="#" class="sidebar-link" data-page="ssl">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg> SSL / TLS
        </a>
        <a href="#" class="sidebar-link" data-page="php-config">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="16 18 22 12 16 6"/><polyline points="8 6 2 12 8 18"/></svg> PHP Config
        </a>
        <a href="#" class="sidebar-link" data-page="cron">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg> Cron Jobs
        </a>
        <a href="#" class="sidebar-link" data-page="backups">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg> Backups
        </a>
        <a href="#" class="sidebar-link" data-page="logs">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/></svg> Error Logs
        </a>
      </div>
    </nav>
    <div class="sidebar-user">
      <div class="sidebar-user-info">
        <div class="avatar" id="user-avatar">U</div>
        <div><div class="user-name" id="user-name">User</div><div class="user-role" id="user-domain">example.com</div></div>
        <a href="#" id="logout-btn" class="btn btn-ghost btn-sm btn-icon" title="Logout" style="margin-left:auto">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
        </a>
      </div>
    </div>
  </aside>

  <div class="main-content">
    <header class="topbar">
      <div class="topbar-title" id="page-title">My Hosting</div>
      <div class="topbar-actions">
        <span id="account-domain" class="text-muted text-sm"></span>
      </div>
    </header>
    <div class="page-content" id="page-content"></div>
  </div>
</div>

<div id="auth-check" style="display:flex;align-items:center;justify-content:center;min-height:100vh">
  <div style="text-align:center;color:var(--text-muted)">Loading…</div>
</div>

<script src="/assets/js/nova.js"></script>
<script>
(async () => {
  const me = await Nova.api('auth', 'me');
  if (!me?.success) { location.href = '/?redirect=/user/'; return; }

  document.getElementById('auth-check').style.display = 'none';
  document.getElementById('app').style.display = '';
  document.getElementById('user-name').textContent = me.data.username;
  document.getElementById('user-avatar').textContent = me.data.username[0].toUpperCase();

  document.getElementById('logout-btn').addEventListener('click', async e => {
    e.preventDefault();
    await Nova.api('auth', 'logout', { method: 'POST' });
    location.href = '/';
  });

  function ring(pct, color, r = 28) {
    const circ = 2 * Math.PI * r;
    const offset = circ * (1 - pct / 100);
    return `<svg class="ring" width="${r*2+8}" height="${r*2+8}" viewBox="0 0 ${r*2+8} ${r*2+8}">
      <circle cx="${r+4}" cy="${r+4}" r="${r}" fill="none" stroke="var(--border)" stroke-width="5"/>
      <circle cx="${r+4}" cy="${r+4}" r="${r}" fill="none" stroke="${color}" stroke-width="5"
        stroke-dasharray="${circ}" stroke-dashoffset="${offset}" stroke-linecap="round"/>
    </svg>`;
  }

  const homePage = () => `
<div class="usage-rings">
  <div>
    <h2 style="font-size:1.1rem;font-weight:700">My Hosting</h2>
    <p class="text-muted text-sm">example.com &nbsp;·&nbsp; Active</p>
  </div>
  <div style="margin-left:auto;display:flex;gap:2rem">
    <div class="ring-item">${ring(45,'#6366f1')}<div class="ring-label">Disk</div><div class="ring-val">2.3 GB / 5 GB</div></div>
    <div class="ring-item">${ring(22,'#0ea5e9')}<div class="ring-label">Bandwidth</div><div class="ring-val">2.2 GB / 10 GB</div></div>
    <div class="ring-item">${ring(60,'#10b981')}<div class="ring-label">Email</div><div class="ring-val">6 / 10</div></div>
    <div class="ring-item">${ring(30,'#f59e0b')}<div class="ring-label">Databases</div><div class="ring-val">3 / 10</div></div>
  </div>
</div>

<div class="section-header"><h2>Quick Access</h2></div>
<div class="feature-grid">
  ${[
    { page:'files',         icon:'folder',    color:'fi-yellow', name:'File Manager',    desc:'Upload, edit, and manage your website files and directories.' },
    { page:'email-accounts',icon:'mail',      color:'fi-sky',    name:'Email Accounts',  desc:'Create and manage mailboxes for your domains.' },
    { page:'mysql',         icon:'db',        color:'fi-green',  name:'MySQL Databases',  desc:'Create databases and users for your PHP applications.' },
    { page:'postgres',      icon:'db',        color:'fi-teal',   name:'PostgreSQL',       desc:'Manage PostgreSQL databases and connections.' },
    { page:'domains',       icon:'globe',     color:'fi-purple', name:'Domains',          desc:'Add subdomains, addon domains, and domain aliases.' },
    { page:'dns',           icon:'dns',       color:'fi-orange', name:'DNS Editor',       desc:'Manage A, CNAME, MX, TXT, and SRV records.' },
    { page:'ssl',           icon:'lock',      color:'fi-green',  name:'SSL / TLS',        desc:'Issue free Let\'s Encrypt certificates for your domains.' },
    { page:'ftp',           icon:'ftp',       color:'fi-pink',   name:'FTP Accounts',     desc:'Create FTP users with directory access controls.' },
    { page:'php-config',    icon:'code',      color:'fi-purple', name:'PHP Config',       desc:'Switch PHP version and configure php.ini settings per domain.' },
    { page:'cron',          icon:'clock',     color:'fi-yellow', name:'Cron Jobs',        desc:'Schedule automated tasks on any interval.' },
    { page:'forwarders',    icon:'forward',   color:'fi-sky',    name:'Email Forwarders', desc:'Forward emails from one address to another.' },
    { page:'backups',       icon:'backup',    color:'fi-red',    name:'Backups',          desc:'Create full or partial backups and restore files.' },
  ].map(f => `
    <div class="feature-card" onclick="loadUserPage('${f.page}')">
      <div class="feature-icon ${f.color}">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          ${svgPath(f.icon)}
        </svg>
      </div>
      <div class="feature-info">
        <div class="feature-name">${f.name}</div>
        <div class="feature-desc">${f.desc}</div>
      </div>
    </div>`).join('')}
</div>`;

  function svgPath(icon) {
    const p = {
      folder:'<path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/>',
      mail:'<path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/>',
      db:'<ellipse cx="12" cy="5" rx="9" ry="3"/><path d="M21 12c0 1.66-4 3-9 3s-9-1.34-9-3"/><path d="M3 5v14c0 1.66 4 3 9 3s9-1.34 9-3V5"/>',
      globe:'<circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/>',
      dns:'<line x1="16.5" y1="9.4" x2="7.5" y2="4.21"/><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/>',
      lock:'<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>',
      ftp:'<polyline points="4 17 10 11 4 5"/><line x1="12" y1="19" x2="20" y2="19"/>',
      code:'<polyline points="16 18 22 12 16 6"/><polyline points="8 6 2 12 8 18"/>',
      clock:'<circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>',
      forward:'<polyline points="17 1 21 5 17 9"/><path d="M3 11V9a4 4 0 0 1 4-4h14"/>',
      backup:'<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/>',
    };
    return p[icon] || '';
  }

  const pages = { home: homePage };
  Nova.initNav(pages);
  document.getElementById('page-content').innerHTML = homePage();

  window.loadUserPage = (page) => Nova.loadPage(page, pages);
})();
</script>
</body>
</html>
