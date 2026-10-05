#!/bin/bash
# Install the NovaCPX auto-deploy poller (/usr/local/bin/novacpx-poll-deploy + /etc/cron.d/novacpx-autodeploy). Run as root; safe to re-run.
set -euo pipefail
[ "$(id -u)" = 0 ] || { echo "run as root" >&2; exit 1; }
SRC="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
bash -n "$SRC/novacpx-poll-deploy"
install -o root -g root -m 0755 "$SRC/novacpx-poll-deploy" /usr/local/bin/novacpx-poll-deploy
install -d -m 0755 /var/log/novacpx
# Older setups ran `novacpx-deploy` (a copy of the runner) and a queue-only `novacpx-poll-deploy` from root's crontab;
# the cron.d entry below replaces both, so drop those lines instead of running everything twice.
if crontab -l >/dev/null 2>&1; then
  crontab -l | grep -v -e 'novacpx-poll-deploy' -e '/usr/local/bin/novacpx-deploy' | crontab - || true
fi
cat > /etc/cron.d/novacpx-autodeploy <<'CRON'
# NovaCPX auto-deploy (managed by deploy/install-autodeploy.sh)
*/10 * * * * root /usr/local/bin/novacpx-poll-deploy >> /var/log/novacpx/autodeploy.log 2>&1
CRON
chmod 644 /etc/cron.d/novacpx-autodeploy
echo "auto-deploy installed (polls origin/main of /opt/novacpx-src every 10 minutes)"
