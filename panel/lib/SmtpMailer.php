<?php
// Copyright (c) 2026 TomTom Enterprises. Licensed under the MIT License (see LICENSE).
/**
 * Plain SMTP transport for panel notifications, for anyone who does not want to use the Gmail API.
 * Settings (table `settings`): smtp_host, smtp_port, smtp_security (none|starttls|ssl), smtp_user, smtp_pass,
 * plus notify_from_email / notify_from_name shared with the Gmail transport.
 */
class SmtpMailer {

    private static function setting(string $key): string {
        $row = DB::getInstance()->fetchOne("SELECT `value` FROM settings WHERE `key` = ?", [$key]);
        return trim($row['value'] ?? '');
    }

    public static function configured(): bool {
        return self::setting('smtp_host') !== '' && self::fromAddress() !== '';
    }

    /** The envelope/header sender: the From Email setting, else the SMTP login when that is an address. */
    private static function fromAddress(): string {
        $from = self::setting('notify_from_email');
        if (filter_var($from, FILTER_VALIDATE_EMAIL)) return $from;
        $user = self::setting('smtp_user');
        return filter_var($user, FILTER_VALIDATE_EMAIL) ? $user : '';
    }

    /** Read one (possibly multi-line) SMTP reply. @return array{0:int,1:string} */
    private static function reply($fp): array {
        $text = ''; $code = 0;
        while (($line = fgets($fp, 1024)) !== false) {
            $text .= $line;
            $code = (int)substr($line, 0, 3);
            if (strlen($line) < 4 || $line[3] !== '-') break;
        }
        return [$code, trim($text)];
    }

    private static function cmd($fp, string $line, array $ok): array {
        fwrite($fp, $line . "\r\n");
        [$code, $text] = self::reply($fp);
        if (!in_array($code, $ok, true)) throw new RuntimeException("SMTP server said: {$text}");
        return [$code, $text];
    }

    /** @return array{ok:bool,error:string} */
    public static function send(string $to, string $subject, string $html, ?string $text = null): array {
        if (!self::configured()) return ['ok' => false, 'error' => 'SMTP is not configured (Settings > Email Notifications)'];
        $host = self::setting('smtp_host');
        $sec  = in_array(self::setting('smtp_security'), ['none', 'starttls', 'ssl'], true) ? self::setting('smtp_security') : 'starttls';
        $port = (int)self::setting('smtp_port') ?: ($sec === 'ssl' ? 465 : ($sec === 'none' ? 25 : 587));
        $user = self::setting('smtp_user');
        $pass = self::setting('smtp_pass');
        $from = self::fromAddress();
        $fromName = self::setting('notify_from_name') ?: 'NovaCPX Panel';
        $replyTo  = self::setting('notify_reply_to');
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) return ['ok' => false, 'error' => 'Invalid recipient address'];

        $fp = null;
        try {
            $ctx = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'SNI_enabled' => true, 'peer_name' => $host]]);
            $fp = @stream_socket_client(($sec === 'ssl' ? 'ssl://' : 'tcp://') . $host . ':' . $port, $errno, $errstr, 15, STREAM_CLIENT_CONNECT, $ctx);
            if (!$fp) throw new RuntimeException("Cannot connect to {$host}:{$port} ({$errstr})");
            stream_set_timeout($fp, 20);
            [$c, $t] = self::reply($fp);
            if ($c !== 220) throw new RuntimeException("SMTP server said: {$t}");
            $ehloName = preg_replace('/[^A-Za-z0-9.-]/', '', gethostname() ?: 'localhost') ?: 'localhost';
            self::cmd($fp, "EHLO {$ehloName}", [250]);
            if ($sec === 'starttls') {
                self::cmd($fp, 'STARTTLS', [220]);
                if (!@stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) throw new RuntimeException('TLS handshake failed (check the host name and port)');
                self::cmd($fp, "EHLO {$ehloName}", [250]);
            }
            if ($user !== '') {
                [, $caps] = self::cmd($fp, "EHLO {$ehloName}", [250]);
                if (stripos($caps, 'AUTH') !== false && stripos($caps, 'PLAIN') === false && stripos($caps, 'LOGIN') !== false) {
                    self::cmd($fp, 'AUTH LOGIN', [334]);
                    self::cmd($fp, base64_encode($user), [334]);
                    self::cmd($fp, base64_encode($pass), [235]);
                } else {
                    self::cmd($fp, 'AUTH PLAIN ' . base64_encode("\0{$user}\0{$pass}"), [235]);
                }
            }
            self::cmd($fp, "MAIL FROM:<{$from}>", [250]);
            self::cmd($fp, "RCPT TO:<{$to}>", [250, 251]);
            self::cmd($fp, 'DATA', [354]);

            $text = $text ?? strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", $html));
            $b    = 'nova_' . bin2hex(random_bytes(8));
            $enc  = fn(string $s) => '=?UTF-8?B?' . base64_encode($s) . '?=';
            $dom  = substr(strrchr($from, '@'), 1);
            $h  = "Date: " . date('r') . "\r\nMessage-ID: <" . bin2hex(random_bytes(12)) . "@{$dom}>\r\n";
            $h .= "From: " . $enc($fromName) . " <{$from}>\r\nTo: {$to}\r\n";
            if ($replyTo && filter_var($replyTo, FILTER_VALIDATE_EMAIL)) $h .= "Reply-To: {$replyTo}\r\n";
            $h .= "Subject: " . $enc($subject) . "\r\nMIME-Version: 1.0\r\nContent-Type: multipart/alternative; boundary=\"{$b}\"\r\n\r\n";
            $msg = $h
                 . "--{$b}\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode($text))
                 . "--{$b}\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode($html))
                 . "--{$b}--\r\n";
            // dot-stuff and terminate
            $msg = preg_replace('/^\./m', '..', str_replace("\r\n", "\n", $msg));
            fwrite($fp, str_replace("\n", "\r\n", $msg) . ".\r\n");
            [$c, $t] = self::reply($fp);
            if ($c !== 250) throw new RuntimeException("SMTP server said: {$t}");
            @fwrite($fp, "QUIT\r\n");
            fclose($fp);
            return ['ok' => true, 'error' => ''];
        } catch (Throwable $e) {
            if (is_resource($fp)) { @fwrite($fp, "QUIT\r\n"); @fclose($fp); }
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }
}
