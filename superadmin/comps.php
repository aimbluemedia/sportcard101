<?php
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';
require __DIR__ . '/../src/layout.php';

use SportCard101\Auth;
use SportCard101\Comps;

Auth::requireAdmin();

$SPORTS = card_sports();

// ------------------------------------------------------------------- Filters
$q     = trim((string)($_GET['q'] ?? ''));
$sport = isset($_GET['sport']) && isset($SPORTS[$_GET['sport']]) ? (string)$_GET['sport'] : 'all';
$days  = (int)($_GET['days'] ?? 180);
if (!in_array($days, [30, 90, 180, 365], true)) {
    $days = 180;
}
$sorts = ['sales' => 'Most sales', 'median' => 'Highest median', 'recent' => 'Recently sold'];
$sort  = isset($sorts[$_GET['sort'] ?? '']) ? (string)$_GET['sort'] : 'sales';

// ------------------------------------------------------------------- Fetch
$where  = ['closed_at >= (UTC_TIMESTAMP() - INTERVAL ? DAY)'];
$params = [$days];
if ($sport !== 'all') {
    $where[] = 'sport = ?';
    $params[] = $sport;
}
if ($q !== '') {
    $where[] = '(title LIKE ? OR canonical_card LIKE ?)';
    $params[] = '%' . $q . '%';
    $params[] = '%' . $q . '%';
}

$rows = [];
try {
    $stmt = $pdo->prepare(
        'SELECT sport, grade, card_key, canonical_card, title, final_price, final_bids,
                closed_at, image_url, item_url
         FROM sold_comps
         WHERE ' . implode(' AND ', $where) . '
         ORDER BY closed_at ASC
         LIMIT 8000'
    );
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
    $tableReady = true;
} catch (\Throwable $e) {
    $tableReady = false; // sold_comps not migrated yet
}

// Group into cards by (sport, grade, card_key), computing stats in PHP.
$groups = [];
foreach ($rows as $r) {
    $k = $r['sport'] . '|' . $r['grade'] . '|' . $r['card_key'];
    $groups[$k]['rows'][] = $r;
    $groups[$k]['meta']   = $r; // latest (rows are ascending → last wins below)
}

$cards = [];
foreach ($groups as $g) {
    $rs = $g['rows'];
    $prices = array_map(fn ($x) => (float)$x['final_price'], $rs);
    $bids   = array_map(fn ($x) => (int)$x['final_bids'], $rs);
    sort($prices);
    $n = count($prices);
    $median = $n % 2 ? $prices[intdiv($n, 2)] : ($prices[intdiv($n, 2) - 1] + $prices[intdiv($n, 2)]) / 2;
    // trend: older half vs newer half (rows ascending by close date)
    $trend = 0.0;
    if ($n >= 4) {
        $p = array_map(fn ($x) => (float)$x['final_price'], $rs); // time order
        $half = intdiv($n, 2);
        $o = $p; $oldH = array_slice($p, 0, $half); $newH = array_slice($p, $half);
        sort($oldH); sort($newH);
        $om = $oldH[intdiv(count($oldH), 2)];
        $nm = $newH[intdiv(count($newH), 2)];
        if ($om > 0) { $trend = round((($nm - $om) / $om) * 100, 1); }
    }
    $latest = end($rs);
    $cards[] = [
        'card'     => $latest['canonical_card'] ?: $latest['title'],
        'sport'    => $latest['sport'],
        'grade'    => $latest['grade'],
        'count'    => $n,
        'median'   => round((float)$median, 2),
        'low'      => round(min($prices), 2),
        'high'     => round(max($prices), 2),
        'avg_bids' => $bids ? round(array_sum($bids) / count($bids), 1) : 0.0,
        'last'     => $latest['closed_at'],
        'trend'    => $trend,
        'image'    => $latest['image_url'],
        'title'    => $latest['title'],
    ];
}

usort($cards, match ($sort) {
    'median' => fn ($a, $b) => $b['median'] <=> $a['median'],
    'recent' => fn ($a, $b) => strcmp((string)$b['last'], (string)$a['last']),
    default  => fn ($a, $b) => $b['count'] <=> $a['count'] ?: ($b['median'] <=> $a['median']),
});
$cards = array_slice($cards, 0, 200);

$totalSales = count($rows);

layout_header('Sold Comps', 'admin');
?>
<h1>📊 Sold Comps</h1>
<p class="sub">What tracked auctions actually closed at — your own comp database. Use it to know the going rate before you bid. <strong><?= number_format($totalSales) ?></strong> sales recorded in the last <?= (int)$days ?> days.</p>

