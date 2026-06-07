#!/usr/bin/env bash
# NovaCPX Installer — Linux Web Hosting Control Panel
# Supports: Ubuntu 20.04/22.04/24.04, Debian 11/12
# Usage: curl -fsSL https://novacpx.io/install.sh | bash
#        or: bash install.sh [--nginx|--apache] [--no-mysql] [--no-postgres]

set -euo pipefail

NOVACPX_VERSION="1.0.0"
PANEL_DIR="/opt/novacpx"
WEB_ROOT="/srv/novacpx/public"
LOG="/var/log/novacpx-install.log"
DB_NAME="novacpx"
DB_USER="novacpx_user"
PHP_DEFAULT="8.3"

# ── Panel ports (each tier has its own port) ──────────────────────────────────
PORT_USER=8880       # End-user panel
PORT_RESELLER=8881   # Reseller panel
PORT_ADMIN=8882      # Admin / datacenter panel
PORT_WEBMAIL=8883    # Roundcube webmail

# ── Colors ────────────────────────────────────────────────────────────────────
RED='\033[0;31m'; GREEN='\033[0;32m'; YELLOW='\033[1;33m'
BLUE='\033[0;34m'; BOLD='\033[1m'; NC='\033[0m'

log()  { echo -e "${GREEN}[✓]${NC} $*" | tee -a "$LOG"; }
warn() { echo -e "${YELLOW}[!]${NC} $*" | tee -a "$LOG"; }
fail() { echo -e "${RED}[✗]${NC} $*" | tee -a "$LOG"; exit 1; }
info() { echo -e "${BLUE}[→]${NC} $*" | tee -a "$LOG"; }
step() { echo -e "\n${BOLD}━━━ $* ━━━${NC}" | tee -a "$LOG"; }

# ── Argument parsing ──────────────────────────────────────────────────────────
WEB_SERVER="apache"
INSTALL_MYSQL=true
INSTALL_POSTGRES=true

for arg in "$@"; do
  case "$arg" in
    --nginx)       WEB_SERVER="nginx" ;;
    --apache)      WEB_SERVER="apache" ;;
    --no-mysql)    INSTALL_MYSQL=false ;;
    --no-postgres) INSTALL_POSTGRES=false ;;
  esac
done

# ── Banner ─────────────────────────────────────────────────────────────────────
clear
cat <<'EOF'

  ███╗   ██╗ ██████╗ ██╗   ██╗ █████╗  ██████╗██████╗ ██╗  ██╗
  ████╗  ██║██╔═══██╗██║   ██║██╔══██╗██╔════╝██╔══██╗╚██╗██╔╝
  ██╔██╗ ██║██║   ██║██║   ██║███████║██║     ██████╔╝ ╚███╔╝
  ██║╚██╗██║██║   ██║╚██╗ ██╔╝██╔══██║██║     ██╔═══╝  ██╔██╗
  ██║ ╚████║╚██████╔╝ ╚████╔╝ ██║  ██║╚██████╗██║     ██╔╝ ██╗
  ╚═╝  ╚═══╝ ╚═════╝   ╚═══╝  ╚═╝  ╚═╝ ╚═════╝╚═╝     ╚═╝  ╚═╝

  Linux Web Hosting Control Panel  |  v${NOVACPX_VERSION}
  ─────────────────────────────────────────────────────────────
EOF

echo ""

# ── Preflight checks ──────────────────────────────────────────────────────────
step "Preflight Checks"

[[ $EUID -ne 0 ]] && fail "Must run as root. Use: sudo bash install.sh"

# OS detection
if [[ -f /etc/os-release ]]; then
  . /etc/os-release
  OS_ID="$ID"
  OS_VER="$VERSION_ID"
  OS_CODENAME="${VERSION_CODENAME:-}"
else
  fail "Cannot detect OS. /etc/os-release missing."
fi

case "$OS_ID" in
  ubuntu)
    case "$OS_VER" in
      20.04|22.04|24.04) log "Detected: Ubuntu $OS_VER" ;;
      *) fail "Ubuntu $OS_VER not supported. Use 20.04, 22.04, or 24.04." ;;
    esac
    ;;
  debian)
    case "$OS_VER" in
      11|12) log "Detected: Debian $OS_VER ($OS_CODENAME)" ;;
      *) fail "Debian $OS_VER not supported. Use Debian 11 (Bullseye) or 12 (Bookworm)." ;;
    esac
    ;;
  *) fail "Unsupported OS: $OS_ID. NovaCPX supports Ubuntu 20/22/24 and Debian 11/12." ;;
