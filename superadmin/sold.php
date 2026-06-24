<?php
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';
require __DIR__ . '/../src/layout.php';

use SportCard101\Auth;
use SportCard101\EbayClient;

Auth::requireAdmin();

$ebay = new EbayClient(ebay_config($config['ebay']));

// --- Filters ---
$keywords = trim((string)($_GET['q'] ?? ''));
$date     = (string)($_GET['date'] ?? date('Y-m-d'));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    $date = date('Y-m-d');
}
$limit = (int)($_GET['limit'] ?? 100);
$limit = max(10, min($limit, 200));

// Which grades to pull.
$gradeOptions = ['PSA 10' => 'PSA 10', 'BGS 10' => 'Beckett (BGS) 10'];
$grades = isset($_GET['grades']) && is_array($_GET['grades'])
    ? array_values(array_intersect(array_keys($gradeOptions), $_GET['grades']))
    : ['PSA 10', 'BGS 10'];

// Day window in UTC (eBay expects ISO 8601 Z).
$fromIso = (new DateTime($date . ' 00:00:00', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.000\Z');
$toIso   = (new DateTime($date . ' 23:59:59', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.000\Z');

// --- Fetch sold items per selected grade ---
$sales = [];
foreach ($grades as $g) {
    foreach ($ebay->searchSold($keywords, $g, $fromIso, $toIso, $limit) as $row) {
        $sales[] = $row;
    }
}
// Most recent first.
usort($sales, fn ($a, $b) => strcmp((string)$b['sold_date'], (string)$a['sold_date']));

// Stats.
$count = count($sales);
$total = array_sum(array_map(fn ($s) => (float)$s['price'], $sales));
$avg   = $count ? $total / $count : 0.0;

layout_header('Sold Today', 'admin');
?>
<h1>📉 Sold report — <?= e(date('M j, Y', strtotime($date))) ?></h1>
<p class="sub">Completed/sold PSA 10 &amp; Beckett (BGS) 10 cards, pulled from eBay's Marketplace Insights API.</p>

<?php if ($ebay->isMock() || $ebay->lastNotice): ?>
<div class="mock-note">
    ⚠️ <strong>Sample data.</strong> <?= e($ebay->lastNotice ?: '') ?>
    Sold data requires eBay's <strong>Marketplace Insights API</strong> (a Limited Release — request access for your keyset at developer.ebay.com).
</div>
<?php endif; ?>

<form method="get" class="card" style="margin-bottom:22px">
    <div class="row">
        <div><label>Keywords (optional)</label><input name="q" value="<?= e($keywords) ?>" placeholder="e.g. Jordan, Charizard, rookie"></div>
        <div><label>Date</label><input name="date" type="date" value="<?= e($date) ?>"></div>
        <div><label>Max per grade</label><input name="limit" type="number" min="10" max="200" value="<?= $limit ?>"></div>
    </div>
    <div style="margin-top:12px">
        <?php foreach ($gradeOptions as $val => $label): ?>
            <label class="checkbox" style="display:inline-flex;margin-right:18px">
                <input type="checkbox" name="grades[]" value="<?= e($val) ?>" <?= in_array($val, $grades, true) ? 'checked' : '' ?>> <?= e($label) ?>
            </label>
        <?php endforeach; ?>
    </div>
    <div style="margin-top:14px"><button class="btn btn-primary" type="submit">Show sold</button></div>
</form>

<div class="stat-grid" style="margin-bottom:22px">
    <div class="stat"><div class="stat-num"><?= $count ?></div><div class="stat-label">Sold</div></div>
    <div class="stat"><div class="stat-num"><?= money($total) ?></div><div class="stat-label">Total value</div></div>
    <div class="stat"><div class="stat-num"><?= money($avg) ?></div><div class="stat-label">Avg price</div></div>
</div>

<?php if (!$sales): ?>
    <div class="empty">No sold cards found for this day/filter.</div>
<?php else: ?>
<table>
    <thead><tr><th></th><th>Card</th><th>Grade</th><th>Sold price</th><th>Sold (UTC)</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($sales as $s): ?>
        <tr>
            <td><?php if ($s['image_url']): ?><img src="<?= e($s['image_url']) ?>" alt="" style="width:40px;height:54px;object-fit:cover;border-radius:4px"><?php endif; ?></td>
            <td><?= e($s['title']) ?></td>
            <td><span class="verdict verdict-<?= $s['grade'] === 'PSA 10' ? 'buy' : 'watch' ?>"><?= e($s['grade']) ?></span></td>
            <td><strong><?= money((float)$s['price'], $s['currency']) ?></strong></td>
            <td><?= $s['sold_date'] ? e(date('M j, g:ia', strtotime($s['sold_date']))) : '—' ?></td>
            <td><?php if ($s['item_url']): ?><a class="btn btn-sm" href="<?= e(epn_link($s['item_url'])) ?>" target="_blank" rel="noopener">View</a><?php endif; ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
<p class="sub" style="margin-top:14px">Showing up to <?= $limit ?> per grade. Narrow with keywords for a specific player/set.</p>
<?php endif; ?>
<?php
layout_footer();
