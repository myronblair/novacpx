#!/usr/bin/env bash
# NovaCPX Uninstaller
# Backs up everything, then cleanly removes all NovaCPX components.
# Usage: bash uninstall.sh [--yes]

set -euo pipefail
RED='\033[0;31m'; GREEN='\033[0;32m'; YELLOW='\033[1;33m'; BOLD='\033[1m'; NC='\033[0m'
log()  { echo -e "${GREEN}[✓]${NC} $*"; }
warn() { echo -e "${YELLOW}[!]${NC} $*"; }
fail() { echo -e "${RED}[✗]${NC} $*"; exit 1; }
step() { echo -e "\n${BOLD}━━━ $* ━━━${NC}"; }

[[ $EUID -ne 0 ]] && fail "Run as root"

SKIP_CONFIRM="${1:-}"
DB_PATH=$(python3 -c "import configparser; c=configparser.ConfigParser(); c.read('/etc/novacpx/config.ini'); print(c.get('database','path',fallback='/var/lib/novacpx/panel.db'))" 2>/dev/null || echo "/var/lib/novacpx/panel.db")
BACKUP_DIR="/tmp/novacpx-uninstall-backup-$(date +%Y%m%d-%H%M%S)"
BACKUP_ARCHIVE="${BACKUP_DIR}.tar.gz"

echo ""
echo -e "${BOLD}NovaCPX Uninstaller${NC}"
echo "This will completely remove NovaCPX and all hosted accounts."
echo ""

# ── STEP 1: Create full backup ─────────────────────────────────────────────
step "Creating full backup before removal"

mkdir -p "$BACKUP_DIR"/{db,configs,accounts,logs,certs,nginx,systemd,cron}

# Database
[[ -f "$DB_PATH" ]] && {
    cp "$DB_PATH" "$BACKUP_DIR/db/panel.db"
    log "Database backed up"
}

# All account home directories
if [[ -f "$DB_PATH" ]]; then
    while IFS='|' read -r user home; do
        [[ -d "$home" ]] && cp -a "$home" "$BACKUP_DIR/accounts/" 2>/dev/null && log "Account: $user ($home)"
    done < <(sqlite3 "$DB_PATH" "SELECT username, home_dir FROM accounts;" 2>/dev/null)
fi

# Config files
[[ -d /etc/novacpx ]] && cp -a /etc/novacpx "$BACKUP_DIR/configs/" && log "NovaCPX configs"
[[ -d /var/lib/novacpx ]] && cp -a /var/lib/novacpx "$BACKUP_DIR/db/var-lib-novacpx" 2>/dev/null

# Nginx vhosts
cp /etc/nginx/sites-available/novacpx-*.conf "$BACKUP_DIR/nginx/" 2>/dev/null || true
log "Nginx vhosts"

# SSL certs per account
[[ -d /etc/novacpx/ssl/accounts ]] && cp -a /etc/novacpx/ssl/accounts "$BACKUP_DIR/certs/" && log "SSL certs"

# Systemd units
cp /etc/systemd/system/novacpx*.service "$BACKUP_DIR/systemd/" 2>/dev/null || true
log "Systemd units"

# Cron
cp /etc/cron.d/novacpx "$BACKUP_DIR/cron/" 2>/dev/null || true
log "Cron jobs"

# Logs
[[ -d /var/log/novacpx ]] && cp -a /var/log/novacpx "$BACKUP_DIR/logs/" && log "Logs"

# DNS zones
[[ -d /var/cache/bind ]] && cp -a /var/cache/bind "$BACKUP_DIR/configs/bind-cache" 2>/dev/null || true
[[ -f /etc/bind/named.conf.local ]] && cp /etc/bind/named.conf.local "$BACKUP_DIR/configs/" 2>/dev/null || true

# Mail config
[[ -d /etc/postfix ]] && cp -a /etc/postfix "$BACKUP_DIR/configs/postfix" 2>/dev/null || true
[[ -d /etc/dovecot ]] && cp -a /etc/dovecot "$BACKUP_DIR/configs/dovecot" 2>/dev/null || true

