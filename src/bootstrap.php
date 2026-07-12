<?php
declare(strict_types=1);

/**
 * App bootstrap: load config, register a tiny autoloader, start the session,
 * connect to the database, and expose shared helpers.
 *
 * Every entry point (public/*.php, bin/*.php) requires this file first.
 */

define('APP_ROOT', dirname(__DIR__));

// --- Config ---
// config.php lives in the application root (next to index.php).
$configCandidates = [
    APP_ROOT . '/config.php',
    APP_ROOT . '/admin/config.php', // legacy layout fallback
];
$configFile = null;
foreach ($configCandidates as $candidate) {
    if (is_file($candidate)) {
        $configFile = $candidate;
        break;
    }
}
if ($configFile === null) {
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, "Missing config.php. Run: cp config.sample.php config.php\n");
        exit(1);
    }
    http_response_code(500);
    exit('Missing config.php — copy config.sample.php to config.php and set your values.');
}
$config = require $configFile;

date_default_timezone_set($config['app']['timezone'] ?? 'UTC');

// --- Autoloader for SportCard101\* classes ---
spl_autoload_register(function (string $class): void {
    $prefix = 'SportCard101\\';
    if (str_starts_with($class, $prefix)) {
        $file = __DIR__ . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($file)) {
            require $file;
        }
    }
});

require __DIR__ . '/helpers.php';

// --- Database ---
$pdo = \SportCard101\Database::connect($config['db']);

// --- Session (web only) ---
if (PHP_SAPI !== 'cli' && session_status() !== PHP_SESSION_ACTIVE) {
    session_set_cookie_params([
        'httponly' => true,
        'samesite' => 'Lax',
        'secure'   => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
    ]);
    session_name('sportcard101');
    session_start();
}

// --- Self-healing scheduler (wp-cron style fallback) ---
// If the host's cron stops firing, ordinary page traffic kicks the scan as a
// detached background process. Throttled, silent, and skipped entirely while
// the real cron is healthy. cron.php itself is excluded to avoid recursion.
if (PHP_SAPI !== 'cli' && basename((string)($_SERVER['SCRIPT_NAME'] ?? '')) !== 'cron.php') {
    try {
        $cronKey = (string) (setting('cron_key', '') ?: ($config['cron']['key'] ?? ''));
        if ($cronKey !== '' && function_exists('exec')) {
            $phpBin = null;
            foreach (['/usr/bin/php', PHP_BINDIR . '/php', PHP_BINARY] as $cand) {
                if ($cand && @is_executable($cand)) { $phpBin = $cand; break; }
            }
            $kick = function (string $task) use ($cronKey, $phpBin): void {
                if ($phpBin === null) { return; }
                @exec(escapeshellarg($phpBin) . ' ' . escapeshellarg(APP_ROOT . '/cron.php')
                    . ' ' . escapeshellarg($cronKey) . ($task !== '' ? ' ' . escapeshellarg($task) : '')
                    . ' > /dev/null 2>&1 &');
            };

            // Scan: stale when no run in 35 min. Re-kick at most every 10 min.
            $lastRun  = (string) (setting('cron_last_run', '') ?? '');
            $lastKick = (int) (setting('cron_kick_at', '0') ?: 0);
            if (($lastRun === '' || time() - strtotime($lastRun) > 35 * 60) && time() - $lastKick > 10 * 60) {
                set_setting('cron_kick_at', (string) time());
                $kick('');
            }

            // Morning Playbook: kick once if 7am has passed with no plan today.
            if ((int) date('G') >= 7) {
                $planKickDay = (string) (setting('plan_kick_day', '') ?? '');
                $today = date('Y-m-d');
                if ($planKickDay !== $today) {
                    $hasPlan = false;
                    try {
                        $q = $pdo->prepare('SELECT 1 FROM daily_plans WHERE plan_date = ?');
                        $q->execute([$today]);
                        $hasPlan = (bool) $q->fetchColumn();
                    } catch (\Throwable $e) {
                        $hasPlan = true; // tables not migrated — nothing to kick
                    }
                    if (!$hasPlan) {
                        set_setting('plan_kick_day', $today);
                        $kick('daily');
                    }
                }
            }
        }
    } catch (\Throwable $e) {
        // The fallback must never break a page.
    }
}
