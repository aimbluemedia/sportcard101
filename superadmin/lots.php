<?php
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';
require __DIR__ . '/../src/layout.php';

use SportCard101\Auth;
use SportCard101\AiAnalyst;
use SportCard101\EbayClient;
use SportCard101\LotFinder;

Auth::requireAdmin();

LotFinder::ensureTable($pdo);

// ---- Scan on demand --------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $started = microtime(true);
    try {
        $ebay = new EbayClient(ebay_config($config['ebay']));
        $ai   = new AiAnalyst($config['ai']);
        $res  = LotFinder::scan($pdo, $ebay, $ai);
        $secs = round(microtime(true) - $started, 1);
        flash('success', sprintf('Lot sweep done in %ss — %d live lots found (%d new), %d AI-analyzed this pass%s.',
            $secs, $res['found'], $res['new'], $res['analyzed'],
            $ai->isMock() ? ' (AI in mock mode — add an API key for real valuations)' : ''));
    } catch (\Throwable $e) {
        flash('error', 'Lot sweep failed: ' . $e->getMessage());
    }
    redirect('/superadmin/lots.php');
}

// ---- Data ------------------------------------------------------------------
$view = ($_GET['view'] ?? 'worth') === 'all' ? 'all' : 'worth';
$where = 'end_time > UTC_TIMESTAMP()';
if ($view === 'worth') {
    $where .= " AND (ai_verdict IN ('BUY','WATCH') OR ai_verdict IS NULL)";
}
$lots = $pdo->query(
    "SELECT * FROM lots WHERE {$where}
     ORDER BY FIELD(COALESCE(ai_verdict,'?'), 'BUY','WATCH','?','PASS'), end_time ASC
     LIMIT 100"
)->fetchAll();

$pending = (int) $pdo->query('SELECT COUNT(*) FROM lots WHERE analyzed_at IS NULL AND end_time > UTC_TIMESTAMP()')->fetchColumn();

/** Compact "ends in" label. */
function lots_ends(?string $endTime): string
{
    $h = hours_until((string)$endTime);
    if ($h === null) { return '—'; }
    if ($h <= 1) { return '<strong style="color:#e05555">~' . max(1, (int)round($h * 60)) . 'm</strong>'; }
    if ($h >= 48) { return '~' . (int)round($h / 24) . 'd'; }
    return '~' . (int)round($h) . 'h';
}

layout_header('Lots', 'admin');
?>
<h1>📦 Bulk Lots</h1>
<p class="sub">Auction lots of graded cards, swept from eBay and AI-valued from their titles. The edge: sellers dumping collections rarely price per card — when the bid sits under the lot's break-up value, that's the deal. Always open the photos before bidding; titles lie.</p>

<div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin-bottom:16px">
    <form method="post" style="margin:0" onsubmit="this.querySelector('button').disabled=true;this.querySelector('button').textContent='📦 Sweeping eBay… ~20s';">
        <?= csrf_field() ?>
        <button class="btn btn-primary" type="submit" title="Searches eBay for graded-card lot auctions and AI-values the new ones">📦 Sweep for lots now</button>
    </form>
    <form method="get" class="searchbar" style="margin:0">
        <select name="view" onchange="this.form.submit()">
            <option value="worth"<?= $view === 'worth' ? ' selected' : '' ?>>Worth a look (BUY / WATCH / new)</option>
            <option value="all"<?= $view === 'all' ? ' selected' : '' ?>>All live lots</option>
        </select>
    </form>
    <?php if ($pending > 0): ?>
        <span style="color:var(--muted)"><?= $pending ?> lot<?= $pending === 1 ? '' : 's' ?> awaiting AI valuation — each sweep values up to 12 more.</span>
    <?php endif; ?>
</div>

<?php if (!$lots): ?>
    <div class="empty" style="padding:30px">
        No live lots<?= $view === 'worth' ? ' worth a look' : '' ?> yet. Hit <strong>📦 Sweep for lots now</strong> to search eBay
        <?= $view === 'worth' ? ', or switch the dropdown to “All live lots”.' : '.' ?>
    </div>
<?php else: ?>
<div class="card">
    <div style="overflow-x:auto"><table>
        <tr><th>Lot</th><th>Now</th><th>Bids</th><th>Ends</th><th>Cards</th><th>$ / card</th><th>AI est. value</th><th>Verdict</th><th></th></tr>
        <?php foreach ($lots as $l):
            $cards   = $l['est_cards'] !== null ? (int)$l['est_cards'] : null;
            $perCard = ($cards && $cards > 0) ? (float)$l['price'] / $cards : null;
            $estLow  = $l['ai_est_low'] !== null ? (float)$l['ai_est_low'] : null;
            $estHigh = $l['ai_est_high'] !== null ? (float)$l['ai_est_high'] : null;
            $under   = ($estLow !== null && $estLow > 0) ? (1 - (float)$l['price'] / $estLow) * 100 : null;
            $vColor  = match ($l['ai_verdict']) { 'BUY' => '#1d7d46', 'WATCH' => '#b8860b', 'PASS' => 'var(--muted)', default => 'var(--muted)' };
        ?>
        <tr>
            <td style="max-width:380px"><strong><?= e((string)$l['title']) ?></strong>
                <?php if ($l['ai_reason']): ?><br><small style="color:var(--muted)"><?= e((string)$l['ai_reason']) ?></small><?php endif; ?></td>
            <td>$<?= number_format((float)$l['price'], 2) ?></td>
            <td><?= $l['bid_count'] !== null ? (int)$l['bid_count'] : '—' ?></td>
            <td><?= lots_ends((string)$l['end_time']) ?></td>
            <td><?= $cards !== null ? $cards : '?' ?></td>
            <td><?= $perCard !== null ? '$' . number_format($perCard, 2) : '—' ?></td>
            <td><?php if ($estLow !== null && $estHigh !== null && $estHigh > 0): ?>
                    $<?= number_format($estLow, 0) ?>–$<?= number_format($estHigh, 0) ?>
                    <?php if ($under !== null && $under > 5): ?><br><small style="color:#1d7d46">bid <?= (int)round($under) ?>% under low est.</small><?php endif; ?>
                <?php else: ?>—<?php endif; ?></td>
            <td><strong style="color:<?= $vColor ?>"><?= $l['ai_verdict'] ? e((string)$l['ai_verdict']) : 'pending' ?></strong></td>
            <td><div style="display:flex;gap:6px">
                <a class="btn" href="<?= e(epn_link((string)$l['item_url'])) ?>" target="_blank" rel="noopener">View on eBay</a>
            </div></td>
        </tr>
        <?php endforeach; ?>
    </table></div>
    <p style="margin:12px 0 0;color:var(--muted)"><small>Estimates come from the lot TITLE only — the AI can't see the photos, and titles oversell. A BUY here means "the math looks right, go inspect," never "bid blind." Fees hit every card when you resell a broken-up lot, so the margin has to be real.</small></p>
</div>
<?php endif; ?>
<?php
layout_footer();
