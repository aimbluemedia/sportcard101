<?php
declare(strict_types=1);

/**
 * Automated scan + closing-tracker entry point for Hostinger cron.
 *
 * No login — protected by a secret key. Set the key either in the superadmin
 * Settings page ("Cron secret key") or in config.php as:
 *     'cron' => ['key' => 'your-long-random-secret'],
 *
 * Cron commands — prefer the direct PHP form (Hostinger's "PHP" cron type);
 * it bypasses the web server entirely, so it keeps working even when curl
 * from the cron host can't reach the domain:
 *   Scan (every 30 min):
 *     *\/30 * * * * /usr/bin/php /path/to/public_html/cron.php YOUR_SECRET
 *   Morning Playbook (once a day, e.g. 7:00am — mind the server timezone):
 *     0 7 * * * /usr/bin/php /path/to/public_html/cron.php YOUR_SECRET daily
 *
 * The HTTP form also works (for manual browser tests or external cron):
 *     https://sportcard101.com/cron.php?key=YOUR_SECRET[&task=daily]
 *
 * What each scan run does:
 *   1. Scans every active channel (fresh auctions + a new bid snapshot each).
 *   2. Re-runs the AI value rating on deal candidates.
 *   3. Records any auctions that have since closed as sold comps.
 *   4. Emails new deals if mail is configured.
 *
 * task=daily builds the Morning Playbook (daily buy/sell plan) and emails it.
 */

require __DIR__ . '/src/bootstrap.php';

use SportCard101\ScanRunner;

header('Content-Type: text/plain; charset=utf-8');

// A full scan takes ~30s and grows as channels are added. Web requests are
// capped at 30s on most shared hosts, and a timeout kill is a FATAL that
// try/catch cannot intercept — it surfaces as a blank HTTP 500 and the
// heartbeat never gets stamped. Raise the ceiling, and keep running even if
// the caller (curl, a browser tab) disconnects.
@set_time_limit(600);
@ignore_user_abort(true);

// Turn an uncatchable fatal (timeout, memory) into a readable message AND a
// recorded status, instead of a blank 500 that tells nobody anything.
register_shutdown_function(function (): void {
    $e = error_get_last();
    if ($e === null || !in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        return;
    }
    $msg = $e['message'] . ' in ' . basename((string)$e['file']) . ':' . $e['line'];
    if (str_contains($e['message'], 'Maximum execution time')) {
        $msg .= ' — the scan exceeded this host\'s time limit. Reduce active channels, or run cron from the command line where no limit applies.';
    }
    // File first — the DB may be exactly what died. @ suppresses warnings but
    // NOT exceptions, so the DB writes must be guarded explicitly or this
    // handler throws and buries the error it exists to report.
    \SportCard101\Diag::log('FATAL: ' . $msg);
    try {
        set_setting('cron_last_run', date('Y-m-d H:i:s'));
        set_setting('cron_last_status', 'FATAL — ' . mb_substr($msg, 0, 400));
    } catch (\Throwable $e2) {
        \SportCard101\Diag::log('  (status not recorded: ' . $e2->getMessage() . ')');
    }
    echo "\nFATAL: {$msg}\n";
});

// ?debug=1 surfaces errors inline instead of leaving a blank 500 behind.
if (!empty($_GET['debug'])) {
    @ini_set('display_errors', '1');
    @ini_set('display_startup_errors', '1');
    error_reporting(E_ALL);
}
\SportCard101\Diag::log('--- cron.php invoked (' . PHP_SAPI . ', task="' . ($_GET['task'] ?? ($argv[2] ?? '')) . '") ---');

// ---- Authenticate the request --------------------------------------------
// CLI (php cron.php KEY [daily]) or HTTP (?key=KEY[&task=daily]).
$expected = (string) (setting('cron_key', '') ?: ($config['cron']['key'] ?? ''));
if (PHP_SAPI === 'cli') {
    $provided = (string) ($argv[1] ?? '');
    $task     = (string) ($argv[2] ?? '');
} else {
    $provided = (string) ($_GET['key'] ?? ($_SERVER['HTTP_X_CRON_KEY'] ?? ''));
    $task     = (string) ($_GET['task'] ?? '');
}

if ($expected === '') {
    http_response_code(503);
    exit("Cron key not configured. Set 'Cron secret key' in Settings (or config.php).\n");
}
if (!hash_equals($expected, $provided)) {
    http_response_code(403);
    exit("Forbidden.\n");
}

// ---- Resolve the owning superadmin ---------------------------------------
$uid = (int) ($pdo->query("SELECT id FROM users WHERE role = 'superadmin' ORDER BY id ASC LIMIT 1")->fetchColumn() ?: 0);
if ($uid === 0) {
    http_response_code(500);
    exit("No superadmin user found.\n");
}

// ---- Daily task: build + email the Morning Playbook -----------------------
if ($task === 'daily') {
    try {
        $r = ScanRunner::daily($pdo, $config);
        echo "OK (daily playbook)\n";
        echo "buy targets:  {$r['buys']}\n";
        echo "watchlist:    {$r['watch']}\n";
        echo "exposure:     \${$r['exposure']}\n";
        echo "new comps:    {$r['comps']}\n";
        echo "picks graded: {$r['graded']}\n";
        echo "ai narrative: {$r['ai']}\n";
        echo 'email:        ' . ($r['sent'] ? "sent to {$r['to']}" : 'not sent') . "\n";
        echo 'lot digest:   ' . ($r['lot_digest'] > 0 ? "{$r['lot_digest']} lots emailed" : 'nothing worth a look') . "\n";
    } catch (\Throwable $e) {
        http_response_code(500);
        echo 'ERROR: ' . $e->getMessage() . "\n";
    }
    exit;
}

// ---- Run the scan + closing tracker --------------------------------------
try {
    $r = ScanRunner::run($pdo, $config, $uid);
    echo "OK\n";
    echo "new deals flagged: {$r['deals']}\n";
    echo "new sold comps:    {$r['comps']}\n";
    echo "picks graded:      {$r['graded']}\n";
    echo "deal alerts sent:  {$r['alerts']}\n";
    echo "lots:              {$r['lots']['found']} live ({$r['lots']['new']} new, {$r['lots']['analyzed']} valued, {$r['lot_alerts']} alerted)\n";
    echo "error cards:       {$r['error_alerts']} alerted\n";
    echo 'ebay mode:         ' . ($r['mock'] ? 'mock (no keyset)' : 'live') . "\n";
    echo "took:              {$r['secs']}s\n";
} catch (\Throwable $e) {
    // Log to FILE first — no database involved, so the real error survives even
    // when the connection is what died. Recording it in the DB is best-effort.
    \SportCard101\Diag::log('SCAN ERROR: ' . get_class($e) . ': ' . $e->getMessage()
        . ' in ' . basename($e->getFile()) . ':' . $e->getLine());
    \SportCard101\Diag::log('  trace: ' . mb_substr($e->getTraceAsString(), 0, 600));
    try {
        set_setting('cron_last_run', date('Y-m-d H:i:s'));
        set_setting('cron_last_status', 'ERROR — ' . mb_substr($e->getMessage(), 0, 400));
    } catch (\Throwable $e2) {
        \SportCard101\Diag::log('  (status not recorded: ' . $e2->getMessage() . ')');
    }
    http_response_code(500);
    echo 'ERROR: ' . $e->getMessage() . "\n";
}
