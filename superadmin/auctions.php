<?php
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';
require __DIR__ . '/../src/layout.php';

use SportCard101\Auth;

Auth::requireAdmin();

// ------------------------------------------------------------------ Channels
// The board is organised into sport × grade "channels". Each is just a saved
// AUCTION search whose keywords = the sport key, so we can scan and filter per
// sport without any extra schema.
$SPORTS = [
    'baseball'   => ['label' => 'Baseball',   'emoji' => '⚾'],
    'football'   => ['label' => 'Football',   'emoji' => '🏈'],
    'basketball' => ['label' => 'Basketball', 'emoji' => '🏀'],
    'hockey'     => ['label' => 'Hockey',     'emoji' => '🏒'],
    'golf'       => ['label' => 'Golf',       'emoji' => '⛳'],
];
$GRADES = ['PSA 10', 'BGS 10'];

// Auto-create any missing channels for this admin (idempotent, runs each load).
$uid = Auth::userId();
$existing = [];
$stmt = $pdo->prepare('SELECT keywords, grade FROM searches WHERE user_id = ? AND buying_option = ?');
$stmt->execute([$uid, 'AUCTION']);
foreach ($stmt->fetchAll() as $r) {
    $existing[$r['keywords'] . '|' . $r['grade']] = true;
}
$insert = $pdo->prepare(
    'INSERT INTO searches (user_id, label, keywords, grade, buying_option, threshold_pct, active)
     VALUES (?, ?, ?, ?, ?, ?, 1)'
);
foreach ($SPORTS as $key => $meta) {
    foreach ($GRADES as $g) {
        if (isset($existing[$key . '|' . $g])) {
            continue;
        }
        $label = $meta['emoji'] . ' ' . $meta['label'] . ' — ' . $g;
        $insert->execute([$uid, $label, $key, $g, 'AUCTION', 25]);
    }
}

// ------------------------------------------------------------------- Filters
$when  = ($_GET['when'] ?? 'today') === 'soon' ? 'soon' : 'today';
$sport = isset($_GET['sport']) && isset($SPORTS[$_GET['sport']]) ? (string)$_GET['sport'] : 'all';
$grade = in_array($_GET['grade'] ?? '', $GRADES, true) ? (string)$_GET['grade'] : 'all';

// ------------------------------------------------------------------ Listings
$where  = ["l.buying_option = 'AUCTION'", 'l.end_time IS NOT NULL'];
$params = [];
if ($when === 'today') {
    // Everything ending today (UTC), incl. ones already closed today so their
    // final recorded bid shows — closest to sold data without Insights.
    $where[] = 'DATE(l.end_time) = UTC_DATE()';
} else {
    $where[] = 'l.end_time > UTC_TIMESTAMP()';
}
if ($sport !== 'all') {
    $where[] = 's.keywords = ?';
    $params[] = $sport;
}
if ($grade !== 'all') {
    $where[] = 's.grade = ?';
    $params[] = $grade;
}

$sql = 'SELECT l.*, s.label AS search_label, s.keywords AS sport_key, s.grade AS search_grade
        FROM listings l
        JOIN searches s ON s.id = l.search_id
        WHERE ' . implode(' AND ', $where) . '
        ORDER BY l.end_time ASC
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

/** Build a board URL preserving the current filters with overrides. */
function board_url(array $over, string $when, string $sport, string $grade): string
{
    $p = array_filter([
        'when'  => $over['when']  ?? $when,
        'sport' => $over['sport'] ?? $sport,
        'grade' => $over['grade'] ?? $grade,
    ], fn ($v) => $v !== '' && $v !== 'all');
    return '/superadmin/auctions.php' . ($p ? '?' . http_build_query($p) : '');
}

$sportLabel = $sport === 'all' ? 'all sports' : ($SPORTS[$sport]['emoji'] . ' ' . $SPORTS[$sport]['label']);

layout_header('Auctions', 'admin');
?>
<h1>🔨 PSA 10 &amp; BGS 10 Auctions</h1>
<p class="sub">Live auctions across baseball, football, basketball, hockey &amp; golf. We record the bid count and current bid <em>every scan</em>, so each card shows how high the bidding has climbed. eBay anonymizes bidders, so the price trail + bid count is the interest signal.</p>

