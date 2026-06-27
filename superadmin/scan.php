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

$ebay   = new EbayClient(ebay_config($config['ebay']));
$ai     = new AiAnalyst($config['ai']);
$finder = new DealFinder($pdo, $ebay, (int)($config['deals']['scan_limit'] ?? 100), $ai);
$notifier = new Notifier($pdo, $config['mail']);

// Optional scope: scan one sport (matched on search keywords) and/or one grade.
$sport = trim((string)($_POST['sport'] ?? ''));
$grade = trim((string)($_POST['grade'] ?? ''));
$when  = ($_POST['when'] ?? 'today') === 'soon' ? 'soon' : 'today';

try {
    $newDeals = $finder->scanSelected(Auth::userId(), $sport ?: null, $grade ?: null);
    $notifier->notify($newDeals);
    $n = count($newDeals);
    flash($n ? 'success' : 'info', $n ? "Scan complete — {$n} new deal" . ($n === 1 ? '' : 's') . " flagged!" : 'Scan complete — auctions captured, no new under-market deals flagged.');
} catch (\Throwable $e) {
    flash('error', 'Scan failed: ' . $e->getMessage());
}

// Return to the board, preserving the active filters.
$back = http_build_query(array_filter([
    'when'  => $when,
    'sport' => $sport,
    'grade' => $grade,
]));
redirect('/superadmin/auctions.php' . ($back ? '?' . $back : ''));
