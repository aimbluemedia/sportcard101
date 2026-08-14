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
// If the host's cron stops firing, ordinary page traffic runs the scan itself.
// Two delivery methods, tried in order:
//   1. exec() — a detached background process (fastest, but shared hosts
//      almost always disable exec; function_exists() reports false then).
//   2. Inline after the response is flushed to the browser, via
//      fastcgi_finish_request() (PHP-FPM) or litespeed_finish_request()
//      (LiteSpeed, which Hostinger uses). The visitor waits for nothing.
// If neither is available we record that, so Settings can say so plainly
// instead of the fallback failing silently. cron.php is excluded (recursion).
if (PHP_SAPI !== 'cli' && basename((string)($_SERVER['SCRIPT_NAME'] ?? '')) !== 'cron.php') {
    try {
        $cronKey = (string) (setting('cron_key', '') ?: ($config['cron']['key'] ?? ''));

        // Is a scan overdue? Stale after 35 min, re-attempted at most every 10.
        $lastRun   = (string) (setting('cron_last_run', '') ?? '');
        $lastKick  = (int) (setting('cron_kick_at', '0') ?: 0);
        $needScan  = ($lastRun === '' || time() - strtotime($lastRun) > 35 * 60)
                     && (time() - $lastKick > 10 * 60);

        // Is today's playbook missing after 7am? Attempted once per day.
        $needPlan = false;
        if ((int) date('G') >= 7 && (string) (setting('plan_kick_day', '') ?? '') !== date('Y-m-d')) {
            try {
                $q = $pdo->prepare('SELECT 1 FROM daily_plans WHERE plan_date = ?');
                $q->execute([date('Y-m-d')]);
                $needPlan = !$q->fetchColumn();
            } catch (\Throwable $e) {
                $needPlan = false; // tables not migrated — nothing to build
            }
        }

        if ($cronKey !== '' && ($needScan || $needPlan)) {
            // Claim the work before doing it, so concurrent hits don't pile on.
            if ($needScan) {
                set_setting('cron_kick_at', (string) time());
            }
            if ($needPlan) {
                set_setting('plan_kick_day', date('Y-m-d'));
            }

            $phpBin = null;
            if (function_exists('exec')) {
                foreach (['/usr/bin/php', PHP_BINDIR . '/php', PHP_BINARY] as $cand) {
                    if ($cand && @is_executable($cand)) {
                        $phpBin = $cand;
                        break;
                    }
                }
            }
            $canFinish = function_exists('fastcgi_finish_request') || function_exists('litespeed_finish_request');

            if ($phpBin !== null) {
                foreach (array_filter(['' => $needScan, 'daily' => $needPlan]) as $task => $_) {
                    @exec(escapeshellarg($phpBin) . ' ' . escapeshellarg(APP_ROOT . '/cron.php')
                        . ' ' . escapeshellarg($cronKey) . ($task !== '' ? ' ' . escapeshellarg($task) : '')
                        . ' > /dev/null 2>&1 &');
                }
                set_setting('cron_fallback_note', date('M j, g:ia') . ' — background process (exec)');
            } elseif ($canFinish) {
                // Deliver the page first, then scan in the leftover process.
                register_shutdown_function(function () use ($pdo, $config, $needScan, $needPlan): void {
                    if (function_exists('fastcgi_finish_request')) {
                        @fastcgi_finish_request();
                    } elseif (function_exists('litespeed_finish_request')) {
                        @litespeed_finish_request();
                    }
                    // One runner at a time, no matter how many visitors land.
                    $fh = @fopen(sys_get_temp_dir() . '/sportcard101-scan.lock', 'c');
                    if (!$fh || !@flock($fh, LOCK_EX | LOCK_NB)) {
                        return;
                    }
                    @set_time_limit(300);
                    @ignore_user_abort(true);
                    try {
                        if ($needScan) {
                            \SportCard101\ScanRunner::run($pdo, $config);
                        }
                        if ($needPlan) {
                            \SportCard101\ScanRunner::daily($pdo, $config);
                        }
                        set_setting('cron_fallback_note', date('M j, g:ia') . ' — ran inline after a page visit');
                    } catch (\Throwable $e) {
                        set_setting('cron_last_run', date('Y-m-d H:i:s'));
                        set_setting('cron_last_status', 'ERROR (traffic fallback) — ' . $e->getMessage());
                    } finally {
                        @flock($fh, LOCK_UN);
                        @fclose($fh);
                    }
                });
            } else {
                set_setting('cron_fallback_note', date('M j, g:ia')
                    . ' — UNAVAILABLE: this host blocks exec() and has no finish-request support, so page traffic cannot run the scan. Use an external cron service.');
            }
        }
    } catch (\Throwable $e) {
        // The fallback must never break a page.
    }
}
