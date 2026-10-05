<?php
// Copyright (c) 2026 TomTom Enterprises. Licensed under the MIT License (see LICENSE).
/**
 * Optional tools a hosting package can include. A package with no list (NULL) includes everything; otherwise only the listed tools
 * are shown to the customer and their API endpoints refuse everyone else. Admins and resellers are never restricted.
 */
class PackageTools {
    /** tool slug => [label, [api endpoints it covers]] */
    public const CATALOG = [
        'shield'    => ['Site Shield and firewall', ['shield', 'waf']],
        'traffic'   => ['Traffic meter',            ['traffic']],
        'pulse'     => ['Uptime monitor',           ['pulse']],
        'sweep'     => ['Malware sweep',            ['sweep']],
        'gitdeploy' => ['Git Deploy',               ['gitdeploy']],
        'docker'    => ['Docker apps',              ['docker']],
        'wordpress' => ['WordPress manager',        ['wordpress']],
        'cron'      => ['Cron jobs',                ['cron']],
        'backups'   => ['Backups',                  ['backup']],
    ];

    /** @return string[]|null the customer's allowed tools, or null for no restriction */
    public static function forUser(int $userId): ?array {
        $row = DB::getInstance()->fetchOne(
            "SELECT p.tools FROM accounts a JOIN packages p ON p.id = a.package_id WHERE a.user_id = ?", [$userId]);
        if (!$row || $row['tools'] === null || $row['tools'] === '') return null;
        $list = json_decode($row['tools'], true);
        return is_array($list) ? array_values(array_filter($list, 'is_string')) : null;
    }

    /** The tool an API endpoint belongs to, or null when the endpoint is not an optional tool. */
    public static function toolForEndpoint(string $endpoint): ?string {
        foreach (self::CATALOG as $slug => [, $eps]) if (in_array($endpoint, $eps, true)) return $slug;
        return null;
    }

    /** Clean a list from a form: known slugs only. All slugs selected means "no restriction" (null). */
    public static function normalise($in): ?string {
        if (!is_array($in)) return null;
        $keep = array_values(array_unique(array_filter($in, fn($s) => is_string($s) && isset(self::CATALOG[$s]))));
        return count($keep) === count(self::CATALOG) ? null : json_encode($keep);
    }
}
