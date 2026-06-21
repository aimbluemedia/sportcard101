<?php
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';
require __DIR__ . '/../src/layout.php';

use SportCard101\Auth;
use SportCard101\EbayClient;
use SportCard101\AiAnalyst;
use SportCard101\DealFinder;
use SportCard101\Notifier;

Auth::requireAdmin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('/superadmin/searches.php');
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
    flash($n ? 'success' : 'info', $n ? "Scan complete — {$n} new deal" . ($n === 1 ? '' : 's') . " found!" : 'Scan complete — no new deals this time.');
} catch (\Throwable $e) {
    flash('error', 'Scan failed: ' . $e->getMessage());
}

redirect('/superadmin/deals.php');