esac

log "Web server: $WEB_SERVER"
log "MySQL: $INSTALL_MYSQL | PostgreSQL: $INSTALL_POSTGRES"

# Check minimum requirements
TOTAL_RAM=$(awk '/MemTotal/ {print int($2/1024)}' /proc/meminfo)
TOTAL_DISK=$(df / | awk 'NR==2 {print int($4/1024/1024)}')
log "RAM: ${TOTAL_RAM}MB | Free disk: ${TOTAL_DISK}GB"
[[ $TOTAL_RAM -lt 512 ]] && warn "Low RAM (${TOTAL_RAM}MB). Recommend 1GB+ for best performance."
[[ $TOTAL_DISK -lt 5 ]] && fail "Insufficient disk space. Need 5GB+ free."

# ── Generate secrets ──────────────────────────────────────────────────────────
step "Generating Credentials"

DB_PASS=$(openssl rand -base64 24 | tr -dc 'A-Za-z0-9!@#$' | head -c 20)
ADMIN_PASS=$(openssl rand -base64 16 | tr -dc 'A-Za-z0-9' | head -c 16)
SECRET_KEY=$(openssl rand -hex 32)
mkdir -p /root/.novacpx
cat > /root/.novacpx/credentials.txt <<CREDS
NovaCPX Installation Credentials — $(date)
==========================================
User Panel:     https://$(hostname -I | awk '{print $1}'):${PORT_USER}
Reseller Panel: https://$(hostname -I | awk '{print $1}'):${PORT_RESELLER}
Admin Panel:    https://$(hostname -I | awk '{print $1}'):${PORT_ADMIN}
Webmail:        https://$(hostname -I | awk '{print $1}'):${PORT_WEBMAIL}
Admin User:     admin
Admin Pass:     $ADMIN_PASS
DB Name:        $DB_NAME
DB User:        $DB_USER
DB Pass:        $DB_PASS
==========================================
SAVE THIS FILE. It will not be shown again.
CREDS
chmod 600 /root/.novacpx/credentials.txt
log "Credentials saved to /root/.novacpx/credentials.txt"

# ── System update ─────────────────────────────────────────────────────────────
step "Updating System Packages"
export DEBIAN_FRONTEND=noninteractive
apt-get update -qq >> "$LOG" 2>&1
apt-get upgrade -y -qq >> "$LOG" 2>&1
apt-get install -y -qq curl wget gnupg2 lsb-release ca-certificates \
  software-properties-common apt-transport-https unzip git \
  sudo cron logrotate ufw fail2ban >> "$LOG" 2>&1
log "System packages updated"

# ── PHP multi-version setup ───────────────────────────────────────────────────
step "Installing PHP (Multi-Version)"

# Add ondrej/php PPA for Ubuntu; sury for Debian
if [[ "$OS_ID" == "ubuntu" ]]; then
  add-apt-repository -y ppa:ondrej/php >> "$LOG" 2>&1
elif [[ "$OS_ID" == "debian" ]]; then
  curl -fsSL https://packages.sury.org/php/apt.gpg | gpg --dearmor -o /etc/apt/trusted.gpg.d/sury-php.gpg
  echo "deb https://packages.sury.org/php/ $OS_CODENAME main" > /etc/apt/sources.list.d/sury-php.list
fi

apt-get update -qq >> "$LOG" 2>&1

PHP_VERSIONS=("7.4" "8.1" "8.2" "8.3")
PHP_EXTENSIONS="cli fpm common mysql pgsql gd curl mbstring xml zip bcmath intl soap redis imagick opcache"

for VER in "${PHP_VERSIONS[@]}"; do
  info "Installing PHP $VER..."
  PKGS=""
  for EXT in $PHP_EXTENSIONS; do
    PKGS="$PKGS php${VER}-${EXT}"
  done
  apt-get install -y -qq php${VER} $PKGS >> "$LOG" 2>&1 || warn "PHP $VER: some extensions may not be available"
  log "PHP $VER installed"
done

# Set default PHP CLI
update-alternatives --set php /usr/bin/php${PHP_DEFAULT} >> "$LOG" 2>&1 || true
log "Default PHP CLI: $PHP_DEFAULT"

