<?php
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';
require __DIR__ . '/../src/layout.php';

use SportCard101\Auth;
use SportCard101\EbayClient;
use SportCard101\AiAnalyst;
use SportCard101\DealFinder;
use SportCard101\Comps;
use SportCard101\DealAlerts;

Auth::requireAdmin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('/superadmin/auctions.php');
}
csrf_verify();

$ebay   = new EbayClient(ebay_config($config['ebay']));
$ai     = new AiAnalyst($config['ai']);
$finder = new DealFinder($pdo, $ebay, (int)($config['deals']['scan_limit'] ?? 100), $ai);
$uid = Auth::userId();

$sports    = card_sports();
$companies = card_companies();
$gradeNums = card_grade_nums();

// What to scan: a specific company + grade (+ optional sport), or just a
// refresh of everything already on the board.
$company  = isset($_POST['company']) && isset($companies[$_POST['company']]) ? (string)$_POST['company'] : '';
$gradeNum = in_array($_POST['grade'] ?? '', $gradeNums, true) ? (string)$_POST['grade'] : '';
$sport    = isset($_POST['sport']) && isset($sports[$_POST['sport']]) ? (string)$_POST['sport'] : 'all';
$when     = ($_POST['when'] ?? 'today') === 'soon' ? 'soon' : 'today';

// Build the full grade string ("PSA 10") when both company + grade chosen.
$grade = ($company !== '' && $gradeNum !== '') ? $company . ' ' . $gradeNum : '';

try {
    if ($grade !== '') {
        // Ensure the channel(s) exist for this company+grade, then scan them.
        $targets = $sport === 'all' ? array_keys($sports) : [$sport];
        foreach ($targets as $sk) {
            $label = $sports[$sk]['emoji'] . ' ' . $sports[$sk]['label'] . ' — ' . $grade;
            DealFinder::ensureChannel($pdo, $uid, $sk, $label, $grade);
        }
        $newDeals = $finder->scanSelected($uid, $sport === 'all' ? null : $sport, $grade);
        $scope = ($sport === 'all' ? 'all sports' : $sports[$sport]['label']) . ' · ' . $grade;
    } else {
        // No specific combo — refresh every channel already on the board.
        $newDeals = $finder->scanSelected($uid, null, null);
        $scope = 'all channels';
    }

    // Lock in any auctions that have since closed as sold comps.
    $recorded = Comps::recordClosed($pdo);
    // Email comp-beating auctions (respects the Deal Alerts settings).
    $alerts = DealAlerts::run($pdo);

    $n = count($newDeals);
    $compMsg = $recorded ? " {$recorded} sold comp" . ($recorded === 1 ? '' : 's') . " recorded." : '';
    $compMsg .= $alerts ? ' ' . count($alerts) . ' deal alert' . (count($alerts) === 1 ? '' : 's') . ' emailed.' : '';
    flash(
        $n ? 'success' : 'info',
        ($n
            ? "Scanned {$scope} — {$n} new deal" . ($n === 1 ? '' : 's') . " flagged!"
            : "Scanned {$scope} — auctions captured, no new under-market deals flagged.") . $compMsg
    );
} catch (\Throwable $e) {
    flash('error', 'Scan failed: ' . $e->getMessage());
}

// Return to the board, preserving the active filters.
$back = http_build_query(array_filter([
    'when'    => $when,
    'sport'   => $sport === 'all' ? '' : $sport,
    'company' => $company,
    'grade'   => $gradeNum,
]));
redirect('/superadmin/auctions.php' . ($back ? '?' . $back : ''));
