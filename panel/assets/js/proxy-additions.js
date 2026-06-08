
// ── Nginx Proxy Manager ───────────────────────────────────────────────────────
async function nginxProxy() {
  const [statusR, hostsR] = await Promise.all([
    Nova.api('proxy', 'status'),
    Nova.api('proxy', 'hosts'),
  ]);
  const s     = statusR?.data  || {};
  const hosts = hostsR?.data   || (Array.isArray(hostsR) ? hostsR : []);
  const run   = s.running;
  const inst  = s.installed;

  return `
<div class="page-header">
  <h1 class="page-title">Nginx Proxy Manager</h1>
  <div class="page-actions">
    ${inst ? `
      <button class="btn btn-ghost btn-sm" onclick="proxySetupInstructions()">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4M12 8h.01"/></svg>
        Setup Guide
      </button>
      <button class="btn btn-sm btn-secondary" onclick="proxySync()">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14"><polyline points="23 4 23 10 17 10"/><polyline points="1 20 1 14 7 14"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/></svg>
        Sync Accounts
      </button>
      <button class="btn btn-sm btn-primary" onclick="proxyAddHost()">+ Add Host</button>
    ` : ''}
  </div>
</div>

<div class="stats-grid" style="margin-bottom:1.5rem">
  <div class="stat-card">
    <div class="stat-label">Nginx Status</div>
    <div class="stat-value ${run ? 'stat-green' : 'stat-red'}">${inst ? (run ? 'Running' : 'Stopped') : 'Not Installed'}</div>
    <div class="stat-sub">${s.version || (inst ? 'nginx' : 'click Install to set up')}</div>
  </div>
  <div class="stat-card">
    <div class="stat-label">Proxy Hosts</div>
    <div class="stat-value">${hosts.length}</div>
    <div class="stat-sub">${hosts.filter(h => h.enabled).length} active</div>
  </div>
  <div class="stat-card">
    <div class="stat-label">SSL Enabled</div>
    <div class="stat-value">${hosts.filter(h => h.ssl_enabled).length}</div>
    <div class="stat-sub">of ${hosts.length} hosts</div>
  </div>
</div>

${!inst ? `
<div class="panel" style="text-align:center;padding:3rem">
  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" width="48" height="48" style="color:var(--text-muted);margin-bottom:1rem"><rect x="2" y="2" width="20" height="8" rx="2"/><rect x="2" y="14" width="20" height="8" rx="2"/><line x1="6" y1="6" x2="6.01" y2="6"/><line x1="6" y1="18" x2="6.01" y2="18"/></svg>
  <h3 style="margin-bottom:0.5rem">Nginx Not Installed</h3>
  <p style="color:var(--text-muted);margin-bottom:1.5rem">Install Nginx on this VM to use it as a reverse proxy in front of Apache, or use a separate proxy VM (see Setup Guide).</p>
  <div style="display:flex;gap:0.75rem;justify-content:center;flex-wrap:wrap">
    <button class="btn btn-primary" onclick="proxyInstall()">Install Nginx Locally</button>
    <button class="btn btn-secondary" onclick="proxySetupInstructions()">Setup Guide / Remote VM</button>
  </div>
</div>
` : `
<div class="panel" style="margin-bottom:1.5rem">
  <div class="panel-header">
    <h3 class="panel-title">Service Controls</h3>
    <div style="display:flex;gap:0.5rem">
      <button class="btn btn-sm btn-success" onclick="proxyControl('start')">Start</button>
      <button class="btn btn-sm btn-warning" onclick="proxyControl('restart')">Restart</button>
      <button class="btn btn-sm btn-danger"  onclick="proxyControl('stop')">Stop</button>
      <button class="btn btn-sm btn-ghost"   onclick="proxyControl('reload')">Reload Config</button>
    </div>
  </div>
</div>

<div class="panel">
  <div class="panel-header">
    <h3 class="panel-title">Proxy Hosts</h3>
    <span class="badge badge-blue">${hosts.length} total</span>
  </div>
  ${hosts.length === 0 ? `
  <div style="text-align:center;padding:2rem;color:var(--text-muted)">
    No proxy hosts yet. Click <strong>Sync Accounts</strong> to auto-add all hosted domains, or <strong>+ Add Host</strong> to add manually.
  </div>
  ` : `
  <div style="overflow-x:auto">
  <table class="table">
    <thead><tr>
      <th>Domain</th>
      <th>Upstream</th>
      <th>SSL</th>
      <th>Status</th>
      <th>Actions</th>
    </tr></thead>
    <tbody>
    ${hosts.map(h => `
      <tr id="proxy-row-${h.id}">
        <td><strong>${Nova.escHtml(h.domain)}</strong></td>
        <td style="font-family:monospace;font-size:0.8rem">${Nova.escHtml(h.upstream)}</td>
        <td>${h.ssl_enabled ? Nova.badge('SSL','green') : Nova.badge('HTTP','muted')}</td>
        <td>${h.enabled ? Nova.badge('Active','green') : Nova.badge('Disabled','red')}</td>
        <td>
          <button class="btn btn-xs btn-ghost" onclick="proxyEditHost(${h.id})">Edit</button>
          <button class="btn btn-xs ${h.enabled ? 'btn-warning' : 'btn-success'}" onclick="proxyToggle(${h.id},${h.enabled ? 0 : 1})">${h.enabled ? 'Disable' : 'Enable'}</button>
          <button class="btn btn-xs btn-danger" onclick="proxyDeleteHost(${h.id},'${Nova.escHtml(h.domain)}')">Delete</button>
        </td>
      </tr>`).join('')}
    </tbody>
  </table>
  </div>
  `}
</div>
`}`;
}

