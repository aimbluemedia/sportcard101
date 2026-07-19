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

use SportCard101\EbayClient;
use SportCard101\AiAnalyst;
use SportCard101\DealFinder;
use SportCard101\Comps;
use SportCard101\DealAlerts;
use SportCard101\LotFinder;
use SportCard101\Mailer;
use SportCard101\Playbook;

header('Content-Type: text/plain; charset=utf-8');

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
    $ai = new AiAnalyst($config['ai']);
    try {
        // Record freshly closed auctions and grade yesterday's predictions
        // first, so this morning's comps and scorecard are current.
        $recorded = Comps::recordClosed($pdo);
        $graded   = Playbook::gradeClosed($pdo);
        $res  = Playbook::build($pdo, $ai);
        $plan = Playbook::load($pdo, date('Y-m-d'));
        $score = Playbook::scorecard($pdo);

        $sent = false;
        $to   = trim((string) setting('notify_email', ''));
        if ($to !== '' && $plan) {
            $sells    = Playbook::sellActions($pdo);
            $morningLots = Playbook::morningLots($pdo);
            $subject = 'Morning Playbook — ' . date('D, M j') . ': '
                     . ($res['buys'] > 0 ? $res['buys'] . ' buy target' . ($res['buys'] === 1 ? '' : 's') : 'no qualified buys');
            $sent = Mailer::send($to, $subject,
                Playbook::emailText($plan, $sells, $score, $morningLots),
                Playbook::emailHtml($plan, $sells, $score, $morningLots));
        }

        echo "OK (daily playbook)\n";
        echo "buy targets:  {$res['buys']}\n";
        echo "watchlist:    {$res['watch']}\n";
        echo "exposure:     \${$res['exposure']}\n";
        echo "new comps:    {$recorded}\n";
        echo "picks graded: {$graded}\n";
        echo "ai narrative: {$res['ai']}\n";
        echo 'email:        ' . ($sent ? "sent to {$to}" : 'not sent') . "\n";
    } catch (\Throwable $e) {
        http_response_code(500);
        echo 'ERROR: ' . $e->getMessage() . "\n";
    }
    exit;
}

// ---- Run the scan + closing tracker --------------------------------------
$ebay   = new EbayClient(ebay_config($config['ebay']));
$ai     = new AiAnalyst($config['ai']);
$finder = new DealFinder($pdo, $ebay, (int)($config['deals']['scan_limit'] ?? 100), $ai);

$started = microtime(true);
try {
    $newDeals = $finder->scanSelected($uid, null, null);
    $recorded = Comps::recordClosed($pdo);     // lock in auctions that just closed
    $alerts   = DealAlerts::run($pdo);          // email comp-beating auctions FIRST
    $graded   = Playbook::gradeClosed($pdo);   // then grade playbook picks (never blocks alerts)

    // Bulk-lot sweep + BUY-lot alert email — best-effort, never breaks the scan.
    $lots = ['found' => 0, 'new' => 0, 'analyzed' => 0];
    $lotAlerts = 0;
    try {
        $lots = LotFinder::scan($pdo, $ebay, $ai);
        $lotAlerts = LotFinder::alert($pdo);
    } catch (\Throwable $e) {
        // lots are a bonus; ignore failures here
    }

    $secs = round(microtime(true) - $started, 1);

    // Heartbeat — lets the superadmin Settings page confirm cron is firing.
    $summary = sprintf('OK — %d deals flagged, %d sold comps, %d alerts sent, %ss (%s)',
        count($newDeals), $recorded, count($alerts), $secs, $ebay->isMock() ? 'mock' : 'live');
    set_setting('cron_last_run', date('Y-m-d H:i:s'));
    set_setting('cron_last_status', $summary);

    echo "OK\n";
    echo "new deals flagged: " . count($newDeals) . "\n";
    echo "new sold comps:    {$recorded}\n";
    echo "picks graded:      {$graded}\n";
    echo "deal alerts sent:  " . count($alerts) . "\n";
    echo "lots:              {$lots['found']} live ({$lots['new']} new, {$lots['analyzed']} valued, {$lotAlerts} alerted)\n";
    echo "ebay mode:         " . ($ebay->isMock() ? 'mock (no keyset)' : 'live') . "\n";
    echo "took:              {$secs}s\n";
} catch (\Throwable $e) {
    set_setting('cron_last_run', date('Y-m-d H:i:s'));
    set_setting('cron_last_status', 'ERROR — ' . $e->getMessage());
    http_response_code(500);
    echo "ERROR: " . $e->getMessage() . "\n";
}
