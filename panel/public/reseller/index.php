<?php
// NovaCPX Reseller Panel — port 8881
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>NovaCPX Reseller</title>
<link rel="icon" type="image/svg+xml" href="/assets/img/favicon.svg">
<link rel="stylesheet" href="/assets/css/nova.css">
</head>
<body>

<div class="panel-layout" id="app" style="display:none">
  <aside class="sidebar" id="sidebar">
    <div class="sidebar-brand">
      <svg class="logo-icon" viewBox="0 0 40 40" fill="none">
        <circle cx="20" cy="20" r="18" stroke="url(#rlg1)" stroke-width="2"/>
        <path d="M12 28 L20 8 L28 28" stroke="url(#rlg2)" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"/>
        <path d="M14 22 H26" stroke="url(#rlg2)" stroke-width="2" stroke-linecap="round"/>
        <defs>
          <linearGradient id="rlg1" x1="2" y1="2" x2="38" y2="38"><stop offset="0%" stop-color="#6366f1"/><stop offset="100%" stop-color="#0ea5e9"/></linearGradient>
          <linearGradient id="rlg2" x1="12" y1="8" x2="28" y2="28"><stop offset="0%" stop-color="#6366f1"/><stop offset="100%" stop-color="#0ea5e9"/></linearGradient>
        </defs>
      </svg>
      <span class="logo-text">Nova<strong>CPX</strong> <small style="font-size:.65rem;color:var(--text-muted)">Reseller</small></span>
    </div>
    <nav>
      <div class="sidebar-section">
        <div class="sidebar-section-label">Overview</div>
        <a href="#" class="sidebar-link active" data-page="dashboard">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/></svg> Dashboard
        </a>
        <a href="#" class="sidebar-link" data-page="resource-usage">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg> Resource Usage
        </a>
      </div>
      <div class="sidebar-section">
        <div class="sidebar-section-label">Accounts</div>
        <a href="#" class="sidebar-link" data-page="accounts">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg> My Accounts
        </a>
        <a href="#" class="sidebar-link" data-page="create-account">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><line x1="19" y1="8" x2="19" y2="14"/><line x1="22" y1="11" x2="16" y2="11"/></svg> Create Account
        </a>
        <a href="#" class="sidebar-link" data-page="suspended">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="4.93" y1="4.93" x2="19.07" y2="19.07"/></svg> Suspended
        </a>
      </div>
      <div class="sidebar-section">
        <div class="sidebar-section-label">Plans</div>
        <a href="#" class="sidebar-link" data-page="packages">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/></svg> My Packages
        </a>
        <a href="#" class="sidebar-link" data-page="create-package">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg> Create Package
        </a>
      </div>
      <div class="sidebar-section">
        <div class="sidebar-section-label">DNS & Domains</div>
        <a href="#" class="sidebar-link" data-page="dns">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/></svg> DNS Zones
        </a>
        <a href="#" class="sidebar-link" data-page="nameservers">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg> Nameservers
        </a>
      </div>
      <div class="sidebar-section">
        <div class="sidebar-section-label">Branding</div>
        <a href="#" class="sidebar-link" data-page="branding">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M12 8v4l3 3"/></svg> White Label
        </a>
        <a href="#" class="sidebar-link" data-page="emails">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg> Email Templates
        </a>
      </div>
      <div class="sidebar-section">
        <div class="sidebar-section-label">Reports</div>
        <a href="#" class="sidebar-link" data-page="bandwidth">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg> Bandwidth Report
        </a>
        <a href="#" class="sidebar-link" data-page="logs">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/></svg> Account Logs
        </a>
      </div>
    </nav>
    <div class="sidebar-user">
      <div class="sidebar-user-info">
        <div class="avatar" id="user-avatar">R</div>
        <div><div class="user-name" id="user-name">Reseller</div><div class="user-role">Reseller Account</div></div>
        <a href="#" id="logout-btn" class="btn btn-ghost btn-sm btn-icon" title="Logout" style="margin-left:auto">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
        </a>
      </div>
    </div>
  </aside>

  <div class="main-content">
    <header class="topbar">
      <div class="topbar-title" id="page-title">Reseller Dashboard</div>
    </header>
    <div class="page-content" id="page-content"></div>
  </div>
</div>

