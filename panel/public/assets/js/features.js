/**
 * NovaCPX Feature Manager
 * Loaded by admin panel's features page
 */
window.FeaturesManager = {
  async load() {
    const res = await Nova.api('features', 'list');
    if (!res?.success) return '<p class="text-muted">Failed to load features.</p>';
    const grouped = res.data;

    const categoryIcons = {
      'Web Server':'🌐', 'PHP':'⚙️', 'Database':'🗄️', 'Email':'📧',
      'DNS':'🔍', 'FTP':'📁', 'SSL':'🔒', 'Security':'🛡️', 'Containers':'🐳',
      'IP Management':'🌍', 'Monitoring':'📊', 'Backup':'💾', 'CDN & Performance':'⚡',
      'Development':'👨‍💻', 'One-Click Apps':'🚀', 'Applications':'📦',
      'Billing':'💳', 'Reseller':'🏪', 'Notifications':'🔔', 'Compliance':'✅',
    };

    return `
<div class="flex justify-between items-center mb-3">
  <h2 style="font-size:1.1rem;font-weight:700">Feature Manager</h2>
  <div class="flex gap-1">
    <input type="text" id="feat-search" placeholder="Search features…" style="width:220px;padding:.45rem .85rem;font-size:.85rem">
    <select id="feat-cat-filter" style="padding:.45rem .7rem;font-size:.85rem">
      <option value="">All Categories</option>
      ${Object.keys(grouped).map(c => `<option value="${c}">${c}</option>`).join('')}
    </select>
  </div>
</div>

<div id="features-container">
  ${Object.entries(grouped).map(([cat, feats]) => `
    <div class="feat-category" data-cat="${cat}">
      <div class="flex items-center gap-1 mb-2 mt-3">
        <span style="font-size:1.1rem">${categoryIcons[cat] || '🔧'}</span>
        <h3 style="font-size:.9rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--text-muted)">${cat}</h3>
        <span class="badge badge-gray" style="margin-left:.5rem">${feats.length}</span>
      </div>
      <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(320px,1fr));gap:.75rem">
        ${feats.map(f => `
          <div class="feat-card card" data-id="${f.id}" data-name="${f.name.toLowerCase()}" data-cat="${cat}">
            <div class="card-body" style="padding:1rem">
              <div class="flex justify-between items-center">
                <div>
                  <div style="font-weight:600;font-size:.88rem">${f.name}</div>
                  <div style="font-size:.75rem;color:var(--text-muted);margin-top:.15rem;line-height:1.4">${f.description}</div>
                  ${f.min_ram_mb > 0 ? `<div style="font-size:.72rem;color:var(--text-muted);margin-top:.2rem">Requires ${f.min_ram_mb}MB RAM</div>` : ''}
                </div>
                <div style="display:flex;flex-direction:column;align-items:flex-end;gap:.4rem;margin-left:1rem;flex-shrink:0">
                  ${f.installed
                    ? `<label class="toggle-switch" title="${f.enabled ? 'Disable' : 'Enable'}">
                        <input type="checkbox" ${f.enabled ? 'checked' : ''} onchange="FeaturesManager.toggle(${f.id}, this.checked)">
                        <span class="toggle-slider"></span>
                       </label>`
                    : `<button class="btn btn-primary btn-sm" onclick="FeaturesManager.install(${f.id})">Install</button>`
                  }
                  ${f.installed ? `<span class="badge badge-green" style="font-size:.65rem">Installed</span>` : `<span class="badge badge-gray" style="font-size:.65rem">Not installed</span>`}
                </div>
              </div>
            </div>
          </div>`).join('')}
      </div>
    </div>`).join('')}
</div>

<style>
.toggle-switch { position:relative; display:inline-block; width:40px; height:22px; }
.toggle-switch input { opacity:0; width:0; height:0; }
.toggle-slider {
  position:absolute; cursor:pointer; inset:0;
  background:var(--border); border-radius:999px; transition:.2s;
}
.toggle-slider:before {
  content:''; position:absolute; width:16px; height:16px;
  left:3px; bottom:3px; background:#fff; border-radius:50%; transition:.2s;
}
input:checked + .toggle-slider { background:var(--primary); }
input:checked + .toggle-slider:before { transform:translateX(18px); }
</style>`;
  },

  async toggle(id, enable) {
    const res = await Nova.api('features', 'toggle', { method: 'POST', body: { id, enable } });
    if (res?.data?.action === 'install_required') {
      Nova.confirm(`"${res.data.feature.name}" must be installed first. Install now?`, () => this.install(id));
      return;
    }
    Nova.toast(res?.message || 'Updated', res?.success ? 'success' : 'error');
  },

  async install(id) {
    const res = await Nova.api('features', 'install', { method: 'POST', body: { id } });
    if (!res?.success) { Nova.toast(res?.message || 'Install failed', 'error'); return; }

    const logDiv = `<div class="terminal" id="install-log-${id}" style="min-height:100px">Starting installation…</div>`;
    const ov = Nova.modal('Installing Feature', logDiv);
    const poll = setInterval(async () => {
      const logRes = await Nova.api('features', 'install-log', { params: { id } });
      const logEl = document.getElementById(`install-log-${id}`);
      if (logEl) logEl.innerHTML = (logRes?.data?.log || '').split('\n').map(l => `<div>${l}</div>`).join('');
      if (!logRes?.data?.running) {
        clearInterval(poll);
        if (logRes?.data?.installed) {
          Nova.toast('Feature installed successfully', 'success');
          ov.remove();
          document.getElementById('page-content').innerHTML = await this.load();
          this.bindSearch();
        }
      }
    }, 2000);
  },

  bindSearch() {
    const search = document.getElementById('feat-search');
    const catFilter = document.getElementById('feat-cat-filter');
    if (!search || !catFilter) return;

    const filter = () => {
      const q = search.value.toLowerCase();
      const cat = catFilter.value;
      document.querySelectorAll('.feat-card').forEach(c => {
        const match = (!q || c.dataset.name.includes(q)) && (!cat || c.dataset.cat === cat);
        c.closest('div').style.display = match ? '' : 'none';
      });
      document.querySelectorAll('.feat-category').forEach(sec => {
        const visible = [...sec.querySelectorAll('.feat-card')].some(c => c.closest('div').style.display !== 'none');
        sec.style.display = visible ? '' : 'none';
      });
    };
    search.addEventListener('input', filter);
    catFilter.addEventListener('change', filter);
  },
};
