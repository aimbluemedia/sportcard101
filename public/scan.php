<?php
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';
require __DIR__ . '/../src/layout.php';

use Vipsvault\Auth;
use Vipsvault\EbayClient;
use Vipsvault\DealFinder;
use Vipsvault\Notifier;

Auth::require();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('index.php');
}
csrf_verify();

$ebay   = new EbayClient($config['ebay']);
$finder = new DealFinder($pdo, $ebay, (int)($config['deals']['scan_limit'] ?? 100));
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
