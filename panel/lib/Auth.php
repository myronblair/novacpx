<?php
class Auth {
    private static ?Auth $instance = null;
    private ?array $user = null;

    private function __construct() {}

    public static function getInstance(): self {
        if (!self::$instance) self::$instance = new self();
        return self::$instance;
    }

    public function check(): bool {
        // Bearer token (API)
        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if (str_starts_with($header, 'Bearer ')) {
            $token = substr($header, 7);
            return $this->loginByToken($token);
        }
        // Session cookie
        $sessionId = $_COOKIE['ncpx_session'] ?? '';
        if ($sessionId) {
            return $this->loginBySession($sessionId);
        }
        return false;
    }

    private function loginBySession(string $sessionId): bool {
        $db  = DB::getInstance();
        $row = $db->fetchOne(
            "SELECT s.*, u.id as uid, u.username, u.email, u.role, u.status, u.reseller_id, u.theme
             FROM sessions s
             JOIN users u ON u.id = s.user_id
             WHERE s.id = ? AND s.expires_at > NOW() AND u.status = 'active'",
            [hash('sha256', $sessionId)]
        );
        if (!$row) return false;
        $this->user = $row;
        return true;
    }

    private function loginByToken(string $token): bool {
        $db  = DB::getInstance();
        $row = $db->fetchOne(
            "SELECT t.permissions, u.id as uid, u.username, u.email, u.role, u.status
             FROM api_tokens t
             JOIN users u ON u.id = t.user_id
             WHERE t.token = ? AND (t.expires_at IS NULL OR t.expires_at > NOW()) AND u.status = 'active'",
            [hash('sha256', $token)]
        );
        if (!$row) return false;
        $db->execute("UPDATE api_tokens SET last_used = NOW() WHERE token = ?", [hash('sha256', $token)]);
        $this->user = $row;
        return true;
    }

    public function attempt(string $username, string $password): ?string {
        $db  = DB::getInstance();
        $user = $db->fetchOne(
            "SELECT * FROM users WHERE (username = ? OR email = ?) AND status = 'active'",
            [$username, $username]
        );
        if (!$user || !password_verify($password, $user['password'])) return null;

        // Create session
        $token     = bin2hex(random_bytes(32));
        $sessionId = hash('sha256', $token);
        $db->execute(
            "INSERT INTO sessions (id, user_id, ip_address, user_agent, expires_at)
             VALUES (?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL 8 HOUR))",
            [$sessionId, $user['id'], $_SERVER['REMOTE_ADDR'] ?? '', $_SERVER['HTTP_USER_AGENT'] ?? '']
        );
        $db->execute("UPDATE users SET last_login = NOW() WHERE id = ?", [$user['id']]);

        setcookie('ncpx_session', $token, [
            'expires'  => time() + 28800,
            'path'     => '/',
            'secure'   => true,
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
        $this->user = $user;
        return $token;
    }

    public function logout(): void {
        $sessionId = hash('sha256', $_COOKIE['ncpx_session'] ?? '');
        DB::getInstance()->execute("DELETE FROM sessions WHERE id = ?", [$sessionId]);
        setcookie('ncpx_session', '', time() - 3600, '/', '', true, true);
    }

    public function user(): ?array { return $this->user; }

    public function require(string ...$roles): void {
        $user = $this->user();
        if (!$user || !in_array($user['role'], $roles)) {
            Response::error('Forbidden', 403);
        }
    }
}
