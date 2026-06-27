<?php
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';
require __DIR__ . '/../src/layout.php';

use SportCard101\Auth;
use SportCard101\DealFinder;

Auth::requireAdmin();

$SPORTS    = card_sports();
$COMPANIES = card_companies();
$GRADE_NUMS = card_grade_nums();
$uid = Auth::userId();

// Seed the headline channels (PSA 10 & BGS 10 across every sport) so the board
// is never empty on a fresh install. Other company/grade combos are created on
// demand when scanned. Idempotent.
foreach ($SPORTS as $key => $meta) {
    foreach (['PSA 10', 'BGS 10'] as $g) {
        DealFinder::ensureChannel($pdo, $uid, $key, $meta['emoji'] . ' ' . $meta['label'] . ' — ' . $g, $g);
    }
}

// ------------------------------------------------------------------- Filters
// Defaults: the main page lands on PSA, every sport, all upcoming, all grades.
$when    = ($_GET['when'] ?? 'soon') === 'today' ? 'today' : 'soon';
$sport   = isset($_GET['sport']) && isset($SPORTS[$_GET['sport']]) ? (string)$_GET['sport'] : 'all';
$company = ($_GET['company'] ?? 'PSA');
$company = ($company === 'all' || isset($COMPANIES[$company])) ? (string)$company : 'PSA';
$grade   = in_array($_GET['grade'] ?? '', $GRADE_NUMS, true) ? (string)$_GET['grade'] : 'all';

// Filter defaults — a value is omitted from board URLs when it matches these.
$DEFAULTS = ['when' => 'soon', 'sport' => 'all', 'company' => 'PSA', 'grade' => 'all'];

