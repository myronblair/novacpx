<?php
/**
 * Email notification dispatcher via CyberMail API
 */
class Notifier {

    private static function getSetting(string $key): string {
        $db  = DB::getInstance();
        $row = $db->fetchOne("SELECT `value` FROM settings WHERE `key` = ?", [$key]);
        return $row['value'] ?? '';
    }

    private static function send(string $to, string $subject, string $html): bool {
        $apiKey  = self::getSetting('cybermail_api_key');
        $fromEmail = self::getSetting('notify_from_email') ?: 'noreply@novacpx.local';
        $fromName  = self::getSetting('notify_from_name')  ?: 'NovaCPX Panel';

        if (!$apiKey || !$to) return false;

        $payload = json_encode([
            'from'    => "$fromName <$fromEmail>",
            'to'      => $to,
            'subject' => $subject,
            'html'    => $html,
            'text'    => strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", $html)),
        ]);

        $ch = curl_init('https://platform.cyberpersons.com/email/v1/send');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $apiKey,
                'Content-Type: application/json',
            ],
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return $code === 202;
    }

    private static function adminEmail(): string {
        return self::getSetting('notify_admin_email');
    }

    private static function notificationsEnabled(): bool {
        return self::getSetting('notifications_enabled') !== '0';
    }

    // ── Triggers ──────────────────────────────────────────────────────────────

    public static function accountCreated(array $account, string $password): void {
        if (!self::notificationsEnabled()) return;

        $user   = $account['username'];
        $domain = $account['domain'];
        $email  = $account['email'] ?? '';
        $panel  = 'https://' . ($_SERVER['HTTP_HOST'] ?? 'your-panel');

        // Notify the new user
        if ($email) {
            self::send($email,
                "Welcome to NovaCPX — your account is ready",
                "<h2>Welcome, {$user}!</h2>
                <p>Your hosting account has been created.</p>
                <table style='font-family:monospace;border-collapse:collapse'>
                  <tr><td style='padding:4px 12px 4px 0'><strong>Domain:</strong></td><td>{$domain}</td></tr>
                  <tr><td style='padding:4px 12px 4px 0'><strong>Username:</strong></td><td>{$user}</td></tr>
                  <tr><td style='padding:4px 12px 4px 0'><strong>Password:</strong></td><td>{$password}</td></tr>
                  <tr><td style='padding:4px 12px 4px 0'><strong>Panel:</strong></td><td><a href='{$panel}'>{$panel}</a></td></tr>
                </table>
                <p>Please change your password after first login.</p>"
            );
        }

        // Notify admin
        $adminEmail = self::adminEmail();
        if ($adminEmail) {
            self::send($adminEmail,
                "NovaCPX: New account created — {$domain}",
                "<p>A new hosting account was created:</p>
                <table style='font-family:monospace;border-collapse:collapse'>
                  <tr><td style='padding:4px 12px 4px 0'><strong>Domain:</strong></td><td>{$domain}</td></tr>
                  <tr><td style='padding:4px 12px 4px 0'><strong>Username:</strong></td><td>{$user}</td></tr>
                  <tr><td style='padding:4px 12px 4px 0'><strong>Email:</strong></td><td>{$email}</td></tr>
                </table>"
            );
        }
    }

    public static function accountSuspended(array $account, string $reason = ''): void {
        if (!self::notificationsEnabled()) return;

        $user   = $account['username'] ?? 'unknown';
        $domain = $account['domain']   ?? 'unknown';
        $email  = $account['email']    ?? '';
        $why    = $reason ?: 'No reason provided';

        // Notify account holder
        if ($email) {
            self::send($email,
                "Your hosting account has been suspended",
                "<h2>Account Suspended</h2>
                <p>Your hosting account <strong>{$domain}</strong> has been suspended.</p>
                <p><strong>Reason:</strong> {$why}</p>
                <p>Please contact support to resolve this issue.</p>"
            );
        }

        // Notify admin
        $adminEmail = self::adminEmail();
        if ($adminEmail) {
            self::send($adminEmail,
                "NovaCPX: Account suspended — {$domain}",
                "<p>Account <strong>{$domain}</strong> (user: {$user}) was suspended.</p>
                <p><strong>Reason:</strong> {$why}</p>"
            );
        }
    }

    public static function diskQuotaWarning(array $account, int $usedMb, int $limitMb): void {
        if (!self::notificationsEnabled()) return;

        $pct    = $limitMb > 0 ? round($usedMb / $limitMb * 100) : 0;
        $domain = $account['domain']   ?? 'unknown';
        $email  = $account['email']    ?? '';

        if ($email) {
            self::send($email,
                "Disk quota warning — {$domain} is at {$pct}%",
                "<h2>Disk Quota Warning</h2>
                <p>Your hosting account <strong>{$domain}</strong> has used <strong>{$pct}%</strong> of its disk quota.</p>
                <p>Usage: {$usedMb} MB of {$limitMb} MB</p>
                <p>Please free up space or contact support to upgrade your plan.</p>"
            );
        }

        $adminEmail = self::adminEmail();
        if ($adminEmail) {
            self::send($adminEmail,
                "NovaCPX: Disk quota warning — {$domain} at {$pct}%",
                "<p>Account <strong>{$domain}</strong> is at <strong>{$pct}%</strong> disk usage ({$usedMb} MB / {$limitMb} MB).</p>"
            );
        }
    }

    public static function sslExpiring(string $domain, string $email, int $daysLeft): void {
        if (!self::notificationsEnabled()) return;

        if ($email) {
            self::send($email,
                "SSL certificate expiring in {$daysLeft} days — {$domain}",
                "<h2>SSL Certificate Expiry Notice</h2>
                <p>The SSL certificate for <strong>{$domain}</strong> will expire in <strong>{$daysLeft} days</strong>.</p>
                <p>Please renew your certificate to avoid service interruption.</p>"
            );
        }

        $adminEmail = self::adminEmail();
        if ($adminEmail) {
            self::send($adminEmail,
                "NovaCPX: SSL expiring in {$daysLeft} days — {$domain}",
                "<p>SSL for <strong>{$domain}</strong> expires in <strong>{$daysLeft} days</strong>. Account email: {$email}</p>"
            );
        }
    }
}