<!-- Per-sport scan buttons -->
<div class="scanbar">
    <span class="scanbar-label">⟳ Scan now:</span>
    <form method="post" action="/superadmin/scan.php" class="inline"><?= csrf_field() ?>
        <input type="hidden" name="when" value="<?= e($when) ?>">
        <input type="hidden" name="grade" value="<?= e($grade === 'all' ? '' : $grade) ?>">
        <button class="btn btn-scan" type="submit">All sports</button>
    </form>
    <?php foreach ($SPORTS as $key => $meta): ?>
        <form method="post" action="/superadmin/scan.php" class="inline"><?= csrf_field() ?>
            <input type="hidden" name="when" value="<?= e($when) ?>">
            <input type="hidden" name="sport" value="<?= e($key) ?>">
            <input type="hidden" name="grade" value="<?= e($grade === 'all' ? '' : $grade) ?>">
            <button class="btn" type="submit"><?= e($meta['emoji'] . ' ' . $meta['label']) ?></button>
        </form>
    <?php endforeach; ?>
</div>
<p class="sub" style="margin:6px 0 18px">Each scan records a bid snapshot for every auction it finds. Run periodically (or via cron) to build a finer price trail. Scanning respects the grade filter below.</p>

<!-- Grade filter -->
<div class="filterbar">
    <span class="filterbar-label">Grade:</span>
    <a class="chip<?= $grade === 'all' ? ' chip-on' : '' ?>" href="<?= e(board_url(['grade' => 'all'], $when, $sport, $grade)) ?>">Both</a>
    <a class="chip<?= $grade === 'PSA 10' ? ' chip-on' : '' ?>" href="<?= e(board_url(['grade' => 'PSA 10'], $when, $sport, $grade)) ?>">PSA 10</a>
    <a class="chip<?= $grade === 'BGS 10' ? ' chip-on' : '' ?>" href="<?= e(board_url(['grade' => 'BGS 10'], $when, $sport, $grade)) ?>">BGS 10</a>
    <span class="filterbar-label" style="margin-left:18px">When:</span>
    <a class="chip<?= $when === 'today' ? ' chip-on' : '' ?>" href="<?= e(board_url(['when' => 'today'], $when, $sport, $grade)) ?>">⏰ Closing today</a>
    <a class="chip<?= $when === 'soon' ? ' chip-on' : '' ?>" href="<?= e(board_url(['when' => 'soon'], $when, $sport, $grade)) ?>">📅 All upcoming</a>
</div>

<!-- Sport tabs -->
<div class="tabs" style="margin-bottom:18px">
    <a class="tab<?= $sport === 'all' ? ' tab-active' : '' ?>" href="<?= e(board_url(['sport' => 'all'], $when, $sport, $grade)) ?>">All sports</a>
    <?php foreach ($SPORTS as $key => $meta): ?>
        <a class="tab<?= $sport === $key ? ' tab-active' : '' ?>" href="<?= e(board_url(['sport' => $key], $when, $sport, $grade)) ?>"><?= e($meta['emoji'] . ' ' . $meta['label']) ?></a>
    <?php endforeach; ?>
</div>

<?php if (!$auctions): ?>
    <div class="empty">
        No <?= e($sportLabel) ?> auctions
        <?= $grade === 'all' ? '' : '(' . e($grade) . ') ' ?>
        <?= $when === 'today' ? 'ending today' : 'upcoming' ?> captured yet.<br>
        Hit a <strong>Scan now</strong> button above to pull them from eBay
        <?php if ($when === 'today'): ?>— or check <a href="<?= e(board_url(['when' => 'soon'], $when, $sport, $grade)) ?>">all upcoming</a>.<?php endif; ?>
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
    <p class="sub" style="margin-top:14px">
        Each price in the bid trail is the current high bid recorded at that scan; the superscript is the bid count at that moment.
        <strong>📈 bids/hr</strong> = how fast bids arrive while we track. Ended auctions show the last bid captured before close — finer if you scan more often.
    </p>
<?php endif; ?>
<?php
layout_footer();
