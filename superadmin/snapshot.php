<?php
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';
require __DIR__ . '/../src/layout.php';

use SportCard101\Auth;
use SportCard101\Comps;
use SportCard101\Playbook;

Auth::requireAdmin();

/*
 * Snap Shot — a real-time read of every tracked auction, scored for profit
 * using everything the system knows RIGHT NOW: live prices, our sold-comp
 * medians, bid heat/velocity, AI verdicts, and the playbook's max-bid math.
 * Pure reads — data is as fresh as the last 30-min scan.
 */

$SPORTS = card_sports();
$sport  = isset($_GET['sport']) && isset($SPORTS[$_GET['sport']]) ? (string)$_GET['sport'] : 'all';
$cfg    = Playbook::config();

// ---- Meta: how fresh is this snapshot? -------------------------------------
$lastScan   = $pdo->query('SELECT MAX(last_scanned_at) FROM searches')->fetchColumn();
$sportCond  = $sport !== 'all' ? ' AND s.keywords = ' . $pdo->quote($sport) : '';

// ---- All active tracked auctions + their comps ------------------------------
$live = $pdo->query(
    "SELECT l.id, l.ebay_item_id, l.title, l.ai_card, l.ai_verdict, l.ai_hidden_gem, l.ai_reason,
            l.price, l.bid_count, l.end_time, l.item_url, l.buying_option,
            s.keywords AS sport, s.grade AS grade
     FROM listings l JOIN searches s ON s.id = l.search_id
     WHERE l.end_time > UTC_TIMESTAMP() AND l.buying_option = 'AUCTION' AND s.grade LIKE 'PSA %'{$sportCond}
     ORDER BY l.end_time ASC LIMIT 600"
)->fetchAll();

$stats = [];
if ($live) {
    $cards = array_map(fn ($r) => ['sport' => $r['sport'], 'grade' => $r['grade'], 'key' => Comps::cardKey((string)$r['title'])], $live);
    $stats = Comps::statsForCards($pdo, $cards, 90);
}

// Score every auction with the playbook economics.
$radar = [];   // reachable profit (current price <= max bid)
foreach ($live as $r) {
    $comp = $stats[$r['sport'] . '|' . $r['grade'] . '|' . Comps::cardKey((string)$r['title'])] ?? null;
    if (!$comp || $comp['count'] < Playbook::MIN_COMPS || $comp['median'] <= 0) {
        continue;
    }
    if (($comp['high'] - $comp['low']) / $comp['median'] > 1.2) {
        continue; // mixed variations — median untrustworthy
    }
    $p = Playbook::pricing((float)$comp['median'], $cfg['margin_pct'], $cfg['per_card']);
    if ($p['max_bid'] < 5 || $p['est_net'] < 3 || (float)$r['price'] > $p['max_bid']) {
        continue;
    }
    $radar[] = [
        'row'      => $r,
        'comp'     => $comp,
        'max_bid'  => $p['max_bid'],
        'est_net'  => $p['est_net'],
        'headroom' => round($p['max_bid'] - (float)$r['price'], 2),
        'hours'    => hours_until((string)$r['end_time']) ?? 999.0,
    ];
}
usort($radar, fn ($a, $b) => $b['est_net'] <=> $a['est_net']);
$snipe = array_values(array_filter($radar, fn ($x) => $x['hours'] <= 2.0));
$radar = array_slice($radar, 0, 25);

// ---- Heating up: bid velocity over the last 3 hours -------------------------
$hot = $pdo->query(
    "SELECT l.id, l.title, l.ai_card, l.price, l.bid_count, l.end_time, l.item_url,
            s.keywords AS sport, s.grade AS grade,
            MAX(b.bid_count) - MIN(b.bid_count) AS gained
     FROM bid_snapshots b
     JOIN listings l ON l.id = b.listing_id
     JOIN searches s ON s.id = l.search_id
     WHERE b.snapped_at >= (UTC_TIMESTAMP() - INTERVAL 3 HOUR)
       AND l.end_time > UTC_TIMESTAMP(){$sportCond}
     GROUP BY l.id, l.title, l.ai_card, l.price, l.bid_count, l.end_time, l.item_url, s.keywords, s.grade
     HAVING gained >= 3
     ORDER BY gained DESC LIMIT 12"
)->fetchAll();