# Compress backup
tar -czf "$BACKUP_ARCHIVE" -C "$(dirname $BACKUP_DIR)" "$(basename $BACKUP_DIR)" 2>/dev/null
rm -rf "$BACKUP_DIR"
BACKUP_SIZE=$(du -sh "$BACKUP_ARCHIVE" | cut -f1)
log "Backup archive: $BACKUP_ARCHIVE ($BACKUP_SIZE)"

echo ""
echo -e "${BOLD}Backup complete.${NC}"
echo ""
echo "To download the backup before continuing:"
echo "  scp root@$(hostname -I | awk '{print $1}'):${BACKUP_ARCHIVE} ./"
echo ""
echo "Or serve temporarily:"
echo "  cd $(dirname $BACKUP_ARCHIVE) && python3 -m http.server 9999 &"
echo "  # Download: http://$(hostname -I | awk '{print $1}'):9999/$(basename $BACKUP_ARCHIVE)"
echo ""

if [[ "$SKIP_CONFIRM" != "--yes" ]]; then
    read -r -p "Continue with uninstall? This CANNOT be undone. [yes/no]: " CONFIRM
    [[ "$CONFIRM" != "yes" ]] && { warn "Aborted."; exit 0; }
fi

# ── STEP 2: Stop all NovaCPX services ────────────────────────────────────
step "Stopping services"
systemctl stop novacpx-web 2>/dev/null && log "Stopped novacpx-web" || true
systemctl disable novacpx-web 2>/dev/null || true

# ── STEP 3: Remove hosting accounts ──────────────────────────────────────
step "Removing hosting accounts"
if [[ -f "$DB_PATH" ]]; then
    while IFS='|' read -r username home_dir; do
        [[ -z "$username" ]] && continue
        # Remove Linux user and home dir
        id "$username" &>/dev/null && userdel -r "$username" 2>/dev/null && log "Removed user: $username" || true
        # Remove PHP-FPM pool
        rm -f "/etc/php/8.3/fpm/pool.d/${username}.conf" 2>/dev/null || true
        # Remove nginx vhost
        rm -f "/etc/nginx/sites-available/novacpx-${username}.conf" \
              "/etc/nginx/sites-enabled/novacpx-${username}.conf" 2>/dev/null || true
    done < <(sqlite3 "$DB_PATH" "SELECT username, home_dir FROM accounts;" 2>/dev/null)
fi

# Also clean any remaining novacpx vhosts
rm -f /etc/nginx/sites-available/novacpx-*.conf \
       /etc/nginx/sites-enabled/novacpx-*.conf 2>/dev/null || true
log "Nginx vhosts removed"

# Remove webacct if exists
id webacct &>/dev/null && userdel -r webacct 2>/dev/null || true

# ── STEP 4: Remove PHP-FPM pools ─────────────────────────────────────────
step "Removing PHP-FPM pools"
for f in /etc/php/*/fpm/pool.d/novacpx-*.conf /etc/php/*/fpm/pool.d/webacct.conf; do
    [[ -f "$f" ]] && rm -f "$f" && log "Removed pool: $f" || true
done
systemctl reload php8.3-fpm 2>/dev/null || true

# ── STEP 5: Remove systemd units ─────────────────────────────────────────
step "Removing systemd units"
for svc in novacpx-web; do
    systemctl stop "$svc" 2>/dev/null; systemctl disable "$svc" 2>/dev/null
    rm -f "/etc/systemd/system/${svc}.service" "/etc/systemd/system/${svc}.service.d"
    log "Removed: $svc"
done
systemctl daemon-reload

# ── STEP 6: Remove sudoers ────────────────────────────────────────────────
step "Removing sudoers rules"
for f in novacpx novacpx-firewall novacpx-panel novacpx-services; do
    rm -f "/etc/sudoers.d/$f" && log "Removed sudoers: $f" || true