# ── Web Server ────────────────────────────────────────────────────────────────
step "Installing Web Server ($WEB_SERVER)"

if [[ "$WEB_SERVER" == "nginx" ]]; then
  apt-get install -y -qq nginx >> "$LOG" 2>&1
  systemctl enable nginx >> "$LOG" 2>&1
  log "nginx installed"

  PANEL_WEB_CONF="/etc/nginx/sites-available/novacpx"
  cat > "$PANEL_WEB_CONF" <<NGXCONF
# NovaCPX — three panels on three dedicated ports

# ── User Panel (8880) ─────────────────────────────────────────────────────────
server {
    listen ${PORT_USER} ssl http2;
    server_name _;
    root ${WEB_ROOT}/user;
    index index.php;
    ssl_certificate     /etc/novacpx/ssl/novacpx.crt;
    ssl_certificate_key /etc/novacpx/ssl/novacpx.key;
    location / { try_files \$uri \$uri/ /index.php?\$query_string; }
    location /api/ { fastcgi_pass unix:/run/php/php${PHP_DEFAULT}-fpm.sock; include fastcgi_params; fastcgi_param SCRIPT_FILENAME ${WEB_ROOT}/api/index.php; }
    location ~ \.php$ { fastcgi_pass unix:/run/php/php${PHP_DEFAULT}-fpm.sock; include fastcgi_params; fastcgi_param SCRIPT_FILENAME \$document_root\$fastcgi_script_name; }
    location /assets/ { root ${WEB_ROOT}; }
    location ~ /\.ht { deny all; }
}

# ── Reseller Panel (8881) ─────────────────────────────────────────────────────
server {
    listen ${PORT_RESELLER} ssl http2;
    server_name _;
    root ${WEB_ROOT}/reseller;
    index index.php;
    ssl_certificate     /etc/novacpx/ssl/novacpx.crt;
    ssl_certificate_key /etc/novacpx/ssl/novacpx.key;
    location / { try_files \$uri \$uri/ /index.php?\$query_string; }
    location /api/ { fastcgi_pass unix:/run/php/php${PHP_DEFAULT}-fpm.sock; include fastcgi_params; fastcgi_param SCRIPT_FILENAME ${WEB_ROOT}/api/index.php; }
    location ~ \.php$ { fastcgi_pass unix:/run/php/php${PHP_DEFAULT}-fpm.sock; include fastcgi_params; fastcgi_param SCRIPT_FILENAME \$document_root\$fastcgi_script_name; }
    location /assets/ { root ${WEB_ROOT}; }
    location ~ /\.ht { deny all; }
}

# ── Admin Panel (8882) ────────────────────────────────────────────────────────
server {
    listen ${PORT_ADMIN} ssl http2;
    server_name _;
    root ${WEB_ROOT}/admin;
    index index.php;
    ssl_certificate     /etc/novacpx/ssl/novacpx.crt;
    ssl_certificate_key /etc/novacpx/ssl/novacpx.key;
    location / { try_files \$uri \$uri/ /index.php?\$query_string; }
    location /api/ { fastcgi_pass unix:/run/php/php${PHP_DEFAULT}-fpm.sock; include fastcgi_params; fastcgi_param SCRIPT_FILENAME ${WEB_ROOT}/api/index.php; }
    location ~ \.php$ { fastcgi_pass unix:/run/php/php${PHP_DEFAULT}-fpm.sock; include fastcgi_params; fastcgi_param SCRIPT_FILENAME \$document_root\$fastcgi_script_name; }
    location /assets/ { root ${WEB_ROOT}; }
    location ~ /\.ht { deny all; }
}
NGXCONF
  ln -sf "$PANEL_WEB_CONF" /etc/nginx/sites-enabled/novacpx

else
  apt-get install -y -qq apache2 libapache2-mod-fcgid >> "$LOG" 2>&1
  a2enmod ssl rewrite proxy_fcgi setenvif headers >> "$LOG" 2>&1
  systemctl enable apache2 >> "$LOG" 2>&1
  log "Apache2 installed"

  # Tell Apache to listen on all three panel ports
  for PORT in $PORT_USER $PORT_RESELLER $PORT_ADMIN; do
    grep -q "Listen $PORT" /etc/apache2/ports.conf 2>/dev/null || echo "Listen $PORT" >> /etc/apache2/ports.conf
  done

  PANEL_WEB_CONF="/etc/apache2/sites-available/novacpx.conf"
  cat > "$PANEL_WEB_CONF" <<APCONF