// ---- Hidden gems: AI-flagged weak listings still live ------------------------
$gems = $pdo->query(
    "SELECT l.title, l.ai_card, l.ai_reason, l.price, l.bid_count, l.end_time, l.item_url,
            s.keywords AS sport, s.grade AS grade
     FROM listings l JOIN searches s ON s.id = l.search_id
     WHERE l.end_time > UTC_TIMESTAMP() AND l.ai_hidden_gem = 1{$sportCond}
     ORDER BY l.end_time ASC LIMIT 10"
)->fetchAll();

// ---- Instant flips: fixed-price listings under our comp-derived max ---------
$bins = [];
$binRows = $pdo->query(
    "SELECT l.title, l.ai_card, l.price, l.item_url, s.keywords AS sport, s.grade AS grade
     FROM listings l JOIN searches s ON s.id = l.search_id
     WHERE l.buying_option <> 'AUCTION' AND (l.end_time IS NULL OR l.end_time > UTC_TIMESTAMP())
       AND s.grade LIKE 'PSA %'{$sportCond}
     ORDER BY l.last_seen_at DESC LIMIT 300"
)->fetchAll();
if ($binRows) {
    $cards = array_map(fn ($r) => ['sport' => $r['sport'], 'grade' => $r['grade'], 'key' => Comps::cardKey((string)$r['title'])], $binRows);
    $binStats = Comps::statsForCards($pdo, $cards, 90);
    foreach ($binRows as $r) {
        $comp = $binStats[$r['sport'] . '|' . $r['grade'] . '|' . Comps::cardKey((string)$r['title'])] ?? null;
        if (!$comp || $comp['count'] < Playbook::MIN_COMPS || $comp['median'] <= 0) {
            continue;
        }
        $p = Playbook::pricing((float)$comp['median'], $cfg['margin_pct'], $cfg['per_card']);
        if ((float)$r['price'] <= $p['max_bid']) {
            $bins[] = ['row' => $r, 'comp' => $comp, 'net' => round($p['resale_net'] - (float)$r['price'], 2)];
        }
    }
    usort($bins, fn ($a, $b) => $b['net'] <=> $a['net']);
    $bins = array_slice($bins, 0, 10);
}

/** Compact "ends in" label. */
function snap_ends(?string $endTime): string
{
    $h = hours_until((string)$endTime);
    if ($h === null) { return '—'; }
    if ($h <= 1) { return '<strong style="color:#e05555">~' . max(1, (int)round($h * 60)) . 'm</strong>'; }
    if ($h >= 48) { return '~' . (int)round($h / 24) . 'd'; }
    return '~' . (int)round($h) . 'h';
}

layout_header('Snap Shot', 'admin');
?>
<h1>📸 Snap Shot</h1>
<p class="sub">Real-time profit read across everything the scanner is tracking — playbook economics applied to every live auction, plus bid heat, hidden gems, and instant flips.
Data as of last scan: <strong><?= $lastScan ? e(date('M j, g:ia', strtotime((string)$lastScan))) : 'never' ?></strong>
· settings: <?= (int)$cfg['margin_pct'] ?>% margin, $<?= (int)$cfg['per_card'] ?>/card cap.</p>

<form method="get" class="searchbar" style="margin-bottom:16px">
    <select name="sport" onchange="this.form.submit()">
        <option value="all">All sports</option>
        <?php foreach ($SPORTS as $k => $m): ?>
        <option value="<?= e($k) ?>" <?= $sport === $k ? 'selected' : '' ?>><?= e($m['emoji'] . ' ' . $m['label']) ?></option>
        <?php endforeach; ?>
    </select>
</form>