done
# Remove any novacpx entries added to main sudoers
sed -i '/Defaults:www-data !requiretty/d' /etc/sudoers 2>/dev/null || true

# ── STEP 7: Remove cron jobs ──────────────────────────────────────────────
step "Removing cron jobs"
rm -f /etc/cron.d/novacpx && log "Removed /etc/cron.d/novacpx" || true
# Remove root crontab entries added by NovaCPX
crontab -l 2>/dev/null | grep -v "novacpx" | crontab - 2>/dev/null || true
log "Root crontab cleaned"

# ── STEP 8: Remove nginx default override ────────────────────────────────
step "Restoring nginx defaults"
# Restore default site (remove 444 return we added)
cat > /etc/nginx/sites-available/default << 'NGINX'
server {
    listen 80 default_server;
    listen [::]:80 default_server;
    root /var/www/html;
    index index.html index.htm;
    server_name _;
    location / { try_files $uri $uri/ =404; }
}
NGINX
nginx -t 2>/dev/null && systemctl reload nginx && log "nginx restored"

# ── STEP 9: Remove mail/DNS config added by NovaCPX ──────────────────────
step "Removing mail/DNS config"
# Remove opendkim signing entries for hosted domains
[[ -f /etc/opendkim/signing.table ]] && > /etc/opendkim/signing.table || true
[[ -f /etc/opendkim/key.table ]]     && > /etc/opendkim/key.table     || true
rm -rf /etc/opendkim/keys/* 2>/dev/null || true
systemctl reload opendkim 2>/dev/null || true
log "DKIM config cleared"

# Remove DNS zones for hosted domains  
rm -f /etc/bind/zones/db.novacpx.* 2>/dev/null || true
# Remove novacpx entries from named.conf.local
[[ -f /etc/bind/named.conf.local ]] && \
    sed -i '/novacpx/d' /etc/bind/named.conf.local 2>/dev/null || true
systemctl reload named 2>/dev/null || true
log "DNS zones cleared"

# Postfix virtual mailbox maps
[[ -f /etc/postfix/novacpx_virtual_domains ]] && {
    rm -f /etc/postfix/novacpx_virtual_domains /etc/postfix/novacpx_virtual_mailbox \
          /etc/postfix/novacpx_virtual_aliases 2>/dev/null || true
    postmap /etc/postfix/novacpx_* 2>/dev/null || true
    systemctl reload postfix 2>/dev/null || true
    log "Postfix virtual tables cleared"
}

# ── STEP 10: Remove all NovaCPX files ────────────────────────────────────
step "Removing NovaCPX files"
rm -rf /srv/novacpx          && log "Removed /srv/novacpx"
rm -rf /opt/novacpx-src      && log "Removed /opt/novacpx-src"
rm -rf /opt/novacpx          && log "Removed /opt/novacpx"
rm -rf /var/lib/novacpx      && log "Removed /var/lib/novacpx"
rm -rf /var/log/novacpx      && log "Removed /var/log/novacpx"
rm -rf /etc/novacpx          && log "Removed /etc/novacpx"
rm -f  /etc/nginx/htpasswd.webacct 2>/dev/null || true
rm -f  /usr/local/bin/novacpx* /usr/local/bin/novacpx-* 2>/dev/null || true
# Remove fail2ban filters
rm -f /etc/fail2ban/filter.d/novacpx-*.conf \
       /etc/fail2ban/jail.d/novacpx*.conf 2>/dev/null || true
systemctl reload fail2ban 2>/dev/null || true
log "fail2ban filters removed"

# ── DONE ──────────────────────────────────────────────────────────────────
echo ""
echo -e "${GREEN}${BOLD}NovaCPX has been fully uninstalled.${NC}"
echo ""
echo "Backup archive preserved at: $BACKUP_ARCHIVE"
echo ""
echo "Services still running (not removed, NovaCPX didn't own these):"
echo "  nginx, php8.3-fpm, postfix, dovecot, bind9, fail2ban"
echo "  (stop/remove them separately if no longer needed)"
echo ""