<form method="get" action="/superadmin/comps.php" class="searchbar">
    <input type="search" name="q" value="<?= e($q) ?>" placeholder="Search card, player, set, keyword…" class="searchbar-input">
    <select name="sport" class="searchbar-select">
        <option value="all"<?= $sport === 'all' ? ' selected' : '' ?>>All sports</option>
        <?php foreach ($SPORTS as $key => $meta): ?>
            <option value="<?= e($key) ?>"<?= $sport === $key ? ' selected' : '' ?>><?= e($meta['emoji'] . ' ' . $meta['label']) ?></option>
        <?php endforeach; ?>
    </select>
    <select name="days" class="searchbar-select">
        <?php foreach ([30 => 'Last 30 days', 90 => 'Last 90 days', 180 => 'Last 180 days', 365 => 'Last year'] as $d => $lbl): ?>
            <option value="<?= $d ?>"<?= $days === $d ? ' selected' : '' ?>><?= e($lbl) ?></option>
        <?php endforeach; ?>
    </select>
    <select name="sort" class="searchbar-select">
        <?php foreach ($sorts as $val => $lbl): ?>
            <option value="<?= e($val) ?>"<?= $sort === $val ? ' selected' : '' ?>><?= e($lbl) ?></option>
        <?php endforeach; ?>
    </select>
    <button class="btn-search" type="submit">Search</button>
    <a class="btn btn-reset" href="/superadmin/comps.php">Reset</a>
</form>

<?php if (!$tableReady): ?>
    <div class="empty">
        The <code>sold_comps</code> table isn't created yet. Run <code>migrations/2026_sold_comps.sql</code>
        in phpMyAdmin, then let the scanner/cron record some closed auctions.
    </div>
<?php elseif (!$cards): ?>
    <div class="empty">
        No comps yet<?= $q !== '' ? ' for “' . e($q) . '”' : '' ?>. Comps build up automatically as tracked
        auctions close (with at least one bid). Keep the scanner/cron running and check back.
    </div>
<?php else: ?>
    <div class="comp-cards">
        <?php foreach ($cards as $c):
            $sportLabel = trim(($SPORTS[$c['sport']]['emoji'] ?? '') . ' ' . ($SPORTS[$c['sport']]['label'] ?? (string)$c['sport']));
        ?>
            <div class="comp-card">
                <div class="comp-card-top">
                    <?php if ($c['image']): ?><img src="<?= e($c['image']) ?>" alt="" loading="lazy"><?php else: ?><div class="comp-noimg">🃏</div><?php endif; ?>
                    <div class="comp-card-head">
                        <div class="comp-card-name"><?= e($c['card']) ?></div>
                        <div class="comp-card-tags">
                            <span class="gradetag"><?= e((string)$c['grade']) ?></span>
                            <?php if ($sportLabel !== ''): ?><span class="sporttag"><?= e($sportLabel) ?></span><?php endif; ?>
                        </div>
                    </div>
                </div>

                <div class="comp-median">
                    <span class="cm-val"><?= money($c['median']) ?></span>
                    <span class="cm-lbl">median sold</span>
                    <?php if ($c['trend'] > 2): ?><span class="trend-up">▲ <?= $c['trend'] ?>%</span>
                    <?php elseif ($c['trend'] < -2): ?><span class="trend-down">▼ <?= abs($c['trend']) ?>%</span><?php endif; ?>
                </div>
                <div class="comp-range">Range <strong><?= money($c['low']) ?> – <?= money($c['high']) ?></strong></div>

                <div class="comp-stats">
                    <div><span class="n"><?= (int)$c['count'] ?></span><span class="l">sales</span></div>
                    <div><span class="n"><?= $c['avg_bids'] ?></span><span class="l">avg bids</span></div>
                    <div><span class="n"><?= $c['last'] ? e(date('M j', strtotime((string)$c['last']))) : '—' ?></span><span class="l">last sold</span></div>
                </div>

                <a class="btn btn-sm comp-find" href="<?= e(epn_search_link($c['title'])) ?>" target="_blank" rel="noopener">Find live on eBay →</a>
            </div>
        <?php endforeach; ?>
    </div>
    <p class="sub" style="margin-top:16px">Median is the typical sale price for that card; “Range” is low–high; “Trend” compares the older half of sales to the newer half. A comp = the last bid we recorded before an auction closed (≥1 bid), so accuracy improves the more often the scanner runs.</p>
<?php endif; ?>
<?php
layout_footer();