<?php if ($snipe): ?>
<div class="card" style="margin-bottom:16px;border-left:4px solid #e05555">
    <h2 style="margin-top:0">⏱️ Snipe window — profitable and ending within 2 hours</h2>
    <div style="overflow-x:auto"><table>
        <tr><th>Card</th><th>Now</th><th>Bids</th><th>Ends</th><th>Comp</th><th>Max bid</th><th>Headroom</th><th>Est. net</th><th></th></tr>
        <?php foreach ($snipe as $x): $r = $x['row']; ?>
        <tr>
            <td><strong><?= e((string)($r['ai_card'] ?: $r['title'])) ?></strong></td>
            <td>$<?= number_format((float)$r['price'], 2) ?></td>
            <td><?= (int)$r['bid_count'] ?></td>
            <td><?= snap_ends((string)$r['end_time']) ?></td>
            <td>$<?= number_format((float)$x['comp']['median'], 2) ?> <small style="color:var(--muted)">(<?= (int)$x['comp']['count'] ?>)</small></td>
            <td><strong style="color:#0071e3">$<?= number_format((float)$x['max_bid'], 2) ?></strong></td>
            <td>$<?= number_format((float)$x['headroom'], 2) ?></td>
            <td style="color:#1d7d46">$<?= number_format((float)$x['est_net'], 2) ?></td>
            <td><a class="btn" href="<?= e(epn_link((string)$r['item_url'])) ?>" target="_blank" rel="noopener">View on eBay</a></td>
        </tr>
        <?php endforeach; ?>
    </table></div>
</div>
<?php endif; ?>

<div class="card" style="margin-bottom:16px">
    <h2 style="margin-top:0">💰 Profit radar — every reachable auction, best value first</h2>
    <?php if (!$radar): ?>
        <p style="margin:0;color:var(--muted)">Nothing reachable right now: no live auction is currently below its comp-derived max bid with trustworthy comps (≥<?= Playbook::MIN_COMPS ?> sales, sane spread). That changes constantly — check back after the next scan.</p>
    <?php else: ?>
    <div style="overflow-x:auto"><table>
        <tr><th>Card</th><th>Now</th><th>Bids</th><th>Ends</th><th>Comp (90d)</th><th>Trend</th><th>Max bid</th><th>Headroom</th><th>Est. net</th><th>AI</th><th></th></tr>
        <?php foreach ($radar as $x): $r = $x['row']; ?>
        <tr>
            <td><strong><?= e((string)($r['ai_card'] ?: $r['title'])) ?></strong></td>
            <td>$<?= number_format((float)$r['price'], 2) ?></td>
            <td><?= (int)$r['bid_count'] ?></td>
            <td><?= snap_ends((string)$r['end_time']) ?></td>
            <td>$<?= number_format((float)$x['comp']['median'], 2) ?> <small style="color:var(--muted)">(<?= (int)$x['comp']['count'] ?>)</small></td>
            <td><?php $tr = (float)$x['comp']['trend'];
                echo $tr > 5 ? '<span style="color:#1d7d46">▲ ' . $tr . '%</span>'
                    : ($tr < -5 ? '<span style="color:#e05555">▼ ' . $tr . '%</span>' : '<span style="color:var(--muted)">→</span>'); ?></td>
            <td><strong style="color:#0071e3">$<?= number_format((float)$x['max_bid'], 2) ?></strong></td>
            <td>$<?= number_format((float)$x['headroom'], 2) ?></td>
            <td style="color:#1d7d46">$<?= number_format((float)$x['est_net'], 2) ?></td>
            <td><?= $r['ai_verdict'] ? e((string)$r['ai_verdict']) : '—' ?><?= (int)$r['ai_hidden_gem'] === 1 ? ' 💎' : '' ?></td>
            <td><a class="btn" href="<?= e(epn_link((string)$r['item_url'])) ?>" target="_blank" rel="noopener">View on eBay</a></td>
        </tr>
        <?php endforeach; ?>
    </table></div>
    <?php endif; ?>