<!-- Auth guard / Inline login -->
<div id="auth-check" style="display:flex;align-items:center;justify-content:center;min-height:100vh;background:var(--bg)">
  <div style="width:100%;max-width:400px;padding:1.5rem">
    <div style="text-align:center;margin-bottom:1.5rem">
      <svg viewBox="0 0 40 40" fill="none" style="width:40px;height:40px;margin:0 auto 1rem">
        <circle cx="20" cy="20" r="18" stroke="url(#rlga)" stroke-width="2"/>
        <path d="M12 28 L20 8 L28 28" stroke="url(#rlgb)" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"/>
        <path d="M14 22 H26" stroke="url(#rlgb)" stroke-width="2" stroke-linecap="round"/>
        <defs>
          <linearGradient id="rlga" x1="2" y1="2" x2="38" y2="38"><stop offset="0%" stop-color="#6366f1"/><stop offset="100%" stop-color="#0ea5e9"/></linearGradient>
          <linearGradient id="rlgb" x1="12" y1="8" x2="28" y2="28"><stop offset="0%" stop-color="#6366f1"/><stop offset="100%" stop-color="#0ea5e9"/></linearGradient>
        </defs>
      </svg>
      <div style="font-size:1.4rem;font-weight:300">Nova<strong style="font-weight:700;background:linear-gradient(135deg,#6366f1,#0ea5e9);-webkit-background-clip:text;-webkit-text-fill-color:transparent">CPX</strong></div>
      <div style="font-size:.78rem;color:var(--text-muted);margin-top:.25rem;text-transform:uppercase;letter-spacing:.1em">Reseller Panel · Port 8881</div>
    </div>
    <div class="card">
      <div class="card-body">
        <div id="login-err" class="alert alert-error" style="display:none"></div>
        <form id="login-form">
          <div class="form-group"><label>Username or Email</label><input type="text" id="l-user" autofocus required></div>
          <div class="form-group"><label>Password</label><input type="password" id="l-pass" required></div>
          <button type="submit" class="btn btn-primary btn-full" id="l-btn">Sign In to Reseller Panel</button>
        </form>
      </div>
    </div>
  </div>
</div>

<script src="/assets/js/nova.js"></script>
<script>
(async () => {
  // Inline login on port 8881
  document.getElementById('login-form').addEventListener('submit', async e => {
    e.preventDefault();
    const btn = document.getElementById('l-btn');
    const err = document.getElementById('login-err');
    btn.disabled = true; btn.textContent = 'Signing in…'; err.style.display = 'none';
    const res = await Nova.api('auth', 'login', {
      method: 'POST',
      body: { username: document.getElementById('l-user').value, password: document.getElementById('l-pass').value }
    });
    if (res?.success && ['reseller','admin'].includes(res.data?.user?.role)) {
      location.reload();
    } else {
      err.textContent = res?.message || 'Invalid credentials or insufficient role';
      err.style.display = '';
      btn.disabled = false; btn.textContent = 'Sign In to Reseller Panel';
    }
  });

  const me = await Nova.api('auth', 'me');
  if (!me?.success || !['reseller','admin'].includes(me.data.role)) { return; }

  document.getElementById('auth-check').style.display = 'none';
  document.getElementById('app').style.display = '';
  document.getElementById('user-name').textContent = me.data.username;
  document.getElementById('user-avatar').textContent = me.data.username[0].toUpperCase();

  document.getElementById('logout-btn').addEventListener('click', async e => {
    e.preventDefault();
    await Nova.api('auth', 'logout', { method: 'POST' });
    location.reload();
  });

  // Simple dashboard
  document.getElementById('page-content').innerHTML = `
    <div class="stats-grid">
      <div class="stat-card"><div class="stat-label">Total Accounts</div><div class="stat-value stat-blue">0</div><div class="stat-sub">Active hosting accounts</div></div>
      <div class="stat-card"><div class="stat-label">Disk Used</div><div class="stat-value">0 GB</div><div class="stat-sub">of allocated quota</div></div>
      <div class="stat-card"><div class="stat-label">Bandwidth</div><div class="stat-value">0 GB</div><div class="stat-sub">this month</div></div>
      <div class="stat-card"><div class="stat-label">Packages</div><div class="stat-value stat-green">0</div><div class="stat-sub">Active plans</div></div>
    </div>
    <div class="card">
      <div class="card-header"><span class="card-title">Quick Actions</span></div>
      <div class="card-body flex gap-2">
        <button class="btn btn-primary">Create Account</button>
        <button class="btn btn-ghost">Create Package</button>
        <button class="btn btn-ghost">View DNS Zones</button>
      </div>
    </div>`;
})();
</script>
</body>
</html>
