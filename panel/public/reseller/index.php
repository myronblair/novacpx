<?php
// NovaCPX Reseller Panel — port 8881
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
<meta name="description" content="NovaCPX Reseller Panel — manage your reseller hosting accounts, packages, clients, and branding from one dashboard.">
<meta name="keywords" content="reseller hosting panel, reseller control panel, manage hosting clients, white label hosting, NovaCPX reseller">
<meta name="robots" content="noindex, nofollow">
<title><?= $_pname ?> — Reseller</title>
<link rel="icon" type="image/svg+xml" href="/assets/img/favicon.svg">
<link rel="stylesheet" href="/assets/css/nova.css<?= $_v('/assets/css/nova.css') ?>">
<?php novacpx_branding_head() ?>
</head>
<body>

<div class="panel-layout" id="main-layout" style="display:none">
  <aside class="sidebar" id="sidebar">
    <div class="sidebar-brand">
      <?= novacpx_logo_html('<svg class="logo-icon" viewBox="0 0 40 40" fill="none"><circle cx="20" cy="20" r="18" stroke="url(#rlg1)" stroke-width="2"/><path d="M12 28 L20 8 L28 28" stroke="url(#rlg2)" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"/><path d="M14 22 H26" stroke="url(#rlg2)" stroke-width="2" stroke-linecap="round"/><defs><linearGradient id="rlg1" x1="2" y1="2" x2="38" y2="38"><stop offset="0%" stop-color="#6366f1"/><stop offset="100%" stop-color="#0ea5e9"/></linearGradient><linearGradient id="rlg2" x1="12" y1="8" x2="28" y2="28"><stop offset="0%" stop-color="#6366f1"/><stop offset="100%" stop-color="#0ea5e9"/></linearGradient></defs></svg>') ?>
      <span class="logo-text"><?= $_pname ?> <small style="font-size:.65rem;color:var(--text-muted)">Reseller</small>
        <span style="display:block;font-size:.6rem;color:var(--text-muted);font-weight:400;line-height:1;margin-top:2px">v<?= NOVACPX_VERSION ?></span>
      </span>
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
      <button class="btn btn-ghost btn-icon" id="sidebar-toggle" aria-label="Menu"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="20" height="20"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></svg></button>
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
      <div style="font-size:1.4rem;font-weight:300"><?= htmlspecialchars($_pname, ENT_QUOTES, 'UTF-8') ?></div>
      <div style="font-size:.78rem;color:var(--text-muted);margin-top:.25rem;text-transform:uppercase;letter-spacing:.1em">Reseller Panel</div>
    </div>
    <div class="card">
      <div class="card-body">
        <div id="li-err" class="alert alert-error" style="display:none"></div>
        <form id="login-form" onsubmit="event.preventDefault();doLogin()">
          <div class="form-group"><label>Username or Email</label><input type="text" id="li-user" autofocus autocomplete="username"></div>
          <div class="form-group"><label>Password</label><input type="password" id="li-pass" autocomplete="current-password"></div>
          <button type="submit" class="btn btn-primary btn-full">Sign In to Reseller Panel</button>
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
<script src="/assets/js/reseller.js<?= $_v('/assets/js/reseller.js') ?>"></script>

</body>
</html>
