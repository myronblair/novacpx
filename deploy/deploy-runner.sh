#!/usr/bin/env bash
# NovaCPX Deploy Runner — runs every minute via cron
# Processes /tmp/novacpx-deploy-queue.txt
# Each line: repo_path|web_root|commit

QUEUE="/tmp/novacpx-deploy-queue.txt"
LOG="/var/log/novacpx/deploy.log"
LOCK="/tmp/novacpx-deploy.lock"

log() { echo "[$(date '+%Y-%m-%d %H:%M:%S')] $*" >> "$LOG"; }

[[ ! -s "$QUEUE" ]] && exit 0

# Prevent concurrent runs
exec 9>"$LOCK"
flock -n 9 || { log "Deploy already running, skipping"; exit 0; }

while IFS='|' read -r REPO_PATH WEB_ROOT COMMIT; do
  [[ -z "$REPO_PATH" ]] && continue
  log "--- Deploying commit $COMMIT ---"

  # Validate PHP syntax before applying
  cd "$REPO_PATH" || continue
  git fetch origin >> "$LOG" 2>&1

  # Check PHP syntax on changed .php files
  CHANGED_PHP=$(git diff HEAD..origin/main --name-only 2>/dev/null | grep '\.php$' || true)
  SYNTAX_OK=true
  for f in $CHANGED_PHP; do
    [[ -f "$REPO_PATH/$f" ]] || continue
    if ! php8.3 -l "$REPO_PATH/$f" >> "$LOG" 2>&1; then
      log "SYNTAX ERROR in $f — aborting deploy"
      SYNTAX_OK=false
      break
    fi
  done

  if ! $SYNTAX_OK; then
    log "Deploy aborted due to PHP syntax errors"
    continue
  fi

  # Pull
  BEFORE=$(git rev-parse HEAD)
  git pull origin main >> "$LOG" 2>&1
  AFTER=$(git rev-parse HEAD)

  if [[ "$BEFORE" == "$AFTER" ]]; then
    log "Nothing new to deploy (already at $AFTER)"
    continue
  fi

  log "Updated: $BEFORE → $AFTER"

  # Sync panel files to web root
  rsync -av --delete \
    --exclude='.git' \
    --exclude='api/config.php' \
    --exclude='*.log' \
    "$REPO_PATH/panel/public/" "$WEB_ROOT/" >> "$LOG" 2>&1

  # Run pending DB migrations
  MIGR_DIR="$REPO_PATH/db/migrations"
  if [[ -d "$MIGR_DIR" ]]; then
    DB_NAME=$(python3 -c "import configparser; c=configparser.ConfigParser(); c.read('/etc/novacpx/config.ini'); print(c['database']['name'])" 2>/dev/null)
    DB_USER=$(python3 -c "import configparser; c=configparser.ConfigParser(); c.read('/etc/novacpx/config.ini'); print(c['database']['user'])" 2>/dev/null)
    DB_PASS=$(python3 -c "import configparser; c=configparser.ConfigParser(); c.read('/etc/novacpx/config.ini'); print(c['database']['pass'])" 2>/dev/null)
    for SQL in "$MIGR_DIR"/*.sql; do
      [[ -f "$SQL" ]] || continue
      MIGR_NAME=$(basename "$SQL" .sql)
      ALREADY=$(mysql -u"$DB_USER" -p"$DB_PASS" "$DB_NAME" -se "SELECT value FROM settings WHERE \`key\`='migration_$MIGR_NAME'" 2>/dev/null)
      if [[ -z "$ALREADY" ]]; then
        log "Running migration: $MIGR_NAME"
        mysql -u"$DB_USER" -p"$DB_PASS" "$DB_NAME" < "$SQL" >> "$LOG" 2>&1
        mysql -u"$DB_USER" -p"$DB_PASS" "$DB_NAME" -e "INSERT INTO settings (\`key\`,\`value\`) VALUES ('migration_$MIGR_NAME','$(date)') ON DUPLICATE KEY UPDATE \`value\`='$(date)'" 2>/dev/null
      fi
    done
  fi

  # Update VERSION
  git describe --tags --abbrev=0 2>/dev/null > "$REPO_PATH/VERSION" || git rev-parse --short HEAD > "$REPO_PATH/VERSION"

  # Restart PHP-FPM to pick up code changes
  systemctl reload php8.3-fpm 2>/dev/null || true
  systemctl reload php8.2-fpm 2>/dev/null || true

  log "Deploy complete: $AFTER"

done < "$QUEUE"

# Clear queue
> "$QUEUE"
