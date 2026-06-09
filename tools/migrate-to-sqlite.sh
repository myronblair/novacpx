#!/usr/bin/env bash
# Migrate NovaCPX panel DB from MySQL to SQLite
# Run as root on the NovaCPX VM.
# Usage: bash tools/migrate-to-sqlite.sh [--schema-only]

set -euo pipefail

SCHEMA_ONLY=false
[[ "${1:-}" == "--schema-only" ]] && SCHEMA_ONLY=true

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(dirname "$SCRIPT_DIR")"
DB_PATH="/var/lib/novacpx/panel.db"
SCHEMA="$REPO_ROOT/db/schema.sql"
CONFIG="/etc/novacpx/config.ini"
BACKUP_DIR="/var/lib/novacpx/migration-backup-$(date +%Y%m%d-%H%M%S)"

RED='\033[0;31m'; GREEN='\033[0;32m'; YELLOW='\033[1;33m'; NC='\033[0m'
log()  { echo -e "${GREEN}[✓]${NC} $*"; }
warn() { echo -e "${YELLOW}[!]${NC} $*"; }
fail() { echo -e "${RED}[✗]${NC} $*"; exit 1; }

[[ $EUID -ne 0 ]] && fail "Run as root."
command -v sqlite3 >/dev/null 2>&1 || { apt-get install -y sqlite3 >> /dev/null; log "sqlite3 installed"; }

# ── Read MySQL creds from existing config ─────────────────────────────────────
read_ini() { grep -E "^\s*$1\s*=" "$CONFIG" | head -1 | cut -d= -f2- | tr -d ' '; }

DB_HOST=$(read_ini host)
DB_NAME=$(read_ini name)
DB_USER=$(read_ini user)
DB_PASS=$(read_ini pass)
DB_WP_USER=$(read_ini wp_user)
DB_WP_PASS=$(read_ini wp_pass)
SECRET_KEY=$(read_ini secret)

if [[ -z "$DB_NAME" ]]; then
  warn "No MySQL config found in $CONFIG — this install may already be on SQLite."
  warn "If you only need to (re)create the SQLite DB from schema, use --schema-only."
  [[ "$SCHEMA_ONLY" == "false" ]] && exit 0
fi

# ── Backup ────────────────────────────────────────────────────────────────────
log "Backing up to $BACKUP_DIR..."
mkdir -p "$BACKUP_DIR"
[[ -f "$DB_PATH" ]] && cp "$DB_PATH" "$BACKUP_DIR/panel.db.bak"
cp "$CONFIG" "$BACKUP_DIR/config.ini.bak"
[[ -n "$DB_NAME" ]] && mysqldump -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" > "$BACKUP_DIR/mysql-dump.sql" 2>/dev/null && log "MySQL dump saved" || warn "MySQL dump failed (service may be down)"

# ── Create SQLite DB from schema ──────────────────────────────────────────────
log "Creating SQLite database at $DB_PATH..."
mkdir -p /var/lib/novacpx
rm -f "$DB_PATH"
sqlite3 "$DB_PATH" < "$SCHEMA"
log "Schema applied"

