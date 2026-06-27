<?php
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';
require __DIR__ . '/../src/layout.php';

use SportCard101\Auth;
use SportCard101\DealFinder;

Auth::requireAdmin();

$SPORTS     = card_sports();
$GRADE_NUMS = card_grade_nums();
$COMPANY    = 'PSA'; // this board is PSA-only
$uid = Auth::userId();

// Seed the headline PSA 10 channel for every sport so the board is never empty.
foreach ($SPORTS as $key => $meta) {
    DealFinder::ensureChannel($pdo, $uid, $key, $meta['emoji'] . ' ' . $meta['label'] . ' — PSA 10', 'PSA 10');
}

// ------------------------------------------------------------------- Filters
$DEFAULTS = ['q' => '', 'sport' => 'all', 'grade' => 'all', 'sort' => 'ending', 'show' => 'value'];
$q     = trim((string)($_GET['q'] ?? ''));
$sport = isset($_GET['sport']) && isset($SPORTS[$_GET['sport']]) ? (string)$_GET['sport'] : 'all';
$grade = in_array($_GET['grade'] ?? '', $GRADE_NUMS, true) ? (string)$_GET['grade'] : 'all';
$sorts = ['ending' => 'Ending soonest', 'bids' => 'Most bids', 'price' => 'Highest bid'];
$sort  = isset($sorts[$_GET['sort'] ?? '']) ? (string)$_GET['sort'] : 'ending';

// "Show" dropdown: value picks (auctions with an AI message) or all auctions.
// The landing page defaults to value picks across all sports, any PSA grade.
$show      = ($_GET['show'] ?? 'value') === 'all' ? 'all' : 'value';
$valueOnly = ($show === 'value');

// ------------------------------------------------------------------ Listings
$where  = ["l.buying_option = 'AUCTION'", 'l.end_time IS NOT NULL', 'l.end_time > UTC_TIMESTAMP()', "s.grade LIKE 'PSA %'"];
$params = [];
if ($valueOnly) {
    $where[] = "l.ai_verdict IN ('BUY','WATCH') AND l.ai_reason IS NOT NULL AND l.ai_reason <> ''";
}
if ($sport !== 'all') {
    $where[] = 's.keywords = ?';
    $params[] = $sport;
    if ($grade !== 'all') {
        $where[] = 's.grade = ?';
        $params[] = $COMPANY . ' ' . $grade;
    }
}
if ($q !== '') {
    $where[] = '(l.title LIKE ? OR l.ai_card LIKE ?)';
    $params[] = '%' . $q . '%';
    $params[] = '%' . $q . '%';
}

$order = match ($sort) {
    'bids'  => 'l.bid_count DESC, l.end_time ASC',
    'price' => 'l.price DESC, l.end_time ASC',
    default => 'l.end_time ASC',
};

$sql = 'SELECT l.*, s.label AS search_label, s.keywords AS sport_key, s.grade AS search_grade
        FROM listings l
        JOIN searches s ON s.id = l.search_id
        WHERE ' . implode(' AND ', $where) . '
        ORDER BY ' . $order . '
        LIMIT 120';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$auctions = $stmt->fetchAll();

// Pull the full bid snapshot series so we can show the bid-price trajectory.
$series = [];
if ($auctions) {
    $ids = array_map(fn ($a) => (int)$a['id'], $auctions);
    $in  = implode(',', array_fill(0, count($ids), '?'));
    try {
        $snap = $pdo->prepare(
            "SELECT listing_id, bid_count, price, snapped_at FROM bid_snapshots
             WHERE listing_id IN ($in) ORDER BY snapped_at ASC"
        );
        $snap->execute($ids);
        foreach ($snap->fetchAll() as $r) {
            $series[(int)$r['listing_id']][] = [
                'price' => (float)$r['price'],
                'bids'  => (int)$r['bid_count'],
                'at'    => $r['snapped_at'],
            ];
        }
    } catch (\Throwable $e) {
        // bid_snapshots table not created yet — board still works without trail.
    }
}

/** Compute interest stats from a snapshot series. */
function bid_stats(?array $snaps): ?array
{
    if (!$snaps) {
        return null;
    }
    $first = $snaps[0];
    $last  = $snaps[count($snaps) - 1];
    $hours = (strtotime($last['at']) - strtotime($first['at'])) / 3600;
    return [
        'snaps'      => count($snaps),
        'open_price' => $first['price'],
        'last_bids'  => $last['bids'],
        'price_rise' => $last['price'] - $first['price'],
        'bid_gain'   => $last['bids'] - $first['bids'],
        'rate'       => $hours > 0.05 ? ($last['bids'] - $first['bids']) / $hours : null,
    ];
}

