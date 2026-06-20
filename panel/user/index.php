<?php
// NovaCPX User Panel — End-user hosting dashboard
if (!defined('NOVACPX_ROOT'))    define('NOVACPX_ROOT',    dirname(__DIR__));
if (!defined('NOVACPX_VERSION')) define('NOVACPX_VERSION', trim(@file_get_contents(NOVACPX_ROOT . '/VERSION') ?: '1.0.0'));
$_v = fn($f) => '?v=' . @filemtime(dirname(__DIR__) . $f);
require_once dirname(__DIR__) . '/_branding.php';
$_pname = novacpx_panel_name('NovaCPX');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="description" content="Your hosting control panel — manage your website, domains, email accounts, databases, FTP, SSL certificates, and files in one place.">
<meta name="keywords" content="hosting control panel, manage website, email hosting, domain management, database management, SSL certificate, FTP, web hosting dashboard">
<meta name="robots" content="noindex, nofollow">
<title><?= $_pname ?> — My Hosting</title>
<link rel="icon" type="image/svg+xml" href="/assets/img/favicon.svg">
<link rel="stylesheet" href="/assets/css/nova.css<?= $_v('/assets/css/nova.css') ?>">
<?php novacpx_branding_head() ?>
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

<div class="panel-layout" id="main-layout" style="display:none">
  <aside class="sidebar" id="sidebar">
    <div class="sidebar-brand">
      <?= novacpx_logo_html('<svg class="logo-icon" viewBox="0 0 40 40" fill="none"><circle cx="20" cy="20" r="18" stroke="url(#ulg1)" stroke-width="2"/><path d="M12 28 L20 8 L28 28" stroke="url(#ulg2)" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"/><path d="M14 22 H26" stroke="url(#ulg2)" stroke-width="2" stroke-linecap="round"/><defs><linearGradient id="ulg1" x1="2" y1="2" x2="38" y2="38"><stop offset="0%" stop-color="#6366f1"/><stop offset="100%" stop-color="#0ea5e9"/></linearGradient><linearGradient id="ulg2" x1="12" y1="8" x2="28" y2="28"><stop offset="0%" stop-color="#6366f1"/><stop offset="100%" stop-color="#0ea5e9"/></linearGradient></defs></svg>') ?>
      <span class="logo-text"><?= $_pname ?> <small style="font-size:.65rem;color:var(--text-muted)">My Hosting</small>
        <span style="display:block;font-size:.6rem;color:var(--text-muted);font-weight:400;line-height:1;margin-top:2px">v<?= NOVACPX_VERSION ?></span>
      </span>
    </div>
    <nav id="sidebar-nav"></nav>
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
      <button class="btn btn-ghost btn-icon" id="sidebar-toggle" aria-label="Menu"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="20" height="20"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></svg></button>
      <div class="topbar-title" id="page-title">My Hosting</div>
      <div class="topbar-actions">
        <span id="account-domain" class="text-muted text-sm"></span>
      </div>
    </header>
    <div class="page-content" id="page-content"></div>
  </div>
</div>

<div id="auth-check" style="display:flex;align-items:center;justify-content:center;min-height:100vh;background:var(--bg)">
  <div style="width:100%;max-width:400px;padding:1.5rem">
    <div style="text-align:center;margin-bottom:1.5rem">
      <svg viewBox="0 0 40 40" fill="none" style="width:40px;height:40px;margin:0 auto 1rem">
        <circle cx="20" cy="20" r="18" stroke="url(#ulg3)" stroke-width="2"/>
        <path d="M12 28 L20 8 L28 28" stroke="url(#ulg4)" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"/>
        <path d="M14 22 H26" stroke="url(#ulg4)" stroke-width="2" stroke-linecap="round"/>
        <defs>
          <linearGradient id="ulg3" x1="2" y1="2" x2="38" y2="38"><stop offset="0%" stop-color="#6366f1"/><stop offset="100%" stop-color="#0ea5e9"/></linearGradient>
          <linearGradient id="ulg4" x1="12" y1="8" x2="28" y2="28"><stop offset="0%" stop-color="#6366f1"/><stop offset="100%" stop-color="#0ea5e9"/></linearGradient>
        </defs>
      </svg>
      <div style="font-size:1.4rem;font-weight:300"><?= htmlspecialchars($_pname, ENT_QUOTES, 'UTF-8') ?></div>
      <div style="font-size:.78rem;color:var(--text-muted);margin-top:.25rem;text-transform:uppercase;letter-spacing:.1em">My Hosting</div>
    </div>
    <div class="card">
      <div class="card-body">
        <div id="li-err" class="alert alert-error" style="display:none"></div>
        <form id="login-form" onsubmit="event.preventDefault();doLogin()">
          <div class="form-group"><label>Username or Email</label><input type="text" id="li-user" autofocus autocomplete="username"></div>
          <div class="form-group"><label>Password</label><input type="password" id="li-pass" autocomplete="current-password"></div>
          <button type="submit" class="btn btn-primary btn-full">Sign In</button>
        </form>
      </div>
    </div>
  </div>
</div>

<script>
window.NOVACPX_BRANDING = <?= json_encode([
    'panel_name'      => novacpx_panel_name('NovaCPX'),
    'support_email'   => novacpx_get_branding()['support_email'] ?? null,
    'support_url'     => novacpx_get_branding()['support_url']   ?? null,
    'hide_powered_by' => !novacpx_powered_by(),
]) ?>;
</script>
<script src="/assets/js/nova.js<?= $_v('/assets/js/nova.js') ?>"></script>
<script src="/assets/js/user.js<?= $_v('/assets/js/user.js') ?>"></script>
<!-- user.js boots via DOMContentLoaded and handles all auth/init -->
</body>
</html>
