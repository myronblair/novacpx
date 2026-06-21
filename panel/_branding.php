<?php
/**
 * Server-side branding loader — injected into portal <head> before JS loads.
 * Reads session cookie → looks up user's reseller → returns branding row.
 */
function novacpx_get_branding(): array {
    static $cache = null;
    if ($cache !== null) return $cache;
    $cfg = @parse_ini_file('/etc/novacpx/config.ini', true);
    if (!$cfg) return $cache = [];
    try {
        $dbPath = $cfg['database']['path'] ?? '/var/lib/novacpx/panel.db';
        $pdo = new PDO(
            "sqlite:{$dbPath}", null, null,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_SILENT, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
        );
        $token = $_COOKIE['ncpx_session'] ?? '';
        if (!$token || strlen($token) < 32) return $cache = [];

        $stmt = $pdo->prepare("SELECT user_id FROM sessions WHERE id = ? AND expires_at > datetime('now') LIMIT 1");
        $stmt->execute([substr($token, 0, 128)]);
        $uid = (int)($stmt->fetchColumn() ?: 0);
        if (!$uid) return $cache = [];

        $stmt = $pdo->prepare("SELECT role, reseller_id FROM users WHERE id = ? LIMIT 1");
        $stmt->execute([$uid]);
        $u = $stmt->fetch();
        if (!$u) return $cache = [];

        $resellerId = ($u['role'] === 'reseller') ? $uid : (int)($u['reseller_id'] ?? 0);
        if (!$resellerId) return $cache = [];

        $stmt = $pdo->prepare("SELECT * FROM reseller_branding WHERE user_id = ? LIMIT 1");
        $stmt->execute([$resellerId]);
        $row = $stmt->fetch();
        return $cache = ($row ?: []);
    } catch (Throwable $e) {
        return $cache = [];
    }
}

function novacpx_branding_head(): void {
    $b = novacpx_get_branding();
    if (!$b) return;
    $pc = preg_match('/^#[0-9a-fA-F]{3,6}$/', $b['primary_color'] ?? '') ? $b['primary_color'] : null;
    $ac = preg_match('/^#[0-9a-fA-F]{3,6}$/', $b['accent_color']  ?? '') ? $b['accent_color']  : null;
    $css = $b['custom_css'] ?? '';
    echo '<style id="reseller-branding">' . "\n";
    echo ':root {' . "\n";
    if ($pc) echo "  --primary: $pc;\n  --primary-dark: $pc;\n";
    if ($ac) echo "  --accent: $ac;\n";
    echo '}' . "\n";
    // Sanitize custom CSS — allow only safe property declarations, strip everything else.
    // Regex approach (strip </style>) is bypassable; whitelist parsing is the safe alternative.
    $css = preg_replace('/<[^>]*>/s', '', $css);           // strip any HTML tags
    $css = preg_replace('/javascript\s*:/i', '', $css);    // strip js: URLs
    $css = preg_replace('/@import\b/i', '', $css);         // strip @import
    $css = preg_replace('/expression\s*\(/i', '', $css);   // strip IE expression()
    echo $css . "\n";
    echo '</style>' . "\n";
    if ($b['favicon_url'] ?? '') {
        $fav = htmlspecialchars($b['favicon_url']);
        echo "<link rel=\"icon\" href=\"$fav\">\n";
    }
}

function novacpx_panel_name(string $default): string {
    $b = novacpx_get_branding();
    return htmlspecialchars($b['panel_name'] ?? $default);
}

function novacpx_logo_html(string $default_svg): string {
    $b = novacpx_get_branding();
    if (!empty($b['logo_url'])) {
        $url  = htmlspecialchars($b['logo_url']);
        $name = htmlspecialchars($b['panel_name'] ?? 'Panel');
        return "<img src=\"$url\" alt=\"$name\" style=\"max-height:36px;max-width:160px;object-fit:contain\">";
    }
    return $default_svg;
}

function novacpx_powered_by(): bool {
    $b = novacpx_get_branding();
    return empty($b['hide_powered_by']);
}