# NovaCPX — three panels on three dedicated ports

# ── User Panel (8880) ─────────────────────────────────────────────────────────
<VirtualHost *:${PORT_USER}>
    DocumentRoot ${WEB_ROOT}/user
    SSLEngine on
    SSLCertificateFile    /etc/novacpx/ssl/novacpx.crt
    SSLCertificateKeyFile /etc/novacpx/ssl/novacpx.key
    Alias /assets ${WEB_ROOT}/assets
    Alias /api    ${WEB_ROOT}/api
    <Directory ${WEB_ROOT}>
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>
    <FilesMatch "\.php\$">
        SetHandler "proxy:unix:/run/php/php${PHP_DEFAULT}-fpm.sock|fcgi://localhost/"
    </FilesMatch>
    Header always set X-NovaCPX-Portal "user"
</VirtualHost>

# ── Reseller Panel (8881) ─────────────────────────────────────────────────────
<VirtualHost *:${PORT_RESELLER}>
    DocumentRoot ${WEB_ROOT}/reseller
    SSLEngine on
    SSLCertificateFile    /etc/novacpx/ssl/novacpx.crt
    SSLCertificateKeyFile /etc/novacpx/ssl/novacpx.key
    Alias /assets ${WEB_ROOT}/assets
    Alias /api    ${WEB_ROOT}/api
    <Directory ${WEB_ROOT}>
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>
    <FilesMatch "\.php\$">
        SetHandler "proxy:unix:/run/php/php${PHP_DEFAULT}-fpm.sock|fcgi://localhost/"
    </FilesMatch>
    Header always set X-NovaCPX-Portal "reseller"
</VirtualHost>

# ── Admin Panel (8882) ────────────────────────────────────────────────────────
<VirtualHost *:${PORT_ADMIN}>
    DocumentRoot ${WEB_ROOT}/admin
    SSLEngine on
    SSLCertificateFile    /etc/novacpx/ssl/novacpx.crt
    SSLCertificateKeyFile /etc/novacpx/ssl/novacpx.key
    Alias /assets ${WEB_ROOT}/assets
    Alias /api    ${WEB_ROOT}/api
    <Directory ${WEB_ROOT}>
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>
    <FilesMatch "\.php\$">
        SetHandler "proxy:unix:/run/php/php${PHP_DEFAULT}-fpm.sock|fcgi://localhost/"
    </FilesMatch>
    Header always set X-NovaCPX-Portal "admin"
</VirtualHost>
APCONF
  a2ensite novacpx >> "$LOG" 2>&1
  a2enconf php${PHP_DEFAULT}-fpm >> "$LOG" 2>&1 || true
fi

# Enable PHP-FPM services
for VER in "${PHP_VERSIONS[@]}"; do
  systemctl enable php${VER}-fpm >> "$LOG" 2>&1 && systemctl start php${VER}-fpm >> "$LOG" 2>&1 || true
done

# ── MySQL ─────────────────────────────────────────────────────────────────────
if $INSTALL_MYSQL; then
  step "Installing MySQL 8"
  apt-get install -y -qq mysql-server >> "$LOG" 2>&1
  systemctl enable mysql >> "$LOG" 2>&1
  systemctl start mysql >> "$LOG" 2>&1
  mysql -e "CREATE DATABASE IF NOT EXISTS $DB_NAME CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;" >> "$LOG" 2>&1
  mysql -e "CREATE USER IF NOT EXISTS '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASS}';" >> "$LOG" 2>&1
  mysql -e "GRANT ALL PRIVILEGES ON ${DB_NAME}.* TO '${DB_USER}'@'localhost';" >> "$LOG" 2>&1
  mysql -e "FLUSH PRIVILEGES;" >> "$LOG" 2>&1
  log "MySQL installed and database created"
fi

# ── PostgreSQL ────────────────────────────────────────────────────────────────
if $INSTALL_POSTGRES; then
  step "Installing PostgreSQL"
  apt-get install -y -qq postgresql postgresql-contrib >> "$LOG" 2>&1
  systemctl enable postgresql >> "$LOG" 2>&1
  log "PostgreSQL installed"
fi

# ── BIND9 DNS ─────────────────────────────────────────────────────────────────
step "Installing BIND9 DNS Server"
apt-get install -y -qq bind9 bind9utils bind9-doc >> "$LOG" 2>&1
systemctl enable named >> "$LOG" 2>&1

