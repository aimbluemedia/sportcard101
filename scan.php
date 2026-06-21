<?php
declare(strict_types=1);

require __DIR__ . '/src/bootstrap.php';
require __DIR__ . '/src/layout.php';

use Sportscard101\Auth;
use Sportscard101\EbayClient;
use Sportscard101\AiAnalyst;
use Sportscard101\DealFinder;
use Sportscard101\Notifier;

Auth::require();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('index.php');
}
csrf_verify();

$ebay   = new EbayClient($config['ebay']);
$ai     = new AiAnalyst($config['ai']);
$finder = new DealFinder($pdo, $ebay, (int)($config['deals']['scan_limit'] ?? 100), $ai);
$notifier = new Notifier($pdo, $config['mail']);

try {
    $newDeals = $finder->scanAll(Auth::userId());
    $notifier->notify($newDeals);

    $n = count($newDeals);
    if ($n > 0) {
        flash('success', "Scan complete — {$n} new deal" . ($n === 1 ? '' : 's') . " found!");
    } else {
        flash('info', 'Scan complete — no new deals this time.');
    }
} catch (\Throwable $e) {
    flash('error', 'Scan failed: ' . $e->getMessage());
}

redirect('index.php');
