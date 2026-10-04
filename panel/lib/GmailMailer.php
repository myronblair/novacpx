<?php
/**
 * Gmail API mail transport for panel notifications (users.messages.send over HTTPS, OAuth2 refresh token).
 * Replaces the CyberMail HTTP API. Settings (table `settings`): gmail_sender, gmail_client_id,
 * gmail_client_secret, gmail_refresh_token. Outbound SMTP is blocked on most VPS hosts; HTTPS is not.
 */
class GmailMailer {

    private static function setting(string $key): string {
        $row = DB::getInstance()->fetchOne("SELECT `value` FROM settings WHERE `key` = ?", [$key]);
        return trim($row['value'] ?? '');
    }

    public static function configured(): bool {
        return self::setting('gmail_sender') !== '' && self::setting('gmail_client_id') !== ''
            && self::setting('gmail_client_secret') !== '' && self::setting('gmail_refresh_token') !== '';
    }

    private static function accessToken(string &$err): ?string {
        $cache = sys_get_temp_dir() . '/novacpx_gmail_token_' . substr(sha1(self::setting('gmail_refresh_token')), 0, 12) . '.json';
        if (is_file($cache)) {
            $c = json_decode((string)@file_get_contents($cache), true);
            if (!empty($c['access_token']) && ($c['expires_at'] ?? 0) > time() + 60) return $c['access_token'];
        }
        $ch = curl_init('https://oauth2.googleapis.com/token');
        curl_setopt_array($ch, [
            CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15,
            CURLOPT_POSTFIELDS => http_build_query([
                'client_id' => self::setting('gmail_client_id'), 'client_secret' => self::setting('gmail_client_secret'),
                'refresh_token' => self::setting('gmail_refresh_token'), 'grant_type' => 'refresh_token',
            ]),
        ]);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $d = json_decode((string)$resp, true);
        if ($code !== 200 || empty($d['access_token'])) {
            $err = 'Gmail token refresh failed (HTTP ' . $code . '): ' . substr((string)$resp, 0, 160);
            return null;
        }
        @file_put_contents($cache, json_encode(['access_token' => $d['access_token'], 'expires_at' => time() + (int)($d['expires_in'] ?? 3600)]));
        @chmod($cache, 0600);
        return $d['access_token'];
    }

    /** @return array{ok:bool,error:string} */
    public static function send(string $to, string $subject, string $html, ?string $text = null): array {
        if (!self::configured()) return ['ok' => false, 'error' => 'Gmail is not configured (Settings > Email Notifications)'];
        $err = '';
        $token = self::accessToken($err);
        if (!$token) return ['ok' => false, 'error' => $err];

        $sender   = self::setting('gmail_sender');
        $fromName = self::setting('notify_from_name') ?: 'NovaCPX Panel';
        $replyTo  = self::setting('notify_from_email');
        $text     = $text ?? strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", $html));
        $b        = 'nova_' . bin2hex(random_bytes(8));
        $enc      = fn(string $s) => '=?UTF-8?B?' . base64_encode($s) . '?=';

        $h = "From: " . $enc($fromName) . " <{$sender}>\r\nTo: {$to}\r\n";
        if ($replyTo && strcasecmp($replyTo, $sender) !== 0 && filter_var($replyTo, FILTER_VALIDATE_EMAIL)) {
            $h .= "Reply-To: {$replyTo}\r\n";
        }
        $h .= "Subject: " . $enc($subject) . "\r\nMIME-Version: 1.0\r\nContent-Type: multipart/alternative; boundary=\"{$b}\"\r\n\r\n";
        $body = "--{$b}\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode($text))
              . "--{$b}\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode($html))
              . "--{$b}--";
        $raw = rtrim(strtr(base64_encode($h . $body), '+/', '-_'), '=');

        $ch = curl_init('https://gmail.googleapis.com/gmail/v1/users/me/messages/send');
        curl_setopt_array($ch, [
            CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_POSTFIELDS => json_encode(['raw' => $raw]),
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token, 'Content-Type: application/json'],
        ]);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $ce   = curl_error($ch);
        curl_close($ch);
        if ($ce) return ['ok' => false, 'error' => 'cURL: ' . $ce];
        if ($code >= 200 && $code < 300) return ['ok' => true, 'error' => ''];
        return ['ok' => false, 'error' => 'Gmail API HTTP ' . $code . ': ' . substr((string)$resp, 0, 200)];
    }
}
