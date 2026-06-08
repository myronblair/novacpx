#!/usr/bin/env bash
# nova-deploy.sh — Full panel sync to NovaCPX VM
# Usage: bash nova-deploy.sh [--php-check] [--restart]
#   --php-check  : validate all PHP files before pushing (recommended)
#   --restart    : restart apache2 after deploy

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(dirname "$SCRIPT_DIR")"
WEB_ROOT="/srv/novacpx/public"

PVE1_HOST="orbisne.fortiddns.com"
PVE1_PASS="Joker1974!!!"
VM_IP="10.48.200.110"
VM_PASS="Joker1974!!!"
SSH_OPTS="-o StrictHostKeyChecking=no -o ConnectTimeout=10"

PHP_CHECK=false
DO_RESTART=false

for arg in "$@"; do
  case "$arg" in
    --php-check) PHP_CHECK=true ;;
    --restart)   DO_RESTART=true ;;
  esac
done

# PHP syntax check before deploy
if $PHP_CHECK; then
  echo "Running PHP syntax check..."
  ERRORS=0
  while IFS= read -r -d '' f; do
    php8.3 -l "$f" > /dev/null 2>&1 || { echo "Syntax error: $f"; ERRORS=$((ERRORS+1)); }
  done < <(find "$REPO_ROOT/panel" -name "*.php" -print0)
  if [[ $ERRORS -gt 0 ]]; then
    echo "Aborting: $ERRORS PHP syntax error(s) found"
    exit 1
  fi
  echo "All PHP files OK"
fi

echo "Deploying panel files to VM..."

# Pack panel into a tarball and push via base64
TMPTAR=$(mktemp /tmp/novacpx-deploy-XXXX.tar.gz)
tar -czf "$TMPTAR" -C "$REPO_ROOT/panel" public api lib
CONTENT=$(base64 -w0 "$TMPTAR")
rm -f "$TMPTAR"

sshpass -p "$PVE1_PASS" ssh $SSH_OPTS root@$PVE1_HOST \
  "sshpass -p '$VM_PASS' ssh $SSH_OPTS root@$VM_IP \
    \"echo '$CONTENT' | base64 -d > /tmp/novacpx-deploy.tar.gz && \
      tar -xzf /tmp/novacpx-deploy.tar.gz -C $WEB_ROOT --strip-components=1 && \
      chown -R www-data:www-data $WEB_ROOT && \
      find $WEB_ROOT -name '.htaccess' -exec chmod 644 {} \\; && \
      rm /tmp/novacpx-deploy.tar.gz && \
      echo 'Deploy complete'\""

if $DO_RESTART; then
  echo "Restarting web server..."
  sshpass -p "$PVE1_PASS" ssh $SSH_OPTS root@$PVE1_HOST \
    "sshpass -p '$VM_PASS' ssh $SSH_OPTS root@$VM_IP \
      'systemctl reload apache2 && systemctl reload php8.3-fpm && echo Reloaded'"
fi

echo ""
echo "Deploy done. Panel: https://$VM_IP:8882"