window.proxyInstall = async () => {
  if (!confirm('Install Nginx on this VM? This will run apt-get install nginx.')) return;
  Nova.toast('Installing nginx...', 'info');
  const r = await Nova.api('proxy', 'install', { method: 'POST' });
  Nova.toast(r?.data?.result || r?.message || 'Done', r?.data?.result === 'installed' ? 'success' : 'info');
  Nova.loadPage('nginx-proxy', window._novaPages);
};

window.proxyControl = async (action) => {
  const r = await Nova.api('proxy', 'control', { method: 'POST', body: { action } });
  Nova.toast(r?.data?.result || r?.message || action + ' done', 'success');
  setTimeout(() => Nova.loadPage('nginx-proxy', window._novaPages), 800);
};

window.proxySync = async () => {
  const r = await Nova.api('proxy', 'sync', { method: 'POST' });
  Nova.toast(`Synced: ${r?.data?.added ?? 0} new hosts added`, 'success');
  Nova.loadPage('nginx-proxy', window._novaPages);
};

window.proxyAddHost = () => {
  Nova.modal('Add Proxy Host', `
    <div class="form-group"><label>Domain</label>
      <input id="ph-domain" type="text" placeholder="example.com" class="form-control"></div>
    <div class="form-group"><label>Upstream URL</label>
      <input id="ph-upstream" type="text" value="http://127.0.0.1:80" class="form-control">
      <small class="text-muted">e.g. http://127.0.0.1:80 or http://10.0.0.2:8080</small></div>
    <div class="form-group">
      <label><input type="checkbox" id="ph-ssl"> Enable SSL</label></div>
    <div class="form-group"><label>Notes (optional)</label>
      <input id="ph-notes" type="text" class="form-control"></div>
  `, async () => {
    const domain   = document.getElementById('ph-domain')?.value?.trim();
    const upstream = document.getElementById('ph-upstream')?.value?.trim();
    if (!domain || !upstream) { Nova.toast('Domain and upstream required', 'error'); return; }
    const r = await Nova.api('proxy', 'hosts', {
      method: 'POST',
      body: { domain, upstream, ssl_enabled: document.getElementById('ph-ssl')?.checked ? 1 : 0 }
    });
    Nova.toast(r?.success ? 'Host added' : (r?.message || 'Failed'), r?.success ? 'success' : 'error');
    if (r?.success) Nova.loadPage('nginx-proxy', window._novaPages);
  });
};

window.proxyEditHost = async (id) => {
  const hostsR = await Nova.api('proxy', 'hosts');
  const hosts  = hostsR?.data || (Array.isArray(hostsR) ? hostsR : []);
  const h      = hosts.find(x => x.id == id);
  if (!h) return;
  Nova.modal('Edit Proxy Host', `
    <div class="form-group"><label>Domain</label>
      <input id="phe-domain" type="text" value="${Nova.escHtml(h.domain)}" class="form-control"></div>
    <div class="form-group"><label>Upstream URL</label>
      <input id="phe-upstream" type="text" value="${Nova.escHtml(h.upstream)}" class="form-control"></div>
    <div class="form-group">
      <label><input type="checkbox" id="phe-ssl" ${h.ssl_enabled ? 'checked' : ''}> Enable SSL</label></div>
    <div class="form-group"><label>Custom Nginx Config (overrides auto-generated)</label>
      <textarea id="phe-custom" rows="6" class="form-control" style="font-family:monospace;font-size:0.78rem">${Nova.escHtml(h.custom_config || '')}</textarea>
      <small class="text-muted">Leave blank to use auto-generated config</small></div>
  `, async () => {
    const r = await Nova.api('proxy', `hosts/${id}`, {
      method: 'PUT',
      body: {
        domain:        document.getElementById('phe-domain')?.value?.trim(),
        upstream:      document.getElementById('phe-upstream')?.value?.trim(),
        ssl_enabled:   document.getElementById('phe-ssl')?.checked ? 1 : 0,
        custom_config: document.getElementById('phe-custom')?.value?.trim() || null,
      }
    });
    Nova.toast(r?.success ? 'Updated' : (r?.message || 'Failed'), r?.success ? 'success' : 'error');
    if (r?.success) Nova.loadPage('nginx-proxy', window._novaPages);
  });
};