/** Condense a series into at most $max trail points (first, spread, last). */
function trail_points(array $snaps, int $max = 6): array
{
    $n = count($snaps);
    if ($n <= $max) {
        return $snaps;
    }
    $out  = [];
    $step = ($n - 1) / ($max - 1);
    for ($i = 0; $i < $max; $i++) {
        $out[] = $snaps[(int)round($i * $step)];
    }
    return $out;
}

/** Build a board URL preserving current filters with overrides (drops defaults). */
function board_url(array $over, array $cur, array $def): string
{
    $vals = array_merge($cur, $over);
    $p = [];
    foreach (['q', 'sport', 'grade', 'sort', 'show'] as $k) {
        $v = (string)($vals[$k] ?? '');
        if ($v !== '' && $v !== ($def[$k] ?? '')) {
            $p[$k] = $v;
        }
    }
    return '/superadmin/auctions.php' . ($p ? '?' . http_build_query($p) : '');
}
$cur = ['q' => $q, 'sport' => $sport, 'grade' => $grade, 'sort' => $sort, 'show' => $show];

layout_header('Auctions', 'admin');
?>
<h1>🔨 Graded Card Auctions</h1>

<!-- Search bar -->
<form method="get" action="/superadmin/auctions.php" class="searchbar">
    <input type="search" name="q" value="<?= e($q) ?>" placeholder="Search card, player, team, keyword…" class="searchbar-input">
    <select name="sport" class="searchbar-select">
        <option value="all"<?= $sport === 'all' ? ' selected' : '' ?>>All sports</option>
        <?php foreach ($SPORTS as $key => $meta): ?>
            <option value="<?= e($key) ?>"<?= $sport === $key ? ' selected' : '' ?>><?= e($meta['emoji'] . ' ' . $meta['label']) ?></option>
        <?php endforeach; ?>
    </select>
    <select name="show" class="searchbar-select">
        <option value="value"<?= $show === 'value' ? ' selected' : '' ?>>💎 With messages</option>
        <option value="all"<?= $show === 'all' ? ' selected' : '' ?>>All auctions</option>
    </select>
    <select name="sort" class="searchbar-select">
        <?php foreach ($sorts as $val => $label): ?>
            <option value="<?= e($val) ?>"<?= $sort === $val ? ' selected' : '' ?>><?= e($label) ?></option>
        <?php endforeach; ?>
    </select>
    <button class="btn-search" type="submit">Search</button>
    <a class="btn btn-reset" href="/superadmin/auctions.php">Reset</a>
</form>

<!-- Sport chips -->
<div class="sportchips">
    <a class="sportchip<?= $sport === 'all' ? ' sportchip-on' : '' ?>" href="<?= e(board_url(['sport' => 'all', 'grade' => 'all'], $cur, $DEFAULTS)) ?>">All</a>
    <?php foreach ($SPORTS as $key => $meta): ?>
        <a class="sportchip<?= $sport === $key ? ' sportchip-on' : '' ?>" href="<?= e(board_url(['sport' => $key, 'grade' => 'all'], $cur, $DEFAULTS)) ?>"><?= e($meta['emoji'] . ' ' . $meta['label']) ?></a>
    <?php endforeach; ?>
</div>

<?php if ($sport !== 'all'): // Grade filter + scan, shown under the selected sport ?>
    <div class="gradebar">
        <span class="filterbar-label">PSA grade:</span>
        <a class="chip<?= $grade === 'all' ? ' chip-on' : '' ?>" href="<?= e(board_url(['grade' => 'all'], $cur, $DEFAULTS)) ?>">All</a>
        <?php foreach ($GRADE_NUMS as $g): ?>
            <a class="chip<?= $grade === $g ? ' chip-on' : '' ?>" href="<?= e(board_url(['grade' => $g], $cur, $DEFAULTS)) ?>"><?= e($g) ?></a>
        <?php endforeach; ?>
        <form method="post" action="/superadmin/scan.php" class="inline" style="margin-left:auto"><?= csrf_field() ?>
            <input type="hidden" name="company" value="PSA">
            <input type="hidden" name="sport" value="<?= e($sport) ?>">
            <input type="hidden" name="grade" value="<?= e($grade === 'all' ? '10' : $grade) ?>">
            <input type="hidden" name="when" value="soon">
            <button class="btn btn-scan" type="submit">⟳ Scan PSA <?= e($grade === 'all' ? '10' : $grade) ?> · <?= e($SPORTS[$sport]['label']) ?></button>
        </form>
    </div>
<?php endif; ?>