cat > /etc/bind/named.conf.options <<BINDCONF
options {
    directory "/var/cache/bind";
    recursion yes;
    allow-recursion { localhost; };
    listen-on { any; };
    forwarders { 8.8.8.8; 1.1.1.1; };
    dnssec-validation auto;
    auth-nxdomain no;
};
BINDCONF

systemctl restart named >> "$LOG" 2>&1
log "BIND9 DNS installed"

# ── Postfix + Dovecot (Mail) ──────────────────────────────────────────────────
step "Installing Mail Server (Postfix + Dovecot)"
HOSTNAME=$(hostname -f)
debconf-set-selections <<< "postfix postfix/mailname string $HOSTNAME"
debconf-set-selections <<< "postfix postfix/main_mailer_type string 'Internet Site'"
apt-get install -y -qq postfix postfix-mysql dovecot-core dovecot-imapd \
  dovecot-pop3d dovecot-lmtpd dovecot-mysql spamassassin >> "$LOG" 2>&1
systemctl enable postfix dovecot >> "$LOG" 2>&1
log "Mail server installed (Postfix + Dovecot)"

# ── ProFTPD ───────────────────────────────────────────────────────────────────
step "Installing ProFTPD"
apt-get install -y -qq proftpd-basic proftpd-mod-mysql >> "$LOG" 2>&1
systemctl enable proftpd >> "$LOG" 2>&1
log "ProFTPD installed"

# ── SSL Certificate ───────────────────────────────────────────────────────────
step "Generating Self-Signed SSL (Panel)"
mkdir -p /etc/novacpx/ssl
openssl req -x509 -nodes -days 3650 -newkey rsa:2048 \
  -keyout /etc/novacpx/ssl/novacpx.key \
  -out /etc/novacpx/ssl/novacpx.crt \
  -subj "/CN=$(hostname -I | awk '{print $1}')/O=NovaCPX/C=US" >> "$LOG" 2>&1
chmod 600 /etc/novacpx/ssl/novacpx.key
log "SSL certificate generated"

# Install certbot for Let's Encrypt
apt-get install -y -qq certbot >> "$LOG" 2>&1
log "Certbot installed for Let's Encrypt SSL"

# ── Roundcube Webmail ─────────────────────────────────────────────────────────
step "Installing Roundcube Webmail (port ${PORT_WEBMAIL})"
apt-get install -y -qq roundcube roundcube-mysql php8.3-intl php8.3-ldap >> "$LOG" 2>&1
RC_ROOT="/usr/share/roundcube"
mkdir -p /etc/novacpx/roundcube

# Roundcube config
RC_DB_PASS=$(openssl rand -base64 16 | tr -dc 'A-Za-z0-9' | head -c 16)
mysql -e "CREATE DATABASE IF NOT EXISTS roundcube CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;" >> "$LOG" 2>&1
mysql -e "CREATE USER IF NOT EXISTS 'roundcube'@'localhost' IDENTIFIED BY '${RC_DB_PASS}';" >> "$LOG" 2>&1
mysql -e "GRANT ALL PRIVILEGES ON roundcube.* TO 'roundcube'@'localhost';" >> "$LOG" 2>&1
mysql roundcube < /usr/share/dbconfig-common/data/roundcube/install/mysql 2>/dev/null || true

cat > /etc/roundcube/config.inc.php <<RCCONF
<?php
\$config['db_dsnw'] = 'mysql://roundcube:${RC_DB_PASS}@localhost/roundcube';
\$config['default_host'] = 'localhost';
\$config['default_port'] = 143;
\$config['smtp_server'] = 'localhost';
\$config['smtp_port'] = 587;
\$config['des_key'] = '$(openssl rand -base64 24 | head -c 24)';
\$config['plugins'] = ['archive','attachment_reminder','emoticons','markasjunk','newmail_notifier','zipdownload'];
\$config['skin'] = 'elastic';
\$config['session_lifetime'] = 60;
\$config['product_name'] = 'NovaCPX Webmail';
RCCONF

# Webmail vhost on port 8883
if [[ "$WEB_SERVER" == "nginx" ]]; then
  cat >> "$PANEL_WEB_CONF" <<WMNGX

