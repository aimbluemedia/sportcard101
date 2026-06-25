<?php
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';
require __DIR__ . '/../src/layout.php';

use SportCard101\Auth;
use SportCard101\EbayClient;

Auth::requireAdmin();

$ebay = new EbayClient(ebay_config($config['ebay']));

// ---- Filters ----
$sports = [
    'all'        => 'All sports',
    'baseball'   => 'Baseball',
    'football'   => 'Football',
    'basketball' => 'Basketball',
    'hockey'     => 'Hockey',
    'golf'       => 'Golf',
];
$sport = isset($_GET['sport']) && isset($sports[$_GET['sport']]) ? $_GET['sport'] : 'all';

$keywords = trim((string)($_GET['q'] ?? ''));

$days = (int)($_GET['days'] ?? 7);
if (!in_array($days, [1, 7, 30], true)) {
    $days = 7;
}

$gradeOptions = ['PSA 10' => 'PSA 10', 'BGS 10' => 'Beckett (BGS) 10'];
$grades = isset($_GET['grades']) && is_array($_GET['grades'])
    ? array_values(array_intersect(array_keys($gradeOptions), $_GET['grades']))
    : ['PSA 10', 'BGS 10'];

$SHOW = 20; // results to display

// Date window (UTC).
$toIso   = (new DateTime('now', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.000\Z');
$fromIso = (new DateTime('now', new DateTimeZone('UTC')))->modify("-{$days} days")->format('Y-m-d\TH:i:s.000\Z');

// Sport keyword appended to the query (category-agnostic, robust).
$sportTerm = $sport === 'all' ? '' : $sport;

// ---- Fetch sold cards per grade ----
$rows = [];
foreach ($grades as $g) {
    $q = trim($sportTerm . ' ' . $keywords);
    foreach ($ebay->searchSold($q, $g, $fromIso, $toIso, $SHOW) as $r) {
        if ($sport !== 'all') {
            $r['sport'] = $sports[$sport];
        }
        $rows[] = $r;
    }
}
// Most recent first, then cap.
usort($rows, fn ($a, $b) => strcmp((string)($b['sold_date'] ?? ''), (string)($a['sold_date'] ?? '')));
$rows = array_slice($rows, 0, $SHOW);

$count = count($rows);
$total = array_sum(array_map(fn ($r) => (float)$r['price'], $rows));
$avg   = $count ? $total / $count : 0.0;

// Helper to build a filter URL preserving other params.
$url = function (array $overrides) use ($sport, $keywords, $days, $grades): string {
    $p = ['sport' => $sport, 'q' => $keywords, 'days' => $days, 'grades' => $grades];
    foreach ($overrides as $k => $v) { $p[$k] = $v; }
    return '/superadmin/deals.php?' . http_build_query($p);
};

layout_header('Card Finder', 'admin');
?>
<h1>🔎 Card Finder</h1>
<p class="sub">Recently <strong>sold</strong> PSA 10 &amp; Beckett (BGS) 10 cards — filter by sport, grade, and date range.</p>

<?php if ($ebay->isMock() || $ebay->lastNotice): ?>
<div class="mock-note">
    ⚠️ <strong>Sample data.</strong> <?= e($ebay->lastNotice ?: '') ?>
    Live sold data needs eBay's <strong>Marketplace Insights API</strong> (a Limited Release — request access for your keyset at developer.ebay.com).
</div>
<?php endif; ?>

<!-- Sport tabs -->
<div class="tabs">
    <?php foreach ($sports as $key => $label): ?>
        <a class="tab<?= $sport === $key ? ' tab-active' : '' ?>" href="<?= e($url(['sport' => $key])) ?>"><?= e($label) ?></a>
    <?php endforeach; ?>
</div>

<form method="get" class="card" style="margin:14px 0 22px">
    <input type="hidden" name="sport" value="<?= e($sport) ?>">
    <div class="row">
        <div><label>Keywords (optional)</label><input name="q" value="<?= e($keywords) ?>" placeholder="e.g. Jordan, Topps Chrome, rookie"></div>
        <div><label>Sold within</label>
            <select name="days">
                <option value="1"<?= $days === 1 ? ' selected' : '' ?>>Last 24 hours</option>
                <option value="7"<?= $days === 7 ? ' selected' : '' ?>>Last 7 days</option>
                <option value="30"<?= $days === 30 ? ' selected' : '' ?>>Last 30 days</option>
            </select>
        </div>
    </div>
    <div style="margin-top:12px">
        <?php foreach ($gradeOptions as $val => $label): ?>
            <label class="checkbox" style="display:inline-flex;margin-right:18px">
                <input type="checkbox" name="grades[]" value="<?= e($val) ?>" <?= in_array($val, $grades, true) ? 'checked' : '' ?>> <?= e($label) ?>
            </label>
        <?php endforeach; ?>
    </div>
    <div style="margin-top:14px"><button class="btn btn-primary" type="submit">Find cards</button></div>
</form>

<div class="stat-grid" style="margin-bottom:22px">
    <div class="stat"><div class="stat-num"><?= $count ?></div><div class="stat-label">Shown</div></div>
    <div class="stat"><div class="stat-num"><?= money($total) ?></div><div class="stat-label">Total</div></div>
    <div class="stat"><div class="stat-num"><?= money($avg) ?></div><div class="stat-label">Avg sold</div></div>
</div>

<?php if (!$rows): ?>
    <div class="empty">No sold cards found for this filter. Try a wider date range or fewer keywords.</div>
<?php else: ?>
    <div class="deals">
        <?php foreach ($rows as $r): ?>
            <div class="deal is-deal">
                <span class="badge"><?= e($r['grade']) ?></span>
                <?php if ($r['image_url']): ?><img src="<?= e($r['image_url']) ?>" alt="" loading="lazy"><?php endif; ?>
                <div class="info">
                    <div class="title"><?= e($r['title']) ?></div>
                    <div class="price"><?= money((float)$r['price'], $r['currency']) ?></div>
                    <div class="meta">
                        <span>🗓️ sold <?= $r['sold_date'] ? e(date('M j, g:ia', strtotime($r['sold_date']))) : '—' ?></span>
                        <?php if (!empty($r['sport'])): ?><span>🏟️ <?= e($r['sport']) ?></span><?php endif; ?>
                    </div>
                    <?php if (!empty($r['item_url'])): ?>
                        <div class="actions"><a class="btn btn-primary btn-sm" href="<?= e(epn_link($r['item_url'])) ?>" target="_blank" rel="noopener">View on eBay →</a></div>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
<?php
layout_footer();
