<?php
// Copyright (c) 2026 TomTom Enterprises. Licensed under the MIT License (see LICENSE).
/**
 * Picks the mail transport for panel notifications: the Gmail API or a plain SMTP server (settings.mail_transport = gmail|smtp).
 * With nothing chosen, whichever of the two is configured is used (Gmail first), so existing installs keep working.
 */
require_once __DIR__ . '/GmailMailer.php';
require_once __DIR__ . '/SmtpMailer.php';

class Mailer {

    public static function transport(): string {
        $row = DB::getInstance()->fetchOne("SELECT `value` FROM settings WHERE `key` = 'mail_transport'");
        $t = trim($row['value'] ?? '');
        if ($t === 'smtp' || $t === 'gmail') return $t;
        return (!GmailMailer::configured() && SmtpMailer::configured()) ? 'smtp' : 'gmail';
    }

    public static function configured(): bool {
        return self::transport() === 'smtp' ? SmtpMailer::configured() : GmailMailer::configured();
    }

    /** @return array{ok:bool,error:string} */
    public static function send(string $to, string $subject, string $html, ?string $text = null): array {
        return self::transport() === 'smtp'
            ? SmtpMailer::send($to, $subject, $html, $text)
            : GmailMailer::send($to, $subject, $html, $text);
    }
}