window.proxyToggle = async (id, enable) => {
  const r = await Nova.api('proxy', `hosts/${id}/toggle`, { method: 'POST', body: { enabled: enable } });
  Nova.toast(r?.success ? (enable ? 'Enabled' : 'Disabled') : 'Failed', r?.success ? 'success' : 'error');
  if (r?.success) Nova.loadPage('nginx-proxy', window._novaPages);
};

window.proxyDeleteHost = (id, domain) => {
  Nova.confirm(`Delete proxy host for ${domain}?`, async () => {
    const r = await Nova.api('proxy', `hosts/${id}`, { method: 'DELETE' });
    Nova.toast(r?.success ? 'Deleted' : 'Failed', r?.success ? 'success' : 'error');
    if (r?.success) Nova.loadPage('nginx-proxy', window._novaPages);
  }, true);
};

window.proxySetupInstructions = async () => {
  const scriptUrl = '/api/proxy/setup-script';
  Nova.modal('Nginx Proxy Setup Guide', `
<div style="max-height:60vh;overflow-y:auto">
  <h4 style="margin-bottom:0.75rem">Option A — Local (Nginx on this VM)</h4>
  <p style="color:var(--text-muted);margin-bottom:1rem">Install Nginx alongside Apache on this VM. Nginx listens on ports 80/443 and forwards to Apache. Best for SSL termination and caching.</p>
  <ol style="color:var(--text-muted);margin-bottom:1.5rem;padding-left:1.2rem;line-height:1.8">
    <li>Click <strong>Install Nginx Locally</strong> on the main Nginx Proxy page</li>
    <li>Move Apache to port 8080: edit <code>/etc/apache2/ports.conf</code> → change <code>Listen 80</code> to <code>Listen 8080</code></li>
    <li>Update upstream in all proxy hosts to <code>http://127.0.0.1:8080</code></li>
    <li>Click <strong>Sync Accounts</strong> to auto-populate proxy hosts from your hosted accounts</li>
    <li>Click <strong>Reload Config</strong> to apply changes</li>
  </ol>

  <h4 style="margin-bottom:0.75rem">Option B — Remote Proxy VM (Recommended for production)</h4>
  <p style="color:var(--text-muted);margin-bottom:1rem">Run a dedicated Nginx proxy VM in front of this NovaCPX VM. Traffic flows: Internet → FortiGate → Nginx Proxy VM → NovaCPX VM (Apache).</p>
  <ol style="color:var(--text-muted);margin-bottom:1.5rem;padding-left:1.2rem;line-height:1.8">
    <li>Create a new VM on Proxmox (Ubuntu 22.04, 1 vCPU, 1GB RAM)</li>
    <li>Run the setup script below on the new VM as root</li>
    <li>Point FortiGate VIPs to the proxy VM IP (ports 80/443)</li>
    <li>Set the proxy upstream to this NovaCPX VM IP (<code>http://10.48.200.110:80</code>)</li>
    <li>Add proxy hosts for each domain from your NovaCPX admin panel</li>
  </ol>

  <h4 style="margin-bottom:0.75rem">Automated Setup Script</h4>
  <p style="color:var(--text-muted);margin-bottom:0.75rem">Run this on the target VM (local or remote) as root:</p>
  <div style="background:var(--bg-secondary);padding:0.75rem;border-radius:6px;font-family:monospace;font-size:0.8rem;margin-bottom:0.75rem">
    curl -sk https://YOUR_NOVACPX_IP:8882/api/proxy/setup-script | bash
  </div>
  <p style="color:var(--text-muted);font-size:0.85rem">Or download and review before running:</p>
  <div style="background:var(--bg-secondary);padding:0.75rem;border-radius:6px;font-family:monospace;font-size:0.8rem">
    curl -sk https://YOUR_NOVACPX_IP:8882/api/proxy/setup-script -o proxy-setup.sh<br>
    cat proxy-setup.sh   # review<br>
    bash proxy-setup.sh
  </div>

  <h4 style="margin-bottom:0.75rem;margin-top:1.5rem">Integration with VirtualHost Manager</h4>
  <p style="color:var(--text-muted);margin-bottom:0.75rem">When proxy mode is active, NovaCPX automatically:</p>
  <ul style="color:var(--text-muted);padding-left:1.2rem;line-height:1.8">
    <li>Creates a proxy host entry for every new account</li>
    <li>Removes the proxy host when an account is terminated</li>
    <li>Re-generates Nginx config on every account change</li>
    <li>Uses account SSL certs automatically if SSL is enabled on the proxy host</li>
  </ul>
</div>
  `, null, { cancelLabel: 'Close', showConfirm: false });
};
