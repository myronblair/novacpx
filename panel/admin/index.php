<?php
// NovaCPX Admin Panel — Datacenter/Server Manager
if (!defined('NOVACPX_ROOT'))    define('NOVACPX_ROOT',    dirname(__DIR__));
if (!defined('NOVACPX_VERSION')) define('NOVACPX_VERSION', trim(@file_get_contents(NOVACPX_ROOT . '/VERSION') ?: '1.0.0'));
$_v = fn($f) => '?v=' . @filemtime(dirname(__DIR__) . $f);
require_once dirname(__DIR__) . '/_branding.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="description" content="NovaCPX Admin Panel — full-featured hosting control panel for managing servers, accounts, domains, email, databases, and services.">
<meta name="keywords" content="hosting control panel, server management, web hosting admin, NovaCPX, domain management, email hosting, database management">
<meta name="robots" content="noindex, nofollow">
<title>NovaCPX Admin</title>
<link rel="icon" type="image/svg+xml" href="/assets/img/favicon.svg">
<link rel="stylesheet" href="/assets/css/nova.css<?= $_v('/assets/css/nova.css') ?>">
</head>
<body>

<div class="panel-layout" id="app" style="display:none">
<div class="sidebar-overlay" id="sidebar-overlay"></div>

  <!-- Sidebar -->
  <aside class="sidebar" id="sidebar">
    <div class="sidebar-brand">
      <?= novacpx_logo_html('<svg class="logo-icon" viewBox="0 0 40 40" fill="none"><circle cx="20" cy="20" r="18" stroke="url(#lg1)" stroke-width="2"/><path d="M12 28 L20 8 L28 28" stroke="url(#lg2)" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"/><path d="M14 22 H26" stroke="url(#lg2)" stroke-width="2" stroke-linecap="round"/><defs><linearGradient id="lg1" x1="2" y1="2" x2="38" y2="38"><stop offset="0%" stop-color="#6366f1"/><stop offset="100%" stop-color="#0ea5e9"/></linearGradient><linearGradient id="lg2" x1="12" y1="8" x2="28" y2="28"><stop offset="0%" stop-color="#6366f1"/><stop offset="100%" stop-color="#0ea5e9"/></linearGradient></defs></svg>') ?>
      <span class="logo-text">Nova<strong>CPX</strong> <small style="font-size:.65rem;color:var(--text-muted)">Admin</small>
        <span id="panel-version-badge" style="display:block;font-size:.6rem;color:var(--text-muted);font-weight:400;line-height:1;margin-top:2px">v<?= trim(@file_get_contents(NOVACPX_ROOT . '/VERSION') ?: NOVACPX_VERSION) ?></span>
      </span>
    </div>

    <nav>
      <div class="sidebar-section">
        <div class="sidebar-section-label">Overview</div>
        <a href="#" class="sidebar-link active" data-page="dashboard">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/></svg>
          Dashboard
        </a>
      </div>

      <div class="sidebar-section">
        <div class="sidebar-section-label">Accounts</div>
        <a href="#" class="sidebar-link" data-page="accounts">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
          All Accounts
        </a>
        <a href="#" class="sidebar-link" data-page="resellers">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="8" r="4"/><path d="M2 20c0-4 4-7 10-7s10 3 10 7"/></svg>
          Resellers
        </a>
        <a href="#" class="sidebar-link" data-page="packages">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/></svg>
          Packages
        </a>
        <a href="#" class="sidebar-link" data-page="create-account">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><line x1="19" y1="8" x2="19" y2="14"/><line x1="22" y1="11" x2="16" y2="11"/></svg>
          Create Account
        </a>
      </div>

      <div class="sidebar-section">
        <div class="sidebar-section-label">DNS</div>
        <a href="#" class="sidebar-link" data-page="dns-zones">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/></svg>
          DNS Zones
        </a>
        <a href="#" class="sidebar-link" data-page="nameservers">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>
          Nameservers
        </a>
      </div>

      <div class="sidebar-section">
        <div class="sidebar-section-label">Services</div>
        <a href="#" class="sidebar-link" data-page="web-server">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/></svg>
          Web Server
        </a>
        <a href="#" class="sidebar-link" data-page="php-manager">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="16 18 22 12 16 6"/><polyline points="8 6 2 12 8 18"/></svg>
          PHP Manager
        </a>
        <a href="#" class="sidebar-link" data-page="mysql-manager">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><ellipse cx="12" cy="5" rx="9" ry="3"/><path d="M21 12c0 1.66-4 3-9 3s-9-1.34-9-3"/><path d="M3 5v14c0 1.66 4 3 9 3s9-1.34 9-3V5"/></svg>
          MySQL / PgSQL
        </a>
        <a href="#" class="sidebar-link" data-page="mail-server">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
          Mail Server
        </a>
        <a href="#" class="sidebar-link" data-page="ftp-server">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/></svg>
          FTP Server
        </a>
        <a href="#" class="sidebar-link" data-page="nginx-proxy">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="2" width="20" height="8" rx="2"/><rect x="2" y="14" width="20" height="8" rx="2"/><line x1="6" y1="6" x2="6.01" y2="6"/><line x1="6" y1="18" x2="6.01" y2="18"/></svg>
          Nginx Proxy
        </a>
        <a href="#" class="sidebar-link" data-page="wordpress">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M2 12h20M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/></svg>
          WordPress
        </a>
        <a href="#" class="sidebar-link" data-page="docker">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="8" width="4" height="4" rx="1"/><rect x="8" y="8" width="4" height="4" rx="1"/><rect x="14" y="8" width="4" height="4" rx="1"/><rect x="8" y="2" width="4" height="4" rx="1"/><path d="M2 14c0 4 3 6 10 6s10-2 10-6"/><path d="M20 14c1.5 0 2.5-1 2.5-2.5S21.5 9 20 9h-1"/></svg>
          Docker
        </a>
      </div>

      <div class="sidebar-section">
        <div class="sidebar-section-label">Security</div>
        <a href="#" class="sidebar-link" data-page="ssl-manager">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
          SSL Manager
        </a>
        <a href="#" class="sidebar-link" data-page="firewall">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
          Firewall (UFW)
        </a>
        <a href="#" class="sidebar-link" data-page="fail2ban">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
          Fail2Ban
        </a>
        <a href="#" class="sidebar-link" data-page="audit-log">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg>
          Audit Log
        </a>
        <a href="#" class="sidebar-link" data-page="twofa">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="5" y="11" width="14" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/><circle cx="12" cy="16" r="1" fill="currentColor"/></svg>
          2FA Manager
        </a>
        <a href="#" class="sidebar-link" data-page="sessions">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"/><path d="M12 1v4M12 19v4M4.22 4.22l2.83 2.83M16.95 16.95l2.83 2.83M1 12h4M19 12h4M4.22 19.78l2.83-2.83M16.95 7.05l2.83-2.83"/></svg>
          Sessions
        </a>
      </div>

      <div class="sidebar-section">
        <div class="sidebar-section-label">System</div>
        <a href="#" class="sidebar-link" data-page="updates">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="23 4 23 10 17 10"/><polyline points="1 20 1 14 7 14"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/></svg>
          Updates <span id="update-badge" class="badge badge-yellow" style="display:none"></span>
        </a>
        <a href="#" class="sidebar-link" data-page="backups">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
          Backups
        </a>
        <a href="#" class="sidebar-link" data-page="cloudflare">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17.5 19H9a7 7 0 1 1 6.71-9h1.79a4.5 4.5 0 1 1 0 9z"/></svg>
          Cloudflare
        </a>
        <a href="#" class="sidebar-link" data-page="server-options">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="2" width="20" height="8" rx="2"/><rect x="2" y="14" width="20" height="8" rx="2"/><line x1="6" y1="6" x2="6.01" y2="6"/><line x1="6" y1="18" x2="6.01" y2="18"/></svg>
          Server Options
        </a>
        <a href="#" class="sidebar-link" data-page="notifications">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>
          Notifications
        </a>
        <a href="#" class="sidebar-link" data-page="settings">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>
          Settings
        </a>
      </div>
    </nav>

    <div class="sidebar-user">
      <div class="sidebar-user-info">
        <div class="avatar" id="user-avatar">A</div>
        <div>
          <div class="user-name" id="user-name">Admin</div>
          <div class="user-role">Administrator</div>
        </div>
        <a href="#" id="logout-btn" class="btn btn-ghost btn-sm btn-icon" title="Logout" style="margin-left:auto">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
        </a>
      </div>
    </div>
  </aside>

  <!-- Main Content -->
  <div class="main-content">
    <header class="topbar">
      <button class="btn btn-ghost btn-icon" id="sidebar-toggle" aria-label="Menu"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="20" height="20"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></svg></button>

      <div class="topbar-title" id="page-title">Dashboard</div>
      <div class="topbar-actions">
        <span id="server-ip" class="text-muted text-sm"></span>
        <div id="alert-indicator" style="display:none">
          <span class="badge badge-red" id="alert-count"></span>
        </div>
      </div>
    </header>

    <div class="page-content" id="page-content">
      <!-- Loaded by JS -->
    </div>
  </div>
