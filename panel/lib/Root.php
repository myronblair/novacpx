<?php
// Copyright (c) 2026 TomTom Enterprises. Licensed under the MIT License (see LICENSE).
/**
 * Root — the panel's only door to privileged operations.
 *
 * Everything that used to be `sudo <command> <anything>` goes through /usr/local/sbin/novacpx-root instead (sudoers allows
 * exactly that one program). Each command takes one JSON object of parameters and validates all of them; see
 * deploy/novacpx-root. Output is the command's own output; a refused request exits 64 with the reason.
 */
class Root {
    public const HELPER = '/usr/local/sbin/novacpx-root';

    /** Run a helper command. Returns ['rc' => int, 'out' => string]. */
    public static function run(string $cmd, array $params = []): array {
        $rc = self::exec($cmd, $params, null, $out);
        return ['rc' => $rc, 'out' => $out];
    }

    /** Run and return the output, or throw RuntimeException (message = helper's output) on failure. */
    public static function ok(string $cmd, array $params = []): string {
        $r = self::run($cmd, $params);
        if ($r['rc'] !== 0) throw new RuntimeException(trim($r['out']) !== '' ? trim($r['out']) : "{$cmd} failed ({$r['rc']})");
        return $r['out'];
    }

    /** Run and decode the JSON document the helper prints; throws on failure. */
    public static function json(string $cmd, array $params = []): array {
        $d = json_decode(self::ok($cmd, $params), true);
        if (!is_array($d)) throw new RuntimeException("{$cmd}: unexpected helper output");
        return $d;
    }

    /** Run and call $onLine for every line as it is produced (long-running installs). Returns the exit status. */
    public static function stream(string $cmd, array $params, callable $onLine): int {
        return self::exec($cmd, $params, $onLine, $unused);
    }

    /** Run and yield every output line as it is produced (for the generator-based SSE endpoints). */
    public static function lines(string $cmd, array $params = []): \Generator {
        $proc = proc_open(['sudo', '-n', self::HELPER, $cmd], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes,
                          null, ['PATH' => '/usr/sbin:/usr/bin:/sbin:/bin']);
        if (!is_resource($proc)) { yield "cannot start the privileged helper\n"; return 127; }
        fwrite($pipes[0], json_encode($params, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        fclose($pipes[0]);
        while (($line = fgets($pipes[1])) !== false) yield $line;
        fclose($pipes[1]);
        return proc_close($proc);
    }

    /** The shell command line that runs a helper command with these parameters (for code that streams through a shell closure). */
    public static function shellCommand(string $cmd, array $params = []): string {
        return 'printf %s ' . escapeshellarg(json_encode($params, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE))
             . ' | sudo -n ' . escapeshellarg(self::HELPER) . ' ' . escapeshellarg($cmd);
    }

    /** Fire-and-forget (image pulls and similar): runs the helper detached, output to $logFile. */
    public static function background(string $cmd, array $params, string $logFile): void {
        $in = tempnam('/tmp', 'ncpx_root_');
        file_put_contents($in, json_encode($params, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        @chmod($in, 0600);
        shell_exec('(nohup sudo -n ' . escapeshellarg(self::HELPER) . ' ' . escapeshellarg($cmd)
                 . ' < ' . escapeshellarg($in) . ' > ' . escapeshellarg($logFile) . ' 2>&1; rm -f ' . escapeshellarg($in) . ') > /dev/null 2>&1 &');
    }

    private static function exec(string $cmd, array $params, ?callable $onLine, ?string &$out): int {
        $out = '';
        $proc = proc_open(['sudo', '-n', self::HELPER, $cmd], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes,
                          null, ['PATH' => '/usr/sbin:/usr/bin:/sbin:/bin']);
        if (!is_resource($proc)) { $out = 'cannot start the privileged helper'; return 127; }
        fwrite($pipes[0], json_encode($params, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        fclose($pipes[0]);
        while (($line = fgets($pipes[1])) !== false) {
            $out .= $line;
            if ($onLine) $onLine($line);
        }
        fclose($pipes[1]);
        return proc_close($proc);
    }
}
