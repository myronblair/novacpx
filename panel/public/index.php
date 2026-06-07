<?php
// NovaCPX entry point — redirect based on role or show login
session_start();
$redirect = $_GET['redirect'] ?? '';
$safeRedirect = preg_match('#^/(user|reseller|admin)#', $redirect) ? $redirect : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>NovaCPX — Login</title>
<link rel="icon" type="image/svg+xml" href="/assets/img/favicon.svg">
<link rel="stylesheet" href="/assets/css/nova.css">
</head>
<body class="login-page">

<div class="login-wrap">
  <div class="login-brand">
    <svg class="logo-icon" viewBox="0 0 40 40" fill="none" xmlns="http://www.w3.org/2000/svg">
      <circle cx="20" cy="20" r="18" stroke="url(#lg1)" stroke-width="2"/>
      <path d="M12 28 L20 8 L28 28" stroke="url(#lg2)" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"/>
      <path d="M14 22 H26" stroke="url(#lg2)" stroke-width="2" stroke-linecap="round"/>
      <defs>
        <linearGradient id="lg1" x1="2" y1="2" x2="38" y2="38">
          <stop offset="0%" stop-color="#6366f1"/>
          <stop offset="100%" stop-color="#0ea5e9"/>
        </linearGradient>
        <linearGradient id="lg2" x1="12" y1="8" x2="28" y2="28">
          <stop offset="0%" stop-color="#6366f1"/>
          <stop offset="100%" stop-color="#0ea5e9"/>
        </linearGradient>
      </defs>
    </svg>
    <span class="logo-text">Nova<strong>CPX</strong></span>
  </div>

  <div class="login-card">
    <h1>Sign In</h1>
    <p class="login-sub">Linux Web Hosting Control Panel</p>

    <div id="login-error" class="alert alert-error" style="display:none"></div>

    <form id="login-form">
      <div class="form-group">
        <label for="username">Username or Email</label>
        <input type="text" id="username" name="username" autocomplete="username" autofocus required>
      </div>
      <div class="form-group">
        <label for="password">Password</label>
        <div class="input-with-icon">
          <input type="password" id="password" name="password" autocomplete="current-password" required>
          <button type="button" class="eye-toggle" data-target="password">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/>
              <circle cx="12" cy="12" r="3"/>
            </svg>
          </button>
        </div>
      </div>
      <button type="submit" class="btn btn-primary btn-full" id="login-btn">
        <span class="btn-text">Sign In</span>
        <span class="btn-spinner" style="display:none">Signing in…</span>
      </button>
    </form>
  </div>

  <div class="login-footer">
    NovaCPX v<span id="panel-version">1.0.0</span> &nbsp;|&nbsp;
    <a href="/api/system/version" target="_blank">System Info</a>
  </div>
</div>

<script>
const REDIRECT = <?= json_encode($safeRedirect) ?>;

document.getElementById('login-form').addEventListener('submit', async e => {
  e.preventDefault();
  const btn  = document.getElementById('login-btn');
  const err  = document.getElementById('login-error');
  btn.querySelector('.btn-text').style.display = 'none';
  btn.querySelector('.btn-spinner').style.display = '';
  btn.disabled = true;
  err.style.display = 'none';

  try {
    const res = await fetch('/api/auth/login', {
      method: 'POST',
      headers: {'Content-Type':'application/json'},
      credentials: 'include',
      body: JSON.stringify({
        username: document.getElementById('username').value,
        password: document.getElementById('password').value,
      }),
    });
    const data = await res.json();
    if (!data.success) throw new Error(data.message || 'Login failed');

    const role = data.data.user.role;
    const dest = REDIRECT || (role === 'admin' ? '/admin/' : role === 'reseller' ? '/reseller/' : '/user/');
    location.href = dest;
  } catch (ex) {
    err.textContent = ex.message;
    err.style.display = '';
    btn.querySelector('.btn-text').style.display = '';
    btn.querySelector('.btn-spinner').style.display = 'none';
    btn.disabled = false;
  }
});

// Password toggle
document.querySelectorAll('.eye-toggle').forEach(btn => {
  btn.addEventListener('click', () => {
    const inp = document.getElementById(btn.dataset.target);
    inp.type = inp.type === 'password' ? 'text' : 'password';
  });
});

// Fetch version
fetch('/api/auth/me', {credentials:'include'}).then(r => r.json()).then(d => {
  if (d.success) {
    const role = d.data.role;
    location.href = role === 'admin' ? '/admin/' : role === 'reseller' ? '/reseller/' : '/user/';
  }
});
fetch('/api/system/version', {credentials:'include'})
  .then(r=>r.json()).then(d=>{ if(d.data?.installed_version) document.getElementById('panel-version').textContent=d.data.installed_version; });
</script>
</body>
</html>
