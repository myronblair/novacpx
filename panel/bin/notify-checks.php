<?php
/**
 * Cron: disk quota warnings + SSL expiry notifications
 * Cron: 0 6 * * * root /usr/bin/php /opt/novacpx/bin/notify-checks.php >> /var/log/novacpx/notify-checks.log 2>&1
 */
define('NOVACPX_ROOT', '/srv/novacpx/public');
define('NOVACPX_LIB',  NOVACPX_ROOT . '/lib');

require NOVACPX_ROOT . '/lib/Core.php';
require NOVACPX_ROOT . '/lib/DB.php';
require_once NOVACPX_LIB . '/AccountManager.php';
require_once NOVACPX_LIB . '/Notifier.php';

$db = DB::getInstance();

// ── Disk quota warnings (>85% used) ──────────────────────────────────────────
$accounts = $db->fetchAll(
    "SELECT a.id, a.username, a.domain, a.home_dir, u.email,
            p.disk_mb AS limit_mb
     FROM accounts a
     JOIN users u ON u.id = a.user_id
     LEFT JOIN packages p ON p.id = a.package_id
     WHERE a.status = 'active' AND p.disk_mb > 0"
);

foreach ($accounts as $acct) {
    $usedMb  = AccountManager::getDiskUsage($acct['home_dir']);
    $limitMb = (int)$acct['limit_mb'];
    if ($limitMb <= 0) continue;
    $pct = $usedMb / $limitMb * 100;
    if ($pct >= 85) {
        // Only send once per day — check flag in settings
        $flagKey = 'quota_warned_' . $acct['id'];
        $lastWarn = $db->fetchOne("SELECT `value` FROM settings WHERE `key` = ?", [$flagKey]);
        $today = date('Y-m-d');
        if (($lastWarn['value'] ?? '') !== $today) {
            Notifier::diskQuotaWarning($acct, $usedMb, $limitMb);
            $db->execute(
                "INSERT INTO settings (`key`,`value`) VALUES (?,?) ON DUPLICATE KEY UPDATE `value`=VALUES(`value`)",
                [$flagKey, $today]
            );
        }
    }
}

// ── SSL expiry warnings (<=14 days) ──────────────────────────────────────────
$sslCerts = $db->fetchAll(
    "SELECT s.domain, s.expires_at, u.email
     FROM ssl_certs s
     JOIN accounts a ON a.id = s.account_id
     JOIN users u ON u.id = a.user_id
     WHERE s.status = 'active' AND s.expires_at IS NOT NULL
       AND s.expires_at <= DATE_ADD(NOW(), INTERVAL 14 DAY)
       AND s.expires_at > NOW()"
);

foreach ($sslCerts as $cert) {
    $daysLeft = (int)ceil((strtotime($cert['expires_at']) - time()) / 86400);
    $flagKey  = 'ssl_warned_' . md5($cert['domain']) . '_' . $daysLeft;
    $lastWarn = $db->fetchOne("SELECT `value` FROM settings WHERE `key` = ?", [$flagKey]);
    if (!$lastWarn) {
        Notifier::sslExpiring($cert['domain'], $cert['email'], $daysLeft);
        $db->execute(
            "INSERT INTO settings (`key`,`value`) VALUES (?,NOW()) ON DUPLICATE KEY UPDATE `value`=NOW()",
            [$flagKey]
        );
    }
}

echo "[" . date('Y-m-d H:i:s') . "] notify-checks complete\n";