</div>

<!-- Auth guard / Login overlay (shown when not authenticated) -->
<div id="auth-check" style="display:flex;align-items:center;justify-content:center;min-height:100vh;background:var(--bg)">
  <div style="width:100%;max-width:400px;padding:1.5rem">
    <div style="text-align:center;margin-bottom:1.5rem">
      <svg viewBox="0 0 40 40" fill="none" style="width:40px;height:40px;margin:0 auto 1rem">
        <circle cx="20" cy="20" r="18" stroke="url(#alg1)" stroke-width="2"/>
        <path d="M12 28 L20 8 L28 28" stroke="url(#alg2)" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"/>
        <path d="M14 22 H26" stroke="url(#alg2)" stroke-width="2" stroke-linecap="round"/>
        <defs>
          <linearGradient id="alg1" x1="2" y1="2" x2="38" y2="38"><stop offset="0%" stop-color="#6366f1"/><stop offset="100%" stop-color="#0ea5e9"/></linearGradient>
          <linearGradient id="alg2" x1="12" y1="8" x2="28" y2="28"><stop offset="0%" stop-color="#6366f1"/><stop offset="100%" stop-color="#0ea5e9"/></linearGradient>
        </defs>
      </svg>
      <div style="font-size:1.4rem;font-weight:300">Nova<strong style="font-weight:700;background:linear-gradient(135deg,#6366f1,#0ea5e9);-webkit-background-clip:text;-webkit-text-fill-color:transparent">CPX</strong></div>
      <div style="font-size:.78rem;color:var(--text-muted);margin-top:.25rem;text-transform:uppercase;letter-spacing:.1em">Admin Panel · Port 8882</div>
    </div>
    <div class="card">
      <div class="card-body">
        <div id="login-err" class="alert alert-error" style="display:none"></div>
        <form id="login-form">
          <div class="form-group"><label>Username or Email</label><input type="text" id="l-user" autofocus required></div>
          <div class="form-group"><label>Password</label><input type="password" id="l-pass" required></div>
          <button type="submit" class="btn btn-primary btn-full" id="l-btn">Sign In to Admin</button>
        </form>
      </div>
    </div>
  </div>
</div>

<script src="/assets/js/nova.js<?= $_v('/assets/js/nova.js') ?>"></script>
<script src="/assets/js/admin.js<?= $_v('/assets/js/admin.js') ?>"></script>
</body>
</html>