// ------------------------------------------------------------------ Listings
$where  = ["l.buying_option = 'AUCTION'", 'l.end_time IS NOT NULL'];
$params = [];
$where[] = $when === 'today' ? 'DATE(l.end_time) = UTC_DATE()' : 'l.end_time > UTC_TIMESTAMP()';
if ($sport !== 'all') {
    $where[] = 's.keywords = ?';
    $params[] = $sport;
}
// Grade is stored as "COMPANY NUM" (e.g. "PSA 10"): company = prefix, grade = suffix.
if ($company !== 'all' && $grade !== 'all') {
    $where[] = 's.grade = ?';
    $params[] = $company . ' ' . $grade;
} elseif ($company !== 'all') {
    $where[] = 's.grade LIKE ?';
    $params[] = $company . ' %';
} elseif ($grade !== 'all') {
    $where[] = 's.grade LIKE ?';
    $params[] = '% ' . $grade;
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

/**
 * Build a board URL preserving the current filters with overrides. A value is
 * dropped from the query only when it equals its default, so e.g. choosing
 * "All" companies still emits company=all to override the PSA default.
 */
function board_url(array $over, array $cur, array $def): string
{
    $vals = [
        'when'    => $over['when']    ?? $cur['when'],
        'sport'   => $over['sport']   ?? $cur['sport'],
        'company' => $over['company'] ?? $cur['company'],
        'grade'   => $over['grade']   ?? $cur['grade'],
    ];
    $p = [];
    foreach ($vals as $k => $v) {
        if ($v !== '' && $v !== $def[$k]) {
            $p[$k] = $v;
        }
    }
    return '/superadmin/auctions.php' . ($p ? '?' . http_build_query($p) : '');
}
$cur = ['when' => $when, 'sport' => $sport, 'company' => $company, 'grade' => $grade];

layout_header('Auctions', 'admin');
?>
<h1>🔨 Graded Card Auctions</h1>
<p class="sub">Live auctions across baseball, football, basketball, hockey &amp; golf. Pick a grading company and grade, scan, and we record the bid count + current bid every scan so each card shows how high the bidding has climbed.</p>

<!-- Scan a specific company + grade (+ sport). Channels are created on demand. -->
<form method="post" action="/superadmin/scan.php" class="scanpanel"><?= csrf_field() ?>
    <input type="hidden" name="when" value="<?= e($when) ?>">
    <div class="scanpanel-field">
        <label>Grading company</label>
        <select name="company">
            <?php foreach ($COMPANIES as $code => $label): ?>
                <option value="<?= e($code) ?>"<?= ($company !== 'all' ? $company : 'PSA') === $code ? ' selected' : '' ?>><?= e($label) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="scanpanel-field">
        <label>Grade</label>
        <select name="grade">
            <?php foreach ($GRADE_NUMS as $g): ?>
                <option value="<?= e($g) ?>"<?= ($grade !== 'all' ? $grade : '10') === $g ? ' selected' : '' ?>><?= e($g) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="scanpanel-field">
        <label>Sport</label>
        <select name="sport">
            <option value="all"<?= $sport === 'all' ? ' selected' : '' ?>>All sports</option>
            <?php foreach ($SPORTS as $key => $meta): ?>
                <option value="<?= e($key) ?>"<?= $sport === $key ? ' selected' : '' ?>><?= e($meta['emoji'] . ' ' . $meta['label']) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="scanpanel-field">
        <label>&nbsp;</label>
        <button class="btn btn-scan" type="submit">⟳ Scan this</button>
    </div>
</form>
<form method="post" action="/superadmin/scan.php" class="inline" style="margin:-6px 0 0"><?= csrf_field() ?>
    <input type="hidden" name="when" value="<?= e($when) ?>">
    <button class="btn" type="submit">↻ Rescan all channels</button>
</form>
<p class="sub" style="margin:2px 0 18px">PSA grades are whole numbers; BGS / SGC / CGC also use half grades (9.5). “Rescan all channels” refreshes everything already on the board.</p>

<!-- Filters -->
<div class="filterbar">
    <span class="filterbar-label">Company:</span>
    <a class="chip<?= $company === 'all' ? ' chip-on' : '' ?>" href="<?= e(board_url(['company' => 'all'], $cur, $DEFAULTS)) ?>">All</a>
    <?php foreach ($COMPANIES as $code => $label): ?>
        <a class="chip<?= $company === $code ? ' chip-on' : '' ?>" href="<?= e(board_url(['company' => $code], $cur, $DEFAULTS)) ?>"><?= e($label) ?></a>
    <?php endforeach; ?>
</div>
<div class="filterbar">
    <span class="filterbar-label">Grade:</span>
    <a class="chip<?= $grade === 'all' ? ' chip-on' : '' ?>" href="<?= e(board_url(['grade' => 'all'], $cur, $DEFAULTS)) ?>">All</a>
    <?php foreach ($GRADE_NUMS as $g): ?>
        <a class="chip<?= $grade === $g ? ' chip-on' : '' ?>" href="<?= e(board_url(['grade' => $g], $cur, $DEFAULTS)) ?>"><?= e($g) ?></a>
    <?php endforeach; ?>
    <span class="filterbar-label" style="margin-left:18px">When:</span>
    <a class="chip<?= $when === 'today' ? ' chip-on' : '' ?>" href="<?= e(board_url(['when' => 'today'], $cur, $DEFAULTS)) ?>">⏰ Closing today</a>
    <a class="chip<?= $when === 'soon' ? ' chip-on' : '' ?>" href="<?= e(board_url(['when' => 'soon'], $cur, $DEFAULTS)) ?>">📅 All upcoming</a>
</div>

<!-- Sport tabs -->
<div class="tabs" style="margin-bottom:18px">
    <a class="tab<?= $sport === 'all' ? ' tab-active' : '' ?>" href="<?= e(board_url(['sport' => 'all'], $cur, $DEFAULTS)) ?>">All sports</a>
    <?php foreach ($SPORTS as $key => $meta): ?>
        <a class="tab<?= $sport === $key ? ' tab-active' : '' ?>" href="<?= e(board_url(['sport' => $key], $cur, $DEFAULTS)) ?>"><?= e($meta['emoji'] . ' ' . $meta['label']) ?></a>
    <?php endforeach; ?>
</div>

<?php if (!$auctions): ?>
    <div class="empty">
        No auctions match this filter
        <?= $when === 'today' ? '(ending today)' : '' ?> yet.<br>
        Set the company, grade &amp; sport above and hit <strong>⟳ Scan this</strong> to pull them from eBay
        <?php if ($when === 'today'): ?>— or check <a href="<?= e(board_url(['when' => 'soon'], $cur, $DEFAULTS)) ?>">all upcoming</a>.<?php endif; ?>
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
