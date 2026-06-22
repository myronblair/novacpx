#!/usr/bin/env bash
# NovaCPX Post-Restore Script
# Run after any PBS/backup restore to fix config drift and rebuild hosting state
# Usage: /usr/local/bin/novacpx-post-restore

set -euo pipefail
LOG="/var/log/novacpx/post-restore.log"
PAT=$(python3 -c "import configparser; c=configparser.ConfigParser(); c.read('/etc/novacpx/config.ini'); print(c.get('deploy','github_pat',fallback=''))" 2>/dev/null || true)
WEB_DASHBOARD_REPO="https://${PAT}@github.com/myronblair/web-dashboard.git"

log() { echo "[$(date '+%Y-%m-%d %H:%M:%S')] $*" | tee -a "$LOG"; }

log "=== NovaCPX Post-Restore Started ==="

# 1. Fix config.ini — always reverts to apache after restore
log "Fixing config.ini web server setting..."
sed -i 's/^server = apache/server = nginx/' /etc/novacpx/config.ini
grep "^server" /etc/novacpx/config.ini >> "$LOG"

# 2. Clean orphaned PHP-FPM pool configs (users deleted during restore)
log "Cleaning orphaned PHP-FPM pools..."
REMOVED=0
for f in /etc/php/8.3/fpm/pool.d/*.conf; do
    [[ "$f" == *"www.conf"* ]] && continue
    u=$(basename "$f" .conf)
    if ! id "$u" &>/dev/null; then
        log "  Removing orphan pool: $f"
        rm -f "$f"
        ((REMOVED++)) || true
    fi
done
log "  Removed $REMOVED orphaned pools"

# 3. Start/restart PHP-FPM
log "Starting PHP-FPM..."
systemctl start php8.3-fpm 2>/dev/null || systemctl restart php8.3-fpm
systemctl is-active php8.3-fpm >> "$LOG"

# 4. Check if webacct Linux user and hosting account exist
if ! id "webacct" &>/dev/null; then
    log "webacct user missing — creating hosting account for web.orbishosting.com..."
    php8.3 << 'PHPEOF' >> "$LOG" 2>&1
<?php
define('NOVACPX_ROOT', '/srv/novacpx/public');
define('NOVACPX_API',  NOVACPX_ROOT . '/api');
define('NOVACPX_LIB',  NOVACPX_ROOT . '/lib');
$_SERVER['SERVER_PORT'] = 8882;
require_once NOVACPX_LIB . '/Core.php';
require_once NOVACPX_LIB . '/DB.php';
require_once NOVACPX_LIB . '/VhostManager.php';
require_once NOVACPX_LIB . '/DNSManager.php';
require_once NOVACPX_LIB . '/PHPManager.php';
require_once NOVACPX_LIB . '/AccountManager.php';
$db = DB::getInstance();
$userId = (int)$db->insert(
    "INSERT INTO users (username, password, email, role, status) VALUES (?,?,?,?,?)",
    ['webacct', password_hash('Joker1974!!!', PASSWORD_BCRYPT), 'webacct@web.orbishosting.com', 'user', 'active']
);
$result = AccountManager::create(['username'=>'webacct','domain'=>'web.orbishosting.com',
    'password'=>'Joker1974!!!','user_id'=>$userId,'php_version'=>'8.3']);
echo "Account created: " . json_encode($result) . "\n";
PHPEOF
else
    log "webacct user exists — skipping account creation"
fi

# 5. Ensure nginx vhost for web.orbishosting.com exists
VHOST="/etc/nginx/sites-available/novacpx-webacct.conf"
if [[ ! -f "$VHOST" ]]; then
    log "Creating nginx vhost for web.orbishosting.com..."
    cat > "$VHOST" << 'NGINX'
server {
    listen 80;
    server_name web.orbishosting.com www.web.orbishosting.com;
    root /home/webacct/public_html;
    index index.php index.html index.htm;
    access_log /home/webacct/logs/access.log;
    error_log  /home/webacct/logs/error.log;

    auth_basic "Blair HQ";
    auth_basic_user_file /etc/nginx/htpasswd.webacct;

    location / { try_files $uri $uri/ /index.php?$query_string; }
    location ~ \.php$ {
        fastcgi_pass unix:/run/php/php8.3-fpm-webacct.sock;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
    }
    location ~ /\.ht { deny all; }
}
NGINX
    ln -sf "$VHOST" /etc/nginx/sites-enabled/novacpx-webacct.conf
    log "  Vhost created and enabled"
fi

# 6. Ensure Basic Auth password file exists
if [[ ! -f /etc/nginx/htpasswd.webacct ]]; then
    log "Creating htpasswd for web.orbishosting.com..."
    htpasswd -cb /etc/nginx/htpasswd.webacct "myronblair@outlook.com" "Joker1974!!!"
fi

# 7. Deploy Blair HQ dashboard from GitHub
log "Deploying Blair HQ dashboard from GitHub..."
TMP_DIR=$(mktemp -d)
git clone --depth=1 "$WEB_DASHBOARD_REPO" "$TMP_DIR" 2>> "$LOG" && \
    cp "$TMP_DIR/index.html" /home/webacct/public_html/index.html && \
    chown webacct:www-data /home/webacct/public_html/index.html && \
    log "  Dashboard deployed" || log "  Dashboard deploy failed (check PAT)"
rm -rf "$TMP_DIR"

# 8. Reload nginx
log "Reloading nginx..."
nginx -t >> "$LOG" 2>&1 && systemctl reload nginx
log "  nginx reloaded"

# 9. Disable Apache2 if present
if systemctl is-active apache2 &>/dev/null; then
    log "Disabling Apache2..."
    systemctl stop apache2 && systemctl disable apache2
fi

log "=== Post-Restore Complete ==="
echo ""
echo "✓ NovaCPX post-restore complete. Check $LOG for details."
