#!/usr/bin/env bash
# NovaCPX Post-Restore Script v2
# Run after any PBS/backup restore
# Usage: /usr/local/bin/novacpx-post-restore [--no-git]

set -euo pipefail
LOG="/var/log/novacpx/post-restore.log"
PAT=$(python3 -c "import configparser; c=configparser.ConfigParser(); c.read('/etc/novacpx/config.ini'); print(c.get('deploy','github_pat',fallback=''))" 2>/dev/null || echo "")
WEB_DASHBOARD_REPO="https://${PAT}@github.com/myronblair/web-dashboard.git"
NOVACPX_REPO="https://${PAT}@github.com/myronblair/novacpx.git"
SKIP_GIT="${1:-}"

log()  { echo "[$(date '+%Y-%m-%d %H:%M:%S')] $*" | tee -a "$LOG"; }
ok()   { log "  ✓ $*"; }
warn() { log "  ⚠ $*"; }

mkdir -p /var/log/novacpx
log "=== NovaCPX Post-Restore Started ==="

# ── 1. Fix config.ini ─────────────────────────────────────────────────────
log "1. Fixing config.ini..."
sed -i 's/^server = apache/server = nginx/' /etc/novacpx/config.ini
ok "server = $(grep '^server' /etc/novacpx/config.ini | cut -d= -f2 | tr -d ' ')"

# Disable Apache2 if running
systemctl stop apache2 2>/dev/null && systemctl disable apache2 2>/dev/null || true

