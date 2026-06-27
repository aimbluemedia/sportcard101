<?php
declare(strict_types=1);

/**
 * Automated scan + closing-tracker entry point for Hostinger cron.
 *
 * No login — protected by a secret key. Set the key either in the superadmin
 * Settings page ("Cron secret key") or in config.php as:
 *     'cron' => ['key' => 'your-long-random-secret'],
 *
 * Cron command (every 30 min):
 *   *\/30 * * * * curl -s "https://sportcard101.com/cron.php?key=YOUR_SECRET" >/dev/null 2>&1
 *
 * What each run does:
 *   1. Scans every active channel (fresh auctions + a new bid snapshot each).
 *   2. Re-runs the AI value rating on deal candidates.
 *   3. Records any auctions that have since closed as sold comps.
 *   4. Emails new deals if mail is configured.
 */

require __DIR__ . '/src/bootstrap.php';

use SportCard101\EbayClient;
use SportCard101\AiAnalyst;
use SportCard101\DealFinder;
use SportCard101\Notifier;
use SportCard101\Comps;

header('Content-Type: text/plain; charset=utf-8');

// ---- Authenticate the request --------------------------------------------
$expected = (string) (setting('cron_key', '') ?: ($config['cron']['key'] ?? ''));
$provided = (string) ($_GET['key'] ?? ($_SERVER['HTTP_X_CRON_KEY'] ?? ''));

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

// ---- Run the scan + closing tracker --------------------------------------
$ebay   = new EbayClient(ebay_config($config['ebay']));
$ai     = new AiAnalyst($config['ai']);
$finder = new DealFinder($pdo, $ebay, (int)($config['deals']['scan_limit'] ?? 100), $ai);

$started = microtime(true);
try {
    $newDeals = $finder->scanSelected($uid, null, null); // scanSelected also records closes
    $recorded = Comps::recordClosed($pdo);               // belt-and-suspenders

    if ($newDeals && !empty($config['mail']['enabled'])) {
        (new Notifier($pdo, $config['mail']))->notify($newDeals);
    }

    $secs = round(microtime(true) - $started, 1);
    echo "OK\n";
    echo "new deals flagged: " . count($newDeals) . "\n";
    echo "new sold comps:    {$recorded}\n";
    echo "ebay mode:         " . ($ebay->isMock() ? 'mock (no keyset)' : 'live') . "\n";
    echo "took:              {$secs}s\n";
} catch (\Throwable $e) {
    http_response_code(500);
    echo "ERROR: " . $e->getMessage() . "\n";
}