</div>

<div class="card" style="margin-bottom:16px">
    <h2 style="margin-top:0">🔥 Heating up — bids gained in the last 3 hours</h2>
    <?php if (!$hot): ?>
        <p style="margin:0;color:var(--muted)">No auction has gained 3+ bids in the last 3 hours. Quiet market right now.</p>
    <?php else: ?>
    <div style="overflow-x:auto"><table>
        <tr><th>Card</th><th>Bids gained (3h)</th><th>Total bids</th><th>Now</th><th>Ends</th><th></th></tr>
        <?php foreach ($hot as $r): ?>
        <tr>
            <td><strong><?= e((string)($r['ai_card'] ?: $r['title'])) ?></strong></td>
            <td><strong style="color:#e05555">+<?= (int)$r['gained'] ?></strong></td>
            <td><?= (int)$r['bid_count'] ?></td>
            <td>$<?= number_format((float)$r['price'], 2) ?></td>
            <td><?= snap_ends((string)$r['end_time']) ?></td>
            <td><a class="btn" href="<?= e(epn_link((string)$r['item_url'])) ?>" target="_blank" rel="noopener">View on eBay</a></td>
        </tr>
        <?php endforeach; ?>
    </table></div>
    <p style="margin:10px 0 0;color:var(--muted)"><small>Accelerating bids = demand signal. Don't chase these — use them to learn which players/sets are hot, then hunt the same card where nobody's looking yet.</small></p>
    <?php endif; ?>
</div>

<?php if ($bins): ?>
<div class="card" style="margin-bottom:16px;border-left:4px solid #3aa66a">
    <h2 style="margin-top:0">🛒 Instant flips — Buy It Now priced under our max bid</h2>
    <div style="overflow-x:auto"><table>
        <tr><th>Card</th><th>BIN price</th><th>Comp (90d)</th><th>Est. net if flipped</th><th></th></tr>
        <?php foreach ($bins as $x): $r = $x['row']; ?>
        <tr>
            <td><strong><?= e((string)($r['ai_card'] ?: $r['title'])) ?></strong></td>
            <td>$<?= number_format((float)$r['price'], 2) ?></td>
            <td>$<?= number_format((float)$x['comp']['median'], 2) ?> <small style="color:var(--muted)">(<?= (int)$x['comp']['count'] ?>)</small></td>
            <td style="color:#1d7d46"><strong>$<?= number_format((float)$x['net'], 2) ?></strong></td>
            <td><a class="btn" href="<?= e(epn_link((string)$r['item_url'])) ?>" target="_blank" rel="noopener">View on eBay</a></td>
        </tr>
        <?php endforeach; ?>
    </table></div>
    <p style="margin:10px 0 0;color:var(--muted)"><small>No auction to wait for — if the comp holds up on inspection, these are buyable right now. Fastest-moving section; verify before it's gone.</small></p>
</div>
<?php endif; ?>

<?php if ($gems): ?>
<div class="card" style="margin-bottom:16px">
    <h2 style="margin-top:0">💎 Hidden gems — weak listings the AI flagged</h2>
    <div style="overflow-x:auto"><table>
        <tr><th>Card</th><th>Why it's overlooked</th><th>Now</th><th>Bids</th><th>Ends</th><th></th></tr>
        <?php foreach ($gems as $r): ?>
        <tr>
            <td><strong><?= e((string)($r['ai_card'] ?: $r['title'])) ?></strong></td>
            <td><small><?= e((string)$r['ai_reason']) ?></small></td>
            <td>$<?= number_format((float)$r['price'], 2) ?></td>
            <td><?= (int)$r['bid_count'] ?></td>
            <td><?= snap_ends((string)$r['end_time']) ?></td>
            <td><a class="btn" href="<?= e(epn_link((string)$r['item_url'])) ?>" target="_blank" rel="noopener">View on eBay</a></td>
        </tr>
        <?php endforeach; ?>
    </table></div>
</div>
<?php endif; ?>
<?php
layout_footer();