# ── 2. Fix PHP-FPM ────────────────────────────────────────────────────────
log "2. Fixing PHP-FPM..."
REMOVED=0
for f in /etc/php/8.3/fpm/pool.d/*.conf; do
    [[ "$f" == *"www.conf"* ]] && continue
    u=$(basename "$f" .conf)
    id "$u" &>/dev/null || { rm -f "$f"; ((REMOVED++)) || true; }
done
[[ $REMOVED -gt 0 ]] && warn "Removed $REMOVED orphaned pools" || ok "No orphaned pools"

# Fix pm.max_children if still at default 5
PM=$(grep "^pm.max_children" /etc/php/8.3/fpm/pool.d/www.conf 2>/dev/null | awk '{print $3}')
if [[ "$PM" == "5" ]] || [[ -z "$PM" ]]; then
    echo -e "\npm = dynamic\npm.max_children = 20\npm.start_servers = 5\npm.min_spare_servers = 3\npm.max_spare_servers = 10" >> /etc/php/8.3/fpm/pool.d/www.conf
    ok "PHP-FPM max_children bumped to 20"
fi

systemctl start php8.3-fpm 2>/dev/null || systemctl restart php8.3-fpm
ok "PHP-FPM $(systemctl is-active php8.3-fpm)"

# ── 3. Pull latest NovaCPX code ───────────────────────────────────────────
if [[ "$SKIP_GIT" != "--no-git" ]] && [[ -n "$PAT" ]]; then
    log "3. Pulling latest NovaCPX code..."
    cd /opt/novacpx-src 2>/dev/null || { warn "novacpx-src not found, skipping"; true; }
    if [[ -d /opt/novacpx-src/.git ]]; then
        git remote set-url origin "$NOVACPX_REPO" 2>/dev/null
        git pull origin main 2>&1 | tail -3 | while read l; do log "  $l"; done
        rsync -a --delete --exclude=".git" --exclude="api/config.php" \
          /opt/novacpx-src/panel/public/ /srv/novacpx/public/ 2>/dev/null
        rsync -a --delete --exclude="config.php" \
          /opt/novacpx-src/panel/api/ /srv/novacpx/public/api/ 2>/dev/null
        rsync -a --delete /opt/novacpx-src/panel/lib/ /srv/novacpx/public/lib/ 2>/dev/null
        ok "Code deployed"
        # Run migrations
        DB=/var/lib/novacpx/panel.db
        for SQL in /opt/novacpx-src/db/migrations/*.sql; do
            [[ -f "$SQL" ]] || continue
            NAME=$(basename "$SQL" .sql)
            DONE=$(sqlite3 "$DB" "SELECT value FROM settings WHERE key='migration_$NAME'" 2>/dev/null)
            if [[ -z "$DONE" ]]; then
                sqlite3 "$DB" < "$SQL" 2>/dev/null && \
                sqlite3 "$DB" "INSERT OR REPLACE INTO settings (key,value,updated_at) VALUES ('migration_$NAME','$(date)',datetime('now'))" 2>/dev/null
                ok "Migration: $NAME"
            fi
        done
    fi
else
    log "3. Skipping git pull"
fi

# ── 4. Fix webacct hosting account ────────────────────────────────────────
log "4. Fixing webacct hosting account..."
DB=/var/lib/novacpx/panel.db

# Clean orphaned user record (user exists in DB but Linux user doesn't)
if id "webacct" &>/dev/null; then
    ok "webacct Linux user exists"
else
    warn "webacct Linux user missing — cleaning DB and recreating..."
    # Remove orphaned DB record
    sqlite3 "$DB" "DELETE FROM users WHERE username='webacct' AND id NOT IN (SELECT user_id FROM accounts WHERE username='webacct');" 2>/dev/null || true
    sqlite3 "$DB" "DELETE FROM users WHERE username='webacct' AND role='user';" 2>/dev/null || true

    # Create via PHP
    php8.3 /tmp/_nova_create_webacct.php >> "$LOG" 2>&1 || true
fi

# Ensure account exists in DB
ACCT_EXISTS=$(sqlite3 "$DB" "SELECT COUNT(*) FROM accounts WHERE username='webacct';" 2>/dev/null || echo "0")
if [[ "$ACCT_EXISTS" == "0" ]]; then
    warn "webacct account not in DB — creating..."
    cat > /tmp/_nova_create_webacct.php << 'PHPEOF'
<?php
define('NOVACPX_ROOT', '/srv/novacpx/public');
define('NOVACPX_API', NOVACPX_ROOT.'/api');
define('NOVACPX_LIB', NOVACPX_ROOT.'/lib');
$_SERVER['SERVER_PORT'] = 8882;
require_once NOVACPX_LIB.'/Core.php';
require_once NOVACPX_LIB.'/DB.php';
require_once NOVACPX_LIB.'/VhostManager.php';
require_once NOVACPX_LIB.'/DNSManager.php';
require_once NOVACPX_LIB.'/PHPManager.php';
require_once NOVACPX_LIB.'/AccountManager.php';
$db = DB::getInstance();
// Clean orphaned user
$db->execute("DELETE FROM users WHERE username='webacct' AND role='user'");
$uid = (int)$db->insert(
    "INSERT INTO users (username,password,email,role,status) VALUES (?,?,?,?,?)",
    ['webacct',password_hash('Joker1974!!!',PASSWORD_BCRYPT),'webacct@web.orbishosting.com','user','active']
);
$r = AccountManager::create(['username'=>'webacct','domain'=>'web.orbishosting.com','password'=>'Joker1974!!!','user_id'=>$uid,'php_version'=>'8.3']);
echo "Created: ".json_encode($r)."\n";
PHPEOF
    php8.3 /tmp/_nova_create_webacct.php >> "$LOG" 2>&1 && ok "Account created" || warn "Account creation failed — check log"
fi

# ── 5. Ensure nginx vhost and Basic Auth ──────────────────────────────────
log "5. Ensuring nginx vhost..."
VHOST="/etc/nginx/sites-available/novacpx-webacct.conf"
if [[ ! -f "$VHOST" ]]; then
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
    ok "Vhost created"
fi

[[ -f /etc/nginx/htpasswd.webacct ]] || {
    htpasswd -cb /etc/nginx/htpasswd.webacct "myronblair@outlook.com" "Joker1974!!!" 2>/dev/null
    ok "Basic auth created"
}

# ── 6. Deploy Blair HQ dashboard ──────────────────────────────────────────
log "6. Deploying Blair HQ dashboard..."
if [[ -n "$PAT" ]]; then
    TMP=$(mktemp -d)
    git clone --depth=1 "$WEB_DASHBOARD_REPO" "$TMP" 2>/dev/null && {
        cp "$TMP/index.html" /home/webacct/public_html/index.html 2>/dev/null
        cp "$TMP/notes.php" /home/webacct/public_html/notes.php 2>/dev/null
        chown webacct:www-data /home/webacct/public_html/index.html /home/webacct/public_html/notes.php 2>/dev/null
        [[ -f /home/webacct/notes.json ]] || { echo "[]" > /home/webacct/notes.json; chown webacct:www-data /home/webacct/notes.json; }
        ok "Dashboard deployed"
    } || warn "Dashboard deploy failed (check PAT)"
    rm -rf "$TMP"
fi

# ── 7. Reload nginx ───────────────────────────────────────────────────────
log "7. Reloading nginx..."
nginx -t 2>/dev/null && systemctl reload nginx && ok "nginx reloaded" || warn "nginx config error"
systemctl reload php8.3-fpm 2>/dev/null || true

log "=== Post-Restore Complete ==="
echo ""
echo "✓ NovaCPX post-restore complete. Log: $LOG"