<?php if (!$auctions): ?>
    <div class="empty">
        <?php if ($valueOnly): ?>
            No PSA value picks yet — the AI hasn't flagged any under-market auctions<?= $q !== '' ? ' for “' . e($q) . '”' : '' ?>.<br>
            Scan to pull fresh auctions, or switch <strong>“With messages” → “All auctions”</strong> in the bar to see everything captured.
        <?php else: ?>
            No PSA auctions captured<?= $q !== '' ? ' for “' . e($q) . '”' : '' ?> yet.<br>
            <?php if ($sport !== 'all'): ?>Use the <strong>⟳ Scan</strong> button above<?php else: ?>Tap a sport and hit its <strong>⟳ Scan</strong> button<?php endif; ?> to pull them from eBay.
        <?php endif; ?>
    </div>
<?php else: ?>
    <div class="deals">
        <?php foreach ($auctions as $a):
            $snaps   = $series[(int)$a['id']] ?? null;
            $st      = bid_stats($snaps);
            $verdict = $a['ai_verdict'] ?? null;
            $ended   = strtotime($a['end_time']) <= time();
        ?>
            <div class="deal is-deal<?= $verdict ? ' v-' . strtolower($verdict) : '' ?><?= $ended ? ' ended' : '' ?>">
                <span class="badge<?= $ended ? ' badge-ended' : '' ?>">
                    <?= $ended ? '🏁 ended' : '⏳ ' . e(time_left($a['end_time'])) ?>
                </span>
                <?php if ($a['image_url']): ?><img src="<?= e($a['image_url']) ?>" alt="" loading="lazy"><?php endif; ?>
                <div class="info">
                    <div class="ai-row">
                        <span class="gradetag"><?= e($a['search_grade']) ?></span>
                        <?php if (!empty($SPORTS[$a['sport_key']])): ?>
                            <span class="sporttag"><?= e($SPORTS[$a['sport_key']]['emoji'] . ' ' . $SPORTS[$a['sport_key']]['label']) ?></span>
                        <?php endif; ?>
                        <?php if ($verdict): ?>
                            <span class="verdict verdict-<?= e(strtolower($verdict)) ?>"><?= e($verdict) ?></span>
                            <?php if ((int)$a['ai_confidence'] > 0): ?><span class="conf"><?= (int)$a['ai_confidence'] ?>% conf</span><?php endif; ?>
                            <?php if (!empty($a['ai_hidden_gem'])): ?><span class="gem">💎</span><?php endif; ?>
                        <?php endif; ?>
                    </div>
                    <div class="title"><?= e($a['ai_card'] ?: $a['title']) ?></div>

                    <div class="price"><?= money((float)$a['price'], $a['currency']) ?>
                        <span class="bidlabel"><?= $ended ? 'final bid' : 'current bid' ?></span>
                    </div>

                    <div class="meta">
                        <span>🔨 <strong><?= (int)$a['bid_count'] ?></strong> bids</span>
                        <?php if ($st && $st['rate'] !== null): ?>
                            <span>📈 <?= rtrim(rtrim(number_format($st['rate'], 1), '0'), '.') ?> bids/hr</span>
                        <?php elseif ($st && $st['bid_gain'] > 0): ?>
                            <span>📈 +<?= (int)$st['bid_gain'] ?> since tracked</span>
                        <?php else: ?>
                            <span class="sub">tracking…</span>
                        <?php endif; ?>
                    </div>

                    <?php if ($st && $st['snaps'] > 1): ?>
                        <div class="bidtrail">
                            <div class="bidtrail-head">
                                Bid trail · opened tracking at <?= money($st['open_price'], $a['currency']) ?>
                                <?php if ($st['price_rise'] > 0): ?><span class="rise">▲ <?= money($st['price_rise'], $a['currency']) ?></span><?php endif; ?>
                            </div>
                            <div class="bidtrail-points">
                                <?php foreach (trail_points($snaps) as $i => $p): ?>
                                    <?php if ($i > 0): ?><span class="arrow">→</span><?php endif; ?>
                                    <span class="pt" title="<?= e($p['at']) ?> · <?= (int)$p['bids'] ?> bids"><?= money($p['price'], $a['currency']) ?><sup><?= (int)$p['bids'] ?></sup></span>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($a['ai_reason'])): ?><div class="ai-reason">🤖 <?= e($a['ai_reason']) ?></div><?php endif; ?>
                    <div class="actions">
                        <a class="btn btn-primary btn-sm" href="<?= e(epn_link($a['item_url'])) ?>" target="_blank" rel="noopener"><?= $ended ? 'View on eBay →' : 'Bid on eBay →' ?></a>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<div class="rescan-foot">
    <form method="post" action="/superadmin/scan.php" class="inline"><?= csrf_field() ?>
        <input type="hidden" name="when" value="soon">
        <button class="btn" type="submit">↻ Rescan everything</button>
    </form>
    <span class="sub">Each scan records a bid snapshot and re-rates value. Run periodically (or via cron) to keep picks fresh.</span>
</div>
<?php
layout_footer();