# ── Webmail (8883) ────────────────────────────────────────────────────────────
server {
    listen ${PORT_WEBMAIL} ssl http2;
    server_name _;
    root ${RC_ROOT};
    index index.php;
    ssl_certificate     /etc/novacpx/ssl/novacpx.crt;
    ssl_certificate_key /etc/novacpx/ssl/novacpx.key;
    location / { try_files \$uri \$uri/ /index.php; }
    location ~ \.php$ { fastcgi_pass unix:/run/php/php8.3-fpm.sock; include fastcgi_params; fastcgi_param SCRIPT_FILENAME \$document_root\$fastcgi_script_name; }
    location ~ /\.(ht|git) { deny all; }
}
WMNGX
else
  cat >> "$PANEL_WEB_CONF" <<WMAP

# ── Webmail (8883) ────────────────────────────────────────────────────────────
<VirtualHost *:${PORT_WEBMAIL}>
    DocumentRoot ${RC_ROOT}
    SSLEngine on
    SSLCertificateFile    /etc/novacpx/ssl/novacpx.crt
    SSLCertificateKeyFile /etc/novacpx/ssl/novacpx.key
    <Directory ${RC_ROOT}>
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>
    <FilesMatch "\.php$">
        SetHandler "proxy:unix:/run/php/php8.3-fpm.sock|fcgi://localhost/"
    </FilesMatch>
    Header always set X-NovaCPX-Portal "webmail"
</VirtualHost>
WMAP
fi

log "Roundcube webmail installed on port ${PORT_WEBMAIL}"

# ── Panel installation ────────────────────────────────────────────────────────
step "Installing NovaCPX Panel"
mkdir -p "$WEB_ROOT" "$PANEL_DIR"

