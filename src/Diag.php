<?php
declare(strict_types=1);

namespace SportCard101;

/**
 * Black-box recorder for the scan. Web servers often replace a PHP fatal with
 * their own 500 page, swallowing whatever the script printed — so progress is
 * written to a file line by line as it happens. Whatever the last line says is
 * where execution died.
 *
 * Self-limiting: truncates itself, and never throws.
 */
final class Diag
{
    private const MAX_BYTES = 262144; // 256 KB

    /** Log file path — app root when writable, else the system temp dir. */
    public static function path(): string
    {
        $root = defined('APP_ROOT') ? APP_ROOT : sys_get_temp_dir();
        return (is_dir($root) && is_writable($root))
            ? $root . '/cron-debug.log'
            : sys_get_temp_dir() . '/sportcard101-cron-debug.log';
    }

    /** Append one timestamped line. Never throws, never blocks. */
    public static function log(string $msg): void
    {
        try {
            $p = self::path();
            if (@filesize($p) > self::MAX_BYTES) {
                @file_put_contents($p, "(truncated)\n");
            }
            @file_put_contents(
                $p,
                sprintf("[%s] %s (mem %.1fMB)\n", date('Y-m-d H:i:s'), $msg, memory_get_usage(true) / 1048576),
                FILE_APPEND | LOCK_EX
            );
        } catch (\Throwable $e) {
            // diagnostics must never break the thing they're diagnosing
        }
    }

    /** Last $lines lines of the log, newest last. Empty array when none. */
    public static function tail(int $lines = 40): array
    {
        $p = self::path();
        if (!is_file($p)) {
            return [];
        }
        $all = @file($p, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        return $all ? array_slice($all, -$lines) : [];
    }

    /** Wipe the log. */
    public static function clear(): void
    {
        @unlink(self::path());
    }
}