if [[ "$SCHEMA_ONLY" == "false" && -n "$DB_NAME" ]]; then
  # ── Migrate data from MySQL → SQLite ─────────────────────────────────────────
  log "Migrating data from MySQL ($DB_NAME)..."

  TABLES=(
    users settings email_domains email_accounts dns_zones dns_records
    accounts packages features account_features
    ssl_certificates backups backup_schedules
    ftp_accounts cron_jobs databases db_users
    wordpress_installs
    resellers reseller_branding reseller_packages
    ip_addresses ssh_keys firewall_rules
    docker_containers docker_compose_stacks docker_quotas
    webmail_sso_tokens audit_log
  )

  for TABLE in "${TABLES[@]}"; do
    # Check table exists in MySQL
    ROWS=$(mysql -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" -se "SELECT COUNT(*) FROM \`$TABLE\`;" 2>/dev/null) || { warn "Table $TABLE not found in MySQL — skipping"; continue; }
    [[ "$ROWS" == "0" ]] && { log "  $TABLE — empty, skip"; continue; }

    # Get column names from MySQL for this table
    MYSQL_COLS=$(mysql -u "$DB_USER" -p"$DB_PASS" --skip-column-names --batch "$DB_NAME" \
      -e "SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='${DB_NAME}' AND TABLE_NAME='${TABLE}' ORDER BY ORDINAL_POSITION;" 2>/dev/null)
    [[ -z "$MYSQL_COLS" ]] && { warn "  $TABLE: could not get MySQL columns, skipping"; continue; }

    # Dump only the columns that exist in both MySQL and SQLite
    mysql -u "$DB_USER" -p"$DB_PASS" --skip-column-names --batch "$DB_NAME" \
      -e "SELECT $(echo "$MYSQL_COLS" | tr '\n' ',' | sed 's/,$//') FROM \`$TABLE\`" 2>/dev/null | \
    python3 - "$TABLE" "$DB_PATH" "$MYSQL_COLS" <<'PYEOF'
import sys, sqlite3, csv, io

table    = sys.argv[1]
db_path  = sys.argv[2]
mysql_cols = [c for c in sys.argv[3].splitlines() if c]
data     = sys.stdin.read()
if not data.strip():
    sys.exit(0)

con = sqlite3.connect(db_path)
cur = con.cursor()
cur.execute(f"SELECT name FROM pragma_table_info('{table}')")
sqlite_cols = {r[0] for r in cur.fetchall()}
if not sqlite_cols:
    print(f"  {table}: not in SQLite schema, skipping", file=sys.stderr)
    con.close()
    sys.exit(0)

# Only insert columns present in both
common = [c for c in mysql_cols if c in sqlite_cols]
col_idx = [i for i, c in enumerate(mysql_cols) if c in sqlite_cols]
placeholders = ','.join(['?'] * len(common))
col_list = ','.join(common)

reader = csv.reader(io.StringIO(data), delimiter='\t')
rows = list(reader)
inserted = 0
for row in rows:
    if len(row) != len(mysql_cols):
        continue
    vals = [None if row[i] == 'NULL' else row[i] for i in col_idx]
    try:
        cur.execute(f"INSERT OR IGNORE INTO {table} ({col_list}) VALUES ({placeholders})", vals)
        inserted += 1
    except Exception as e:
        pass
con.commit()
con.close()
print(f"  {table}: {inserted}/{len(rows)} rows migrated ({len(common)} cols)")
PYEOF
  done
  log "Data migration complete"
fi

# ── Update config.ini ─────────────────────────────────────────────────────────
log "Updating $CONFIG to use SQLite..."

# Build new [database] section
NEW_DB_SECTION="[database]
path     = ${DB_PATH}
wp_user  = ${DB_WP_USER}
wp_pass  = ${DB_WP_PASS}"

# Replace everything between [database] and the next [section]
python3 - "$CONFIG" "$NEW_DB_SECTION" <<'PYEOF'
import sys, re

config_path = sys.argv[1]
new_section = sys.argv[2]

with open(config_path) as f:
    content = f.read()

# Replace the [database] section with new content
content = re.sub(
    r'\[database\].*?(?=\[|\Z)',
    new_section + '\n\n',
    content,
    flags=re.DOTALL
)

with open(config_path, 'w') as f:
    f.write(content)

print("config.ini updated")
PYEOF

chown root:www-data "$CONFIG"
chmod 640 "$CONFIG"

# ── Fix permissions ───────────────────────────────────────────────────────────
chown www-data:www-data "$DB_PATH"
chmod 660 "$DB_PATH"

log "Migration complete!"
log "Panel DB: $DB_PATH"
log "Backup:   $BACKUP_DIR"
warn "Restart PHP-FPM to clear any cached DB connections:"
echo "  systemctl restart php8.3-fpm php8.2-fpm php8.1-fpm php7.4-fpm 2>/dev/null || true"
