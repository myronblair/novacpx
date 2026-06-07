<?php
class TOTP {
    private const CHARS  = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    private const PERIOD = 30;
    private const DIGITS = 6;
    private const WINDOW = 1;

    public static function generateSecret(int $length = 32): string {
        $secret = '';
        $bytes  = random_bytes($length);
        for ($i = 0; $i < $length; $i++) {
            $secret .= self::CHARS[ord($bytes[$i]) & 31];
        }
        return $secret;
    }

    public static function generateCode(string $secret, ?int $timestamp = null): string {
        $counter = intdiv($timestamp ?? time(), self::PERIOD);
        $key     = self::base32Decode($secret);
        $msg     = pack('J', $counter);
        $hash    = hash_hmac('sha1', $msg, $key, true);
        $offset  = ord($hash[19]) & 0x0F;
        $code    = ((ord($hash[$offset])   & 0x7F) << 24)
                 | ((ord($hash[$offset+1]) & 0xFF) << 16)
                 | ((ord($hash[$offset+2]) & 0xFF) << 8)
                 |  (ord($hash[$offset+3]) & 0xFF);
        return str_pad($code % (10 ** self::DIGITS), self::DIGITS, '0', STR_PAD_LEFT);
    }

    public static function verify(string $secret, string $code): bool {
        $time = time();
        for ($i = -self::WINDOW; $i <= self::WINDOW; $i++) {
            if (hash_equals(self::generateCode($secret, $time + $i * self::PERIOD), $code)) {
                return true;
            }
        }
        return false;
    }

    public static function qrUrl(string $secret, string $username, string $issuer = 'NovaCPX'): string {
        $label   = rawurlencode("{$issuer}:{$username}");
        $otpauth = "otpauth://totp/{$label}?secret={$secret}&issuer=" . rawurlencode($issuer) . "&algorithm=SHA1&digits=6&period=30";
        return "https://api.qrserver.com/v1/create-qr-code/?size=220x220&data=" . rawurlencode($otpauth);
    }

    public static function generateBackupCodes(int $count = 8): array {
        $codes = [];
        for ($i = 0; $i < $count; $i++) {
            $raw      = bin2hex(random_bytes(4));
            $codes[]  = strtoupper(substr($raw, 0, 4) . '-' . substr($raw, 4, 4));
        }
        return $codes;
    }

    public static function hashBackupCodes(array $codes): string {
        return json_encode(array_map(fn($c) => password_hash($c, PASSWORD_BCRYPT), $codes));
    }

    public static function verifyBackupCode(string $code, string $hashedJson): bool {
        $hashes = json_decode($hashedJson, true) ?? [];
        foreach ($hashes as $hash) {
            if (password_verify(strtoupper($code), $hash)) return true;
        }
        return false;
    }

    private static function base32Decode(string $base32): string {
        $base32 = strtoupper(preg_replace('/[^A-Z2-7]/', '', $base32));
        $buf = 0; $bits = 0; $out = '';
        for ($i = 0; $i < strlen($base32); $i++) {
            $val  = strpos(self::CHARS, $base32[$i]);
            $buf  = ($buf << 5) | $val;
            $bits += 5;
            if ($bits >= 8) { $bits -= 8; $out .= chr(($buf >> $bits) & 0xFF); }
        }
        return $out;
    }
}
