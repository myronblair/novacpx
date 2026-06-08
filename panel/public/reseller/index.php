<?php
// NovaCPX Reseller Panel — port 8881
$_v = fn($f) => '?v=' . @filemtime(dirname(__DIR__) . $f);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>NovaCPX Reseller</title>
<link rel="icon" type="image/svg+xml" href="/assets/img/favicon.svg">
<link rel="stylesheet" href="/assets/css/nova.css<?= $_v('/assets/css/nova.css') ?>">
</head>
<body>

<div class="panel-layout" id="main-layout" style="display:none">
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
    <nav id="sidebar-nav"></nav>
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

<script src="/assets/js/nova.js<?= $_v('/assets/js/nova.js') ?>"></script>
<script src="/assets/js/reseller.js<?= $_v('/assets/js/reseller.js') ?>"></script>

</body>
</html>
