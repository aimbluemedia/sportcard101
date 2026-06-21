<?php
declare(strict_types=1);

/**
 * CLI scanner — run from cron to scan all users' active searches and send
 * email notifications for new deals.
 *
 *   * /15 * * * *  php /path/to/sportscard101/bin/scan.php >> /var/log/sportscard101.log 2>&1
 */

require __DIR__ . '/../src/bootstrap.php';

use Sportscard101\EbayClient;
use Sportscard101\AiAnalyst;
use Sportscard101\DealFinder;
use Sportscard101\Notifier;

$ebay     = new EbayClient($config['ebay']);
$ai       = new AiAnalyst($config['ai']);
$finder   = new DealFinder($pdo, $ebay, (int)($config['deals']['scan_limit'] ?? 100), $ai);
$notifier = new Notifier($pdo, $config['mail']);

$started = date('Y-m-d H:i:s');
echo "[{$started}] sportscard101 scan starting"
    . ($ebay->isMock() ? ' (eBay MOCK)' : '')
    . ($ai->isMock() ? ' (AI MOCK)' : '') . "\n";

// Scan every user.
$users = $pdo->query('SELECT id, username FROM users')->fetchAll();
$total = 0;

foreach ($users as $u) {
    $newDeals = $finder->scanAll((int)$u['id']);
    if ($newDeals) {
        $notifier->notify($newDeals);
        $total += count($newDeals);
        echo "  user {$u['username']}: " . count($newDeals) . " new deal(s)\n";
        foreach ($newDeals as $d) {
            printf("    - %s  \$%.2f  (-%s%%)\n", $d['title'], $d['price'], $d['discount_pct'] ?? '?');
        }
    }
}

echo "[" . date('Y-m-d H:i:s') . "] done — {$total} new deal(s) total\n";
