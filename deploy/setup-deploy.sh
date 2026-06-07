#!/usr/bin/env bash
# Run once after install to configure the auto-deploy system
# Usage: bash setup-deploy.sh <github_webhook_secret>
set -euo pipefail

SECRET="${1:-$(openssl rand -hex 16)}"
REPO_PATH="/opt/novacpx-src"
WEB_ROOT="/srv/novacpx/public"

# Add deploy config to /etc/novacpx/config.ini
python3 - <<PYEOF
import configparser, os
cfg = configparser.ConfigParser()
cfg.read('/etc/novacpx/config.ini')
if 'deploy' not in cfg: cfg['deploy'] = {}
cfg['deploy']['webhook_secret'] = '$SECRET'
cfg['deploy']['repo_path']      = '$REPO_PATH'
cfg['deploy']['web_root']       = '$WEB_ROOT'
cfg['deploy']['branch']         = 'main'
with open('/etc/novacpx/config.ini', 'w') as f: cfg.write(f)
print('Config updated')
PYEOF

# Install deploy runner
cp "$REPO_PATH/deploy/deploy-runner.sh" /usr/local/bin/novacpx-deploy
chmod +x /usr/local/bin/novacpx-deploy

# Install webhook handler into web root
mkdir -p "$WEB_ROOT/deploy"
cp "$REPO_PATH/deploy/webhook.php" "$WEB_ROOT/deploy/webhook.php"
chown www-data:www-data "$WEB_ROOT/deploy/webhook.php"

# Add cron job (every minute)
(crontab -l 2>/dev/null | grep -v novacpx-deploy; echo "* * * * * root /usr/local/bin/novacpx-deploy >> /var/log/novacpx/deploy.log 2>&1") | crontab -

echo ""
echo "Auto-deploy configured!"
echo "Webhook URL:    https://$(hostname -I | awk '{print $1}'):2083/deploy/webhook.php"
echo "Webhook Secret: $SECRET"
echo ""
echo "Add this webhook to GitHub repo settings:"
echo "  Repo: https://github.com/myronblair/novacpx"
echo "  URL:  https://$(hostname -I | awk '{print $1}'):2083/deploy/webhook.php"
echo "  Content-Type: application/json"
echo "  Secret: $SECRET"
echo "  Events: Push"
