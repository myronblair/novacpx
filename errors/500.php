<?php http_response_code(500); ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>500 — Server Error · NovaCPX</title>
<style>
:root{--bg:#0d0f17;--bg2:#131520;--border:#252840;--text:#e2e4f0;--text-muted:#7c7f9a;--primary:#6366f1;--red:#ef4444}
*{box-sizing:border-box;margin:0;padding:0}
body{background:var(--bg);color:var(--text);font-family:'Inter',system-ui,sans-serif;min-height:100vh;display:flex;align-items:center;justify-content:center;
  background-image:radial-gradient(ellipse at 30% 20%,rgba(239,68,68,.1) 0%,transparent 60%),radial-gradient(ellipse at 80% 80%,rgba(99,102,241,.07) 0%,transparent 60%)}
.wrap{text-align:center;padding:2rem;max-width:480px}
.code{font-size:7rem;font-weight:900;line-height:1;background:linear-gradient(135deg,#ef4444,#f59e0b);-webkit-background-clip:text;-webkit-text-fill-color:transparent;margin-bottom:.5rem}
h1{font-size:1.5rem;font-weight:600;margin-bottom:.75rem}
p{color:var(--text-muted);margin-bottom:2rem;line-height:1.6}
.btn{display:inline-flex;align-items:center;gap:.5rem;padding:.65rem 1.5rem;background:var(--primary);color:#fff;border-radius:10px;text-decoration:none;font-weight:500;font-size:.9rem}
.btn:hover{opacity:.85}
.logo{display:flex;align-items:center;justify-content:center;gap:.5rem;margin-bottom:2.5rem;opacity:.6}
.logo svg{width:28px;height:28px}
.logo-text{font-size:1.1rem;font-weight:300}
.logo-text strong{font-weight:700;background:linear-gradient(135deg,#6366f1,#0ea5e9);-webkit-background-clip:text;-webkit-text-fill-color:transparent}
</style>
</head>
<body>
<div class="wrap">
  <div class="logo">
    <svg viewBox="0 0 40 40" fill="none"><circle cx="20" cy="20" r="18" stroke="url(#g1)" stroke-width="2"/><path d="M12 28L20 8l8 20" stroke="url(#g2)" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"/><path d="M14 22h12" stroke="url(#g2)" stroke-width="2" stroke-linecap="round"/><defs><linearGradient id="g1" x1="2" y1="2" x2="38" y2="38"><stop stop-color="#6366f1"/><stop offset="1" stop-color="#0ea5e9"/></linearGradient><linearGradient id="g2" x1="12" y1="8" x2="28" y2="28"><stop stop-color="#6366f1"/><stop offset="1" stop-color="#0ea5e9"/></linearGradient></defs></svg>
    <div class="logo-text">Nova<strong>CPX</strong></div>
  </div>
  <div class="code">500</div>
  <h1>Internal Server Error</h1>
  <p>Something went wrong on our end. The issue has been logged. Please try again in a moment.</p>
  <a href="javascript:history.back()" class="btn">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16"><path d="M19 12H5M12 5l-7 7 7 7"/></svg>
    Go Back
  </a>
</div>
</body>
</html>
