<?php
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';
require __DIR__ . '/../src/layout.php';

use SportCard101\Auth;

Auth::requireAdmin();

// Two views: "today" = auctions that close today (UTC), the default the user
// asked for; "soon" = everything upcoming, soonest first.
$when = ($_GET['when'] ?? 'today') === 'soon' ? 'soon' : 'today';

if ($when === 'today') {
    // Everything ending today (UTC), including ones that already closed today so
    // we can show their FINAL recorded bid — the closest we get to sold data
    // without the Marketplace Insights API.
    $sql = "SELECT l.*, s.label AS search_label
            FROM listings l
            JOIN searches s ON s.id = l.search_id
            WHERE l.buying_option = 'AUCTION'
              AND l.end_time IS NOT NULL
              AND DATE(l.end_time) = UTC_DATE()
            ORDER BY l.end_time ASC
            LIMIT 80";
} else {
    $sql = "SELECT l.*, s.label AS search_label
            FROM listings l
            JOIN searches s ON s.id = l.search_id
            WHERE l.buying_option = 'AUCTION'
              AND l.end_time IS NOT NULL
              AND l.end_time > UTC_TIMESTAMP()
            ORDER BY l.end_time ASC
            LIMIT 80";
}
$auctions = $pdo->query($sql)->fetchAll();

// Pull the full bid snapshot series for these listings so we can show the
// bid-price trajectory (how high the bidding has climbed) + velocity.
$series = []; // listing_id => [ ['price'=>, 'bids'=>, 'at'=>], ... ] ascending
if ($auctions) {
    $ids = array_map(fn ($a) => (int)$a['id'], $auctions);
    $in  = implode(',', array_fill(0, count($ids), '?'));
    try {
        $stmt = $pdo->prepare(
            "SELECT listing_id, bid_count, price, snapped_at FROM bid_snapshots
             WHERE listing_id IN ($in) ORDER BY snapped_at ASC"
        );
        $stmt->execute($ids);
        foreach ($stmt->fetchAll() as $r) {
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
    if (!$snaps || count($snaps) < 1) {
        return null;
    }
    $first = $snaps[0];
    $last  = $snaps[count($snaps) - 1];
    $hours = (strtotime($last['at']) - strtotime($first['at'])) / 3600;
    $bidGain   = $last['bids'] - $first['bids'];
    $priceRise = $last['price'] - $first['price'];
    return [
        'snaps'      => count($snaps),
        'open_price' => $first['price'],
        'open_bids'  => $first['bids'],
        'last_price' => $last['price'],
        'last_bids'  => $last['bids'],
        'price_rise' => $priceRise,
        'bid_gain'   => $bidGain,
        'hours'      => $hours,
        'rate'       => $hours > 0.05 ? $bidGain / $hours : null, // bids/hr
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

layout_header('Auctions', 'admin');
?>
<h1>🔨 PSA 10 Auctions</h1>
<p class="sub">Live auctions captured by the scanner. We record the bid count and current bid <em>every scan</em>, so each card shows how high the bidding has climbed over time. eBay anonymizes bidders, so individual bids aren't available — the price trail + bid count is the reliable interest signal.</p>

<div class="tabs" style="margin-bottom:18px">
    <a class="tab<?= $when === 'today' ? ' tab-active' : '' ?>" href="?when=today">⏰ Closing today</a>
    <a class="tab<?= $when === 'soon' ? ' tab-active' : '' ?>" href="?when=soon">📅 All upcoming</a>
</div>

<form method="post" action="/superadmin/scan.php" class="inline" style="margin-bottom:8px"><?= csrf_field() ?>
    <button class="btn btn-scan" type="submit">⟳ Scan now</button></form>
<span class="sub" style="margin-left:10px">Each scan adds a bid snapshot. Run periodically (or via cron) to build a finer price trail.</span>

<?php if (!$auctions): ?>
    <div class="empty">
        <?php if ($when === 'today'): ?>
            No PSA 10 auctions ending today were captured. Add an <strong>auction</strong> search on the
            <a href="/superadmin/searches.php">AI App</a> page (Listing type = Auctions), hit <strong>Scan now</strong>,
            then check back — or view <a href="?when=soon">all upcoming</a>.
        <?php else: ?>
            No active auctions captured yet. Add an <strong>auction</strong> search on the
            <a href="/superadmin/searches.php">AI App</a> page and hit Scan now.
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
                    <?php if ($verdict): ?>
                        <div class="ai-row">
                            <span class="verdict verdict-<?= e(strtolower($verdict)) ?>"><?= e($verdict) ?></span>
                            <?php if ((int)$a['ai_confidence'] > 0): ?><span class="conf"><?= (int)$a['ai_confidence'] ?>% conf</span><?php endif; ?>
                            <?php if (!empty($a['ai_hidden_gem'])): ?><span class="gem">💎 hidden gem</span><?php endif; ?>
                        </div>
                    <?php endif; ?>
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
                        <span>· <?= e($a['search_label']) ?></span>
                    </div>

                    <?php if ($st && $st['snaps'] > 1): ?>
                        <div class="bidtrail">
                            <div class="bidtrail-head">
                                Bid trail · opened tracking at <?= money($st['open_price'], $a['currency']) ?>
                                <?php if ($st['price_rise'] > 0): ?>
                                    <span class="rise">▲ <?= money($st['price_rise'], $a['currency']) ?></span>
                                <?php endif; ?>
                            </div>
                            <div class="bidtrail-points">
                                <?php foreach (trail_points($snaps) as $i => $p): ?>
                                    <?php if ($i > 0): ?><span class="arrow">→</span><?php endif; ?>
                                    <span class="pt" title="<?= e($p['at']) ?> · <?= (int)$p['bids'] ?> bids">
                                        <?= money($p['price'], $a['currency']) ?><sup><?= (int)$p['bids'] ?></sup>
                                    </span>
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
        Each price in the bid trail is the current high bid we recorded at that scan; the small superscript is the bid count at that moment.
        <strong>📈 bids/hr</strong> is how fast bids are coming in while we've been tracking. Ended auctions show the last bid we captured before close — finer if you scan more often.
    </p>
<?php endif; ?>
<?php
layout_footer();
