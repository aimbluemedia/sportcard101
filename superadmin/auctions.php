<?php
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';
require __DIR__ . '/../src/layout.php';

use SportCard101\Auth;

Auth::requireAdmin();

// Active auctions the scanner has captured, ending soonest first.
$auctions = $pdo->query(
    "SELECT l.*, s.label AS search_label
     FROM listings l
     JOIN searches s ON s.id = l.search_id
     WHERE l.buying_option = 'AUCTION'
       AND l.end_time IS NOT NULL
       AND l.end_time > UTC_TIMESTAMP()
     ORDER BY l.end_time ASC
     LIMIT 60"
)->fetchAll();

// Pull bid snapshots for these listings to compute interest / velocity.
$velocity = []; // listing_id => ['first'=>, 'last'=>, 'snaps'=>, 'hours'=>, 'rate'=>]
if ($auctions) {
    $ids = array_map(fn ($a) => (int)$a['id'], $auctions);
    $in  = implode(',', array_fill(0, count($ids), '?'));
    try {
        $stmt = $pdo->prepare(
            "SELECT listing_id, bid_count, snapped_at FROM bid_snapshots
             WHERE listing_id IN ($in) ORDER BY snapped_at ASC"
        );
        $stmt->execute($ids);
        $byListing = [];
        foreach ($stmt->fetchAll() as $r) {
            $byListing[(int)$r['listing_id']][] = $r;
        }
        foreach ($byListing as $lid => $snaps) {
            $first = $snaps[0];
            $last  = $snaps[count($snaps) - 1];
            $hours = (strtotime($last['snapped_at']) - strtotime($first['snapped_at'])) / 3600;
            $gain  = (int)$last['bid_count'] - (int)$first['bid_count'];
            $velocity[$lid] = [
                'snaps' => count($snaps),
                'gain'  => $gain,
                'hours' => $hours,
                'rate'  => $hours > 0.05 ? $gain / $hours : null,
            ];
        }
    } catch (\Throwable $e) {
        // bid_snapshots table not created yet — board still works without trend.
    }
}

layout_header('Auctions', 'admin');
?>
<h1>🔨 PSA 10 Auctions — ending soon</h1>
<p class="sub">Live auctions captured by the scanner, sorted by soonest to end. Bid count + velocity show how much interest each one is drawing.</p>

<form method="post" action="/superadmin/scan.php" class="inline" style="margin-bottom:18px"><?= csrf_field() ?>
    <button class="btn btn-scan" type="submit">⟳ Scan now</button></form>
<span class="sub" style="margin-left:10px">Each scan records a bid snapshot — run it periodically (or via cron) to build the trend.</span>

<?php if (!$auctions): ?>
    <div class="empty">
        No active auctions captured yet. Add an <strong>auction</strong> search on the
        <a href="/superadmin/searches.php">AI App</a> page (Listing type = Auctions) and hit Scan now.
    </div>
<?php else: ?>
    <div class="deals">
        <?php foreach ($auctions as $a):
            $v = $velocity[(int)$a['id']] ?? null;
            $verdict = $a['ai_verdict'] ?? null;
        ?>
            <div class="deal is-deal<?= $verdict ? ' v-' . strtolower($verdict) : '' ?>">
                <span class="badge">⏳ <?= e(time_left($a['end_time'])) ?></span>
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
                    <div class="price"><?= money((float)$a['price'], $a['currency']) ?></div>
                    <div class="meta">
                        <span>🔨 <strong><?= (int)$a['bid_count'] ?></strong> bids</span>
                        <?php if ($v && $v['rate'] !== null): ?>
                            <span>📈 <?= rtrim(rtrim(number_format($v['rate'], 1), '0'), '.') ?>/hr</span>
                        <?php elseif ($v && $v['gain'] > 0): ?>
                            <span>📈 +<?= (int)$v['gain'] ?> since tracked</span>
                        <?php else: ?>
                            <span class="sub">tracking…</span>
                        <?php endif; ?>
                        <span>· <?= e($a['search_label']) ?></span>
                    </div>
                    <?php if (!empty($a['ai_reason'])): ?><div class="ai-reason">🤖 <?= e($a['ai_reason']) ?></div><?php endif; ?>
                    <div class="actions">
                        <a class="btn btn-primary btn-sm" href="<?= e(epn_link($a['item_url'])) ?>" target="_blank" rel="noopener">Bid on eBay →</a>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
    <p class="sub" style="margin-top:14px">📈 = bids added per hour while we've been tracking it (our interest signal). eBay anonymizes bidders, so unique-bidder counts aren't available — bid count + velocity is the reliable measure.</p>
<?php endif; ?>
<?php
layout_footer();