# Install panel files from GitHub
if [[ -d /opt/novacpx-src ]]; then
  cp -r /opt/novacpx-src/panel/public/* "$WEB_ROOT/"
  cp -r /opt/novacpx-src/panel/api "$WEB_ROOT/api"
  cp -r /opt/novacpx-src/panel/lib "$WEB_ROOT/lib"
  cp -r /opt/novacpx-src/panel/lib /opt/novacpx/lib
fi

# Write config
mkdir -p /etc/novacpx
cat > /etc/novacpx/config.ini <<CONFIG
[database]
host     = localhost
name     = ${DB_NAME}
user     = ${DB_USER}
pass     = ${DB_PASS}

[panel]
secret        = ${SECRET_KEY}
port_user     = ${PORT_USER}
port_reseller = ${PORT_RESELLER}
port_admin    = ${PORT_ADMIN}
port_webmail  = ${PORT_WEBMAIL}
webroot       = ${WEB_ROOT}
version       = ${NOVACPX_VERSION}

[web]
server      = ${WEB_SERVER}
php_default = ${PHP_DEFAULT}
CONFIG
chmod 600 /etc/novacpx/config.ini

# Import database schema
if [[ -f /opt/novacpx-src/db/schema.sql ]]; then
  mysql "$DB_NAME" < /opt/novacpx-src/db/schema.sql >> "$LOG" 2>&1
  # Create admin user
  ADMIN_HASH=$(php -r "echo password_hash('${ADMIN_PASS}', PASSWORD_BCRYPT);")
  mysql "$DB_NAME" -e "INSERT INTO users (username,password,email,role,status) VALUES ('admin','$ADMIN_HASH','root@localhost','admin','active') ON DUPLICATE KEY UPDATE password='$ADMIN_HASH';" >> "$LOG" 2>&1
  log "Database schema imported and admin user created"
fi

# Set permissions
chown -R www-data:www-data "$WEB_ROOT"
chmod -R 750 "$WEB_ROOT"

# ── Firewall ──────────────────────────────────────────────────────────────────
step "Configuring Firewall (UFW)"
ufw --force reset >> "$LOG" 2>&1
ufw default deny incoming >> "$LOG" 2>&1
ufw default allow outgoing >> "$LOG" 2>&1
ufw allow ssh >> "$LOG" 2>&1
ufw allow 80/tcp >> "$LOG" 2>&1    # HTTP
ufw allow 443/tcp >> "$LOG" 2>&1   # HTTPS
ufw allow ${PORT_USER}/tcp     >> "$LOG" 2>&1  # NovaCPX user panel
ufw allow ${PORT_RESELLER}/tcp >> "$LOG" 2>&1  # NovaCPX reseller panel
ufw allow ${PORT_ADMIN}/tcp    >> "$LOG" 2>&1  # NovaCPX admin panel
ufw allow ${PORT_WEBMAIL}/tcp  >> "$LOG" 2>&1  # Roundcube webmail
ufw allow 21/tcp >> "$LOG" 2>&1    # FTP
ufw allow 20/tcp >> "$LOG" 2>&1    # FTP data
ufw allow 25/tcp >> "$LOG" 2>&1    # SMTP
ufw allow 587/tcp >> "$LOG" 2>&1   # SMTP submission
ufw allow 465/tcp >> "$LOG" 2>&1   # SMTPS
ufw allow 110/tcp >> "$LOG" 2>&1   # POP3
ufw allow 995/tcp >> "$LOG" 2>&1   # POP3S
ufw allow 143/tcp >> "$LOG" 2>&1   # IMAP
ufw allow 993/tcp >> "$LOG" 2>&1   # IMAPS
ufw allow 53/tcp >> "$LOG" 2>&1    # DNS
ufw allow 53/udp >> "$LOG" 2>&1    # DNS
ufw --force enable >> "$LOG" 2>&1
log "Firewall configured"

# ── Fail2Ban ─────────────────────────────────────────────────────────────────
step "Configuring Fail2Ban"
cat > /etc/fail2ban/jail.local <<F2B
[DEFAULT]
bantime  = 3600
findtime = 600
maxretry = 5

[sshd]
enabled = true

[novacpx-user]
enabled  = true
port     = ${PORT_USER}
logpath  = /var/log/novacpx/access.log
maxretry = 10

[novacpx-reseller]
enabled  = true
port     = ${PORT_RESELLER}
logpath  = /var/log/novacpx/access.log
maxretry = 10

[novacpx-admin]
enabled  = true
port     = ${PORT_ADMIN}
logpath  = /var/log/novacpx/access.log
maxretry = 5

[novacpx-webmail]
enabled  = true
port     = ${PORT_WEBMAIL}
logpath  = /var/log/novacpx/access.log
maxretry = 10
F2B
systemctl enable fail2ban >> "$LOG" 2>&1
systemctl restart fail2ban >> "$LOG" 2>&1
log "Fail2Ban configured"

# ── Cron jobs ─────────────────────────────────────────────────────────────────
step "Setting Up Cron Jobs"
cat > /etc/cron.d/novacpx <<CRON
# NovaCPX system cron jobs
*/5  * * * * www-data /usr/local/bin/php${PHP_DEFAULT} ${WEB_ROOT}/api/cron/monitor.php >> /var/log/novacpx/cron.log 2>&1
0    * * * * root     /usr/local/bin/novacpx-ssl-renew >> /var/log/novacpx/ssl.log 2>&1
0    2 * * * root     /usr/local/bin/novacpx-backup >> /var/log/novacpx/backup.log 2>&1
*/1  * * * * root     /usr/local/bin/novacpx-dns-sync >> /var/log/novacpx/dns.log 2>&1
CRON
mkdir -p /var/log/novacpx
log "Cron jobs installed"

# ── Restart services ──────────────────────────────────────────────────────────
step "Starting All Services"
if [[ "$WEB_SERVER" == "nginx" ]]; then
  systemctl restart nginx >> "$LOG" 2>&1
else
  systemctl restart apache2 >> "$LOG" 2>&1
fi
$INSTALL_MYSQL && systemctl restart mysql >> "$LOG" 2>&1
systemctl restart postfix dovecot proftpd named >> "$LOG" 2>&1
log "All services started"

# ── Done ─────────────────────────────────────────────────────────────────────
SERVER_IP=$(hostname -I | awk '{print $1}')
cat <<DONE

  ╔══════════════════════════════════════════════════════════════╗
  ║             NovaCPX Installation Complete!                  ║
  ╠══════════════════════════════════════════════════════════════╣
  ║  User Panel:     https://${SERVER_IP}:${PORT_USER}
  ║  Reseller Panel: https://${SERVER_IP}:${PORT_RESELLER}
  ║  Admin Panel:    https://${SERVER_IP}:${PORT_ADMIN}
  ║  Webmail:        https://${SERVER_IP}:${PORT_WEBMAIL}
  ║  Username:       admin
  ║  Password:       ${ADMIN_PASS}
  ╠══════════════════════════════════════════════════════════════╣
  ║  Credentials: /root/.novacpx/credentials.txt                ║
  ║  Install log: ${LOG}
  ╚══════════════════════════════════════════════════════════════╝

DONE
