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

# Read DB path and channel once before processing the queue
DB_PATH=$(python3 -c "import configparser; c=configparser.ConfigParser(); c.read('/etc/novacpx/config.ini'); print(c.get('database','path',fallback='/var/lib/novacpx/panel.db'))" 2>/dev/null || echo "/var/lib/novacpx/panel.db")
CHANNEL=$(sqlite3 "$DB_PATH" "SELECT value FROM settings WHERE key='update_channel'" 2>/dev/null || echo "stable")
[[ "$CHANNEL" != "beta" ]] && CHANNEL="stable"

while IFS='|' read -r REPO_PATH WEB_ROOT COMMIT QUEUED_BRANCH; do
  [[ -z "$REPO_PATH" ]] && continue

  # Use branch recorded in queue entry (from webhook); fall back to DB channel
  TARGET_BRANCH="main"
  if [[ "$QUEUED_BRANCH" == "beta" || ("$QUEUED_BRANCH" != "main" && "$CHANNEL" == "beta") ]]; then
    TARGET_BRANCH="beta"
  fi

  log "--- Deploying commit $COMMIT (branch: $TARGET_BRANCH) ---"

  # Validate PHP syntax before applying
  cd "$REPO_PATH" || continue
  git fetch origin >> "$LOG" 2>&1

  # Check PHP syntax on changed .php files
  CHANGED_PHP=$(git diff HEAD..origin/${TARGET_BRANCH} --name-only 2>/dev/null | grep '\.php$' || true)
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

  # Pull from channel branch
  BEFORE=$(git rev-parse HEAD | tr -cd 'a-f0-9A-F')
  git pull origin "${TARGET_BRANCH}" >> "$LOG" 2>&1
  AFTER=$(git rev-parse HEAD | tr -cd 'a-f0-9A-F')

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

  # Sync backend API and lib (not in panel/public, deployed separately)
  rsync -av --delete --exclude='config.php' "$REPO_PATH/panel/api/" "$WEB_ROOT/api/" >> "$LOG" 2>&1
  rsync -av --delete "$REPO_PATH/panel/lib/" "$WEB_ROOT/lib/" >> "$LOG" 2>&1

  # Sync cron scripts to bin directory
  rsync -av "$REPO_PATH/panel/bin/" /opt/novacpx/bin/ >> "$LOG" 2>&1
  chmod +x /opt/novacpx/bin/*.php 2>/dev/null || true

  # Run pending DB migrations (SQLite)
  MIGR_DIR="$REPO_PATH/db/migrations"
  if [[ -d "$MIGR_DIR" && -f "$DB_PATH" ]]; then
    for SQL in "$MIGR_DIR"/*.sql; do
      [[ -f "$SQL" ]] || continue
      MIGR_NAME=$(basename "$SQL" .sql)
      ALREADY=$(sqlite3 "$DB_PATH" "SELECT value FROM settings WHERE key='migration_$MIGR_NAME'" 2>/dev/null)
      if [[ -z "$ALREADY" ]]; then
        log "Running migration: $MIGR_NAME"
        sqlite3 "$DB_PATH" < "$SQL" >> "$LOG" 2>&1
        sqlite3 "$DB_PATH" "INSERT OR REPLACE INTO settings (key,value,updated_at) VALUES ('migration_$MIGR_NAME','$(date)',datetime('now'))" 2>/dev/null
      fi
    done
  fi

  # Record new version in DB — sanitize values before interpolating into SQL
  NEW_VERSION=$(cat "$REPO_PATH/VERSION" 2>/dev/null | tr -d '[:space:]' | tr -cd 'a-zA-Z0-9.-' || true)
  if [[ -n "$NEW_VERSION" && -f "$DB_PATH" ]]; then
    sqlite3 "$DB_PATH" "INSERT INTO novacpx_version (version, installed_at, notes, git_commit) VALUES ('$NEW_VERSION', datetime('now'), 'Auto-deployed via webhook ($CHANNEL channel)', '$AFTER')" 2>/dev/null || true
    sqlite3 "$DB_PATH" "INSERT INTO settings (key, value, updated_at) VALUES ('panel_version', '$NEW_VERSION', datetime('now')) ON CONFLICT(key) DO UPDATE SET value=excluded.value, updated_at=excluded.updated_at" 2>/dev/null || true
    log "Version updated to $NEW_VERSION"
  fi

  # Ensure deploy webhook is accessible from web root
  mkdir -p "$WEB_ROOT/deploy"
  ln -sf "$REPO_PATH/deploy/webhook.php" "$WEB_ROOT/deploy/webhook.php"

  # Restart PHP-FPM to pick up code changes
  systemctl reload php8.3-fpm 2>/dev/null || true
  systemctl reload php8.2-fpm 2>/dev/null || true

  log "Deploy complete: $AFTER"

done < "$QUEUE"

# Clear queue
> "$QUEUE"
