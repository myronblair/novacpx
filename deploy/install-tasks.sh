#!/bin/bash
# Copyright (c) 2026 TomTom Enterprises. Licensed under the MIT License (see LICENSE).
# Install the panel's background-task cron entries (/etc/cron.d/novacpx-tasks). Run as root; safe to re-run.
#
# Older installs put the stats and notification jobs in /etc/cron.d/novacpx pointing at /srv/novacpx/public/bin/, a folder that
# does not exist (the scripts live in /opt/novacpx/bin), so those jobs never ran. The lines are moved here with the right path.
set -euo pipefail
[ "$(id -u)" = 0 ] || { echo "run as root" >&2; exit 1; }
PHP_BIN="$(command -v php8.3 || command -v php)"
BIN=/opt/novacpx/bin
install -d -m 0755 -o www-data -g www-data /var/log/novacpx
cat > /etc/cron.d/novacpx-tasks <<CRON
# NovaCPX background tasks (managed by deploy/install-tasks.sh)
*/5  * * * * www-data ${PHP_BIN} ${BIN}/collect-stats.php   >> /var/log/novacpx/cron.log 2>&1
*/5  * * * * www-data ${PHP_BIN} ${BIN}/run-tasks.php       >> /var/log/novacpx/cron.log 2>&1
*/15 * * * * www-data ${PHP_BIN} ${BIN}/run-schedules.php   >> /var/log/novacpx/cron.log 2>&1
0    0 * * * www-data ${PHP_BIN} ${BIN}/notify-checks.php   >> /var/log/novacpx/cron.log 2>&1
CRON
chmod 644 /etc/cron.d/novacpx-tasks
if [ -f /etc/cron.d/novacpx ]; then
  sed -i '/collect-stats.php/d; /notify-checks.php/d' /etc/cron.d/novacpx
fi
echo "background tasks installed (/etc/cron.d/novacpx-tasks)"
