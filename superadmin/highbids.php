<?php
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';
require __DIR__ . '/../src/layout.php';

use SportCard101\Auth;
use SportCard101\Comps;
use SportCard101\Demand;

Auth::requireAdmin();

$SPORTS = card_sports();

// Filters: minimum bid count (default 30), sport, view tab, sort, page.
$min   = isset($_GET['min']) ? max(1, (int)$_GET['min']) : 30;
$sport = isset($_GET['sport']) && isset($SPORTS[$_GET['sport']]) ? (string)$_GET['sport'] : 'all';
$tab   = in_array($_GET['tab'] ?? 'closed', ['closed', 'best'], true) ? (string)$_GET['tab'] : 'closed';
$sort  = isset(Demand::SORTS[$_GET['sort'] ?? '']) ? (string)$_GET['sort'] : 'recent';
$page  = max(1, (int)($_GET['page'] ?? 1));
$perPage = 48;

/** Link back to this page with some params changed. */
function hb_url(array $changes = []): string
{
    $p = array_merge([
        'min'   => $_GET['min']   ?? null,
        'sport' => $_GET['sport'] ?? null,
        'tab'   => $_GET['tab']   ?? null,
        'sort'  => $_GET['sort']  ?? null,
        'page'  => $_GET['page']  ?? null,
        'card'  => $_GET['card']  ?? null,
        'cs'    => $_GET['cs']    ?? null,
        'cg'    => $_GET['cg']    ?? null,
    ], $changes);
    $p = array_filter($p, fn ($v) => $v !== null && $v !== '');
    return '/superadmin/highbids.php' . ($p ? '?' . http_build_query($p) : '');
}

/** Link to one card's demand profile. */
function hb_profile_url(?string $sport, ?string $grade, ?string $cardKey): string
{
    return '/superadmin/highbids.php?' . http_build_query([
        'card' => (string)$cardKey, 'cs' => (string)$sport, 'cg' => (string)$grade,
    ]);
}

// ---- Per-card demand profile (its own view) ------------------------------
$cardKey = trim((string)($_GET['card'] ?? ''));
if ($cardKey !== '') {
    $cs = ($_GET['cs'] ?? '') !== '' ? (string)$_GET['cs'] : null;
    $cg = ($_GET['cg'] ?? '') !== '' ? (string)$_GET['cg'] : null;
    $profile = Demand::cardProfile($pdo, $cs, $cg, $cardKey);

    layout_header('Card demand', 'admin');
    echo '<p style="margin:0 0 6px"><a href="' . e(hb_url(['card' => null, 'cs' => null, 'cg' => null])) . '">‹ Back to High Bids</a></p>';

    if (!$profile) {
        echo '<div class="empty" style="padding:40px">No recorded sales for that card.</div>';
        layout_footer();
        return;
    }

    $s        = $profile['summary'];
    $sales    = $profile['sales'];
    $maxPrice = max(1.0, (float)$s['high']);
    $cur      = $s['currency'];
    ?>
    <h1>📈 <?= e((string)$s['card']) ?></h1>
    <p class="sub"><?= e(trim(($SPORTS[$cs]['label'] ?? (string)$cs) . ' · ' . (string)$cg)) ?>
        · every sale we've recorded, oldest first</p>

    <div class="card" style="margin-bottom:16px">
        <div style="display:flex;gap:26px;flex-wrap:wrap;align-items:flex-start">
            <?php if ($s['image_url']): ?>
                <img src="<?= e((string)$s['image_url']) ?>" alt="" loading="lazy" style="width:90px;border-radius:8px">
            <?php endif; ?>
            <div><small style="color:var(--muted)">Recorded sales</small><br><strong style="font-size:1.3rem"><?= (int)$s['count'] ?></strong></div>
            <div><small style="color:var(--muted)">Median price</small><br><strong style="font-size:1.3rem"><?= e(money((float)$s['median'], $cur)) ?></strong></div>
            <div><small style="color:var(--muted)">Range</small><br><strong style="font-size:1.1rem"><?= e(money((float)$s['low'], $cur)) ?> – <?= e(money((float)$s['high'], $cur)) ?></strong></div>
            <div><small style="color:var(--muted)">Avg bids</small><br><strong style="font-size:1.3rem;color:var(--red)">🔨 <?= e((string)$s['avg_bids']) ?></strong>
                <small style="color:var(--muted)">peak <?= (int)$s['max_bids'] ?></small></div>
            <div><small style="color:var(--muted)">Price trend</small><br>
                <?php if ($s['trend'] === null): ?><strong style="font-size:1.1rem;color:var(--muted)">—</strong><small style="color:var(--muted)"> needs 4+ sales</small>
                <?php else: $t = (float)$s['trend']; ?>
                    <strong style="font-size:1.3rem;color:<?= $t > 5 ? '#1d7d46' : ($t < -5 ? '#e05555' : 'var(--muted)') ?>">
                        <?= $t > 0 ? '▲ +' : ($t < 0 ? '▼ ' : '→ ') ?><?= e((string)$t) ?>%</strong>
                <?php endif; ?></div>
            <div><small style="color:var(--muted)">One comes up every</small><br><strong style="font-size:1.3rem"><?= $s['gap_days'] !== null ? e((string)$s['gap_days']) . ' days' : '—' ?></strong></div>
        </div>
        <p style="margin:12px 0 0;color:var(--muted)"><small>Trend compares the median of the older half of these sales against the newer half. "One comes up every" is the average gap between recorded sales — the practical measure of how often you get a shot at this card.</small></p>
        <p style="margin:12px 0 0;display:flex;gap:8px;flex-wrap:wrap">
            <a class="btn btn-primary" href="<?= e(epn_search_link((string)$s['title'])) ?>" target="_blank" rel="noopener">Find one on eBay →</a>
            <a class="btn" href="<?= e(ebay_sold_link((string)$s['card'])) ?>" target="_blank" rel="noopener">Recent sold prices ›</a>
        </p>
    </div>

    <div class="card">
        <h2 style="margin-top:0">Sale history (<?= count($sales) ?>)</h2>
        <div style="overflow-x:auto"><table>
            <tr><th>Closed</th><th>Price</th><th>Bids</th><th style="width:45%">Relative price</th><th></th></tr>
            <?php foreach (array_reverse($sales) as $sale):
                $pct = max(2, (int)round(((float)$sale['final_price'] / $maxPrice) * 100)); ?>
            <tr>
                <td style="white-space:nowrap"><?= e(date('M j, Y', strtotime((string)$sale['closed_at']))) ?></td>
                <td style="white-space:nowrap"><strong><?= e(money((float)$sale['final_price'], $sale['currency'])) ?></strong></td>
                <td style="white-space:nowrap">🔨 <?= (int)$sale['final_bids'] ?></td>
                <td><div style="background:var(--panel-2);border-radius:5px;height:14px;width:100%">
                    <div style="background:var(--accent);height:14px;border-radius:5px;width:<?= $pct ?>%"></div></div></td>
                <td><a class="btn btn-sm" href="<?= e(epn_link((string)$sale['item_url'])) ?>" target="_blank" rel="noopener">Listing</a></td>
            </tr>
            <?php endforeach; ?>
        </table></div>
    </div>
    <?php
    layout_footer();
    return;
}

// ---- Live auctions drawing heavy interest --------------------------------
$where  = ["l.buying_option = 'AUCTION'", 'l.bid_count > ?', 'l.end_time > UTC_TIMESTAMP()'];
$params = [$min];
if ($sport !== 'all') { $where[] = 's.keywords = ?'; $params[] = $sport; }
$sql = 'SELECT l.*, s.keywords AS sport_key, s.grade AS search_grade
        FROM listings l JOIN searches s ON s.id = l.search_id
        WHERE ' . implode(' AND ', $where) . '
        ORDER BY l.bid_count DESC, l.end_time ASC LIMIT 100';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$live = $stmt->fetchAll();

// Comp lookup for live cards (batched).
$compStats = [];
if ($live) {
    $cards = array_map(fn ($a) => ['sport' => $a['sport_key'], 'grade' => $a['search_grade'], 'key' => Comps::cardKey((string)$a['title'])], $live);
    try { $compStats = Comps::statsForCards($pdo, $cards); } catch (\Throwable $e) {}
}

// ---- Archive-wide figures + the selected tab's data ----------------------
$archive     = Demand::archiveStats($pdo, $min);
$closedTotal = Demand::closedCount($pdo, $min, $sport);
$pages       = max(1, (int)ceil($closedTotal / $perPage));
$page        = min($page, $pages);

$closed = $contested = $topBids = $topPrice = [];
if ($tab === 'closed') {
    $closed = Demand::closedPage($pdo, $min, $sport, $sort, $page, $perPage);
} else {
    $contested = Demand::mostContested($pdo, $min, $sport, 40);
    $topBids   = Demand::recordHolders($pdo, 'bids', $sport, 15);
    $topPrice  = Demand::recordHolders($pdo, 'price', $sport, 15);
}

/** Render one archived sale as a comp card. */
function hb_sale_card(array $c, array $SPORTS): void
{
    $sportLabel = trim(($SPORTS[$c['sport']]['emoji'] ?? '') . ' ' . ($SPORTS[$c['sport']]['label'] ?? (string)$c['sport']));
    ?>
    <div class="comp-card">
        <div class="comp-card-top">
            <?php if ($c['image_url']): ?><img src="<?= e($c['image_url']) ?>" alt="" loading="lazy"><?php else: ?><div class="comp-noimg">🃏</div><?php endif; ?>
            <div class="comp-card-head">
                <div class="comp-card-name"><?= e($c['canonical_card'] ?: $c['title']) ?></div>
                <div class="comp-card-tags">
                    <span class="gradetag"><?= e((string)$c['grade']) ?></span>
                    <?php if ($sportLabel !== ''): ?><span class="sporttag"><?= e($sportLabel) ?></span><?php endif; ?>
                </div>
            </div>
        </div>
        <div class="comp-median">
            <span class="cm-val"><?= money((float)$c['final_price'], $c['currency']) ?></span>
            <span class="cm-lbl">sold</span>
            <span class="trend-down" style="color:var(--red)">🔥 <?= (int)$c['final_bids'] ?> bids</span>
        </div>
        <div class="comp-range">Closed <?= $c['closed_at'] ? e(date('M j, Y', strtotime((string)$c['closed_at']))) : '—' ?></div>
        <div style="display:flex;gap:6px;flex-wrap:wrap">
            <?php if (!empty($c['card_key'])): ?>
                <a class="btn btn-sm" href="<?= e(hb_profile_url($c['sport'], $c['grade'], $c['card_key'])) ?>">History</a>
            <?php endif; ?>
            <a class="btn btn-sm comp-find" href="<?= e(epn_search_link($c['title'])) ?>" target="_blank" rel="noopener">Find similar →</a>
        </div>
    </div>
    <?php
}

layout_header('High Bids', 'admin');
?>
<h1>🔥 High Bids</h1>
<p class="sub">Cards drawing heavy interest — auctions with more than <strong><?= (int)$min ?></strong> bids. High bid counts signal strong demand (eBay hides bidder identities, so this is the bid count, not unique bidders).</p>

<?php if ($archive['total'] > 0): ?>
<div class="card" style="margin-bottom:16px;padding:12px 16px">
    <strong>Your archive:</strong>
    <?= number_format($archive['total']) ?> recorded sales across <?= number_format($archive['cards']) ?> distinct cards
    · <strong><?= number_format($archive['hot']) ?></strong> of them drew <?= (int)$min ?>+ bids
    <?php if ($archive['first']): ?>
        · collected since <?= e(date('M j, Y', strtotime((string)$archive['first']))) ?>
    <?php endif; ?>
    <br><span style="color:var(--muted)"><small>Nothing is ever deleted — every tracked auction that closed with a bid is permanently archived, so the leaderboards below are true all-time figures.</small></span>
</div>
<?php endif; ?>

<form method="get" action="/superadmin/highbids.php" class="searchbar">
    <label class="tb-check" style="cursor:default">Min bids
        <input name="min" type="number" min="1" value="<?= (int)$min ?>" style="width:70px;background:transparent;border:none;color:var(--text);font-weight:800">
    </label>
    <select name="sport" class="searchbar-select">
        <option value="all"<?= $sport === 'all' ? ' selected' : '' ?>>All sports</option>
        <?php foreach ($SPORTS as $key => $meta): ?>
            <option value="<?= e($key) ?>"<?= $sport === $key ? ' selected' : '' ?>><?= e($meta['emoji'] . ' ' . $meta['label']) ?></option>
        <?php endforeach; ?>
    </select>
    <input type="hidden" name="tab" value="<?= e($tab) ?>">
    <button class="btn-search" type="submit">Apply</button>
</form>

<h2>🔴 Live now <span class="sub" style="font-weight:400">(<?= count($live) ?>)</span></h2>
<?php if (!$live): ?>
    <div class="empty">No live auctions over <?= (int)$min ?> bids right now. Keep the scanner/cron running — hot auctions land here as they heat up.</div>
<?php else: ?>
    <div class="deals">
        <?php foreach ($live as $a):
            $ck   = Comps::cardKey((string)$a['title']);
            $comp = $compStats[$a['sport_key'] . '|' . $a['search_grade'] . '|' . $ck] ?? null;
        ?>
            <div class="deal is-deal">
                <span class="badge">⏳ <?= e(time_left($a['end_time'])) ?></span>
                <span class="snipe-badge" style="background:var(--red);color:#fff">🔥 <?= (int)$a['bid_count'] ?> bids</span>
                <?php if ($a['image_url']): ?><img src="<?= e($a['image_url']) ?>" alt="" loading="lazy"><?php endif; ?>
                <div class="info">
                    <div class="ai-row">
                        <span class="gradetag"><?= e($a['search_grade']) ?></span>
                        <?php if (!empty($SPORTS[$a['sport_key']])): ?><span class="sporttag"><?= e($SPORTS[$a['sport_key']]['emoji'] . ' ' . $SPORTS[$a['sport_key']]['label']) ?></span><?php endif; ?>
                    </div>
                    <div class="title"><?= e($a['ai_card'] ?: $a['title']) ?></div>
                    <div class="price"><?= money((float)$a['price'], $a['currency']) ?> <span class="bidlabel">current bid</span></div>
                    <div class="meta">
                        <span>🔨 <strong><?= (int)$a['bid_count'] ?></strong> bids</span>
                        <?php if ($comp): ?><span>📊 comp <?= money((float)$comp['median'], $a['currency']) ?></span><?php endif; ?>
                    </div>
                    <div class="actions"><a class="btn btn-primary btn-sm" href="<?= e(epn_link($a['item_url'])) ?>" target="_blank" rel="noopener">Bid on eBay →</a></div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<!-- Archive tabs -->
<div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin:28px 0 14px">
    <a class="btn<?= $tab === 'closed' ? ' btn-primary' : '' ?>" href="<?= e(hb_url(['tab' => 'closed', 'page' => null])) ?>">🏁 Archived sales (<?= number_format($closedTotal) ?>)</a>
    <a class="btn<?= $tab === 'best' ? ' btn-primary' : '' ?>" href="<?= e(hb_url(['tab' => 'best', 'page' => null])) ?>">🏆 All-time leaders</a>
</div>

<?php if ($tab === 'closed'): ?>

    <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-bottom:14px">
        <span style="color:var(--muted)">Sort:</span>
        <?php foreach (Demand::SORTS as $key => [$label, $_]): ?>
            <a class="btn btn-sm<?= $sort === $key ? ' btn-primary' : '' ?>" href="<?= e(hb_url(['sort' => $key, 'page' => null])) ?>"><?= e($label) ?></a>
        <?php endforeach; ?>
    </div>

    <?php if (!$closed): ?>
        <div class="empty">No closed sales with <?= (int)$min ?>+ bids recorded yet. These build up as tracked auctions close — lower "Min bids" to widen the net.</div>
    <?php else: ?>
        <p class="sub">Showing <?= number_format(($page - 1) * $perPage + 1) ?>–<?= number_format(min($page * $perPage, $closedTotal)) ?> of <?= number_format($closedTotal) ?></p>
        <div class="comp-cards">
            <?php foreach ($closed as $c) { hb_sale_card($c, $SPORTS); } ?>
        </div>

        <?php if ($pages > 1): ?>
        <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;justify-content:center;margin-top:22px">
            <?php if ($page > 1): ?><a class="btn" href="<?= e(hb_url(['page' => $page - 1])) ?>">‹ Previous</a><?php endif; ?>
            <span style="color:var(--muted)">Page <?= $page ?> of <?= number_format($pages) ?></span>
            <?php if ($page < $pages): ?><a class="btn" href="<?= e(hb_url(['page' => $page + 1])) ?>">Next ›</a><?php endif; ?>
        </div>
        <?php endif; ?>
    <?php endif; ?>

<?php else: ?>

    <div class="card" style="margin-bottom:16px">
        <h2 style="margin-top:0">🥇 Most contested cards</h2>
        <p class="sub" style="margin-bottom:12px">Ranked by how many times each card has sold with <?= (int)$min ?>+ bids — repeat demand, not one-off spikes. These are the cards that reliably find buyers, so they're the safest things to buy when one turns up underpriced.</p>
        <?php if (!$contested): ?>
            <div class="empty" style="padding:30px">No card has drawn <?= (int)$min ?>+ bids more than once yet. Lower "Min bids" above, or give the archive more time.</div>
        <?php else: ?>
        <div style="overflow-x:auto"><table>
            <tr><th>#</th><th>Card</th><th>Hot sales</th><th>Avg bids</th><th>Best</th><th>Median price</th><th>Last sold</th><th></th></tr>
            <?php foreach ($contested as $i => $c): ?>
            <tr>
                <td style="color:var(--muted)"><?= $i + 1 ?></td>
                <td style="max-width:320px"><strong><a href="<?= e(hb_profile_url($c['sport'], $c['grade'], $c['card_key'])) ?>"><?= e($c['canonical_card'] ?: $c['title']) ?></a></strong><br>
                    <small style="color:var(--muted)"><?= e(trim(($SPORTS[$c['sport']]['label'] ?? (string)$c['sport']) . ' · ' . (string)$c['grade'])) ?></small></td>
                <td style="white-space:nowrap"><strong style="color:var(--red)"><?= (int)$c['hot_sales'] ?></strong>
                    <?php if (!empty($c['total_sales'])): ?><small style="color:var(--muted)"> / <?= (int)$c['total_sales'] ?> total</small><?php endif; ?></td>
                <td style="white-space:nowrap">🔨 <?= number_format((float)$c['avg_bids'], 1) ?></td>
                <td style="white-space:nowrap"><?= (int)$c['max_bids'] ?></td>
                <td style="white-space:nowrap"><?= $c['median_price'] !== null
                    ? '<strong>' . e(money((float)$c['median_price'], $c['currency'])) . '</strong>'
                    : e(money((float)$c['avg_price'], $c['currency'])) . ' <small style="color:var(--muted)">avg</small>' ?></td>
                <td style="white-space:nowrap"><?= $c['last_sold'] ? e(date('M j, Y', strtotime((string)$c['last_sold']))) : '—' ?></td>
                <td><div style="display:flex;gap:6px"><a class="btn btn-sm" href="<?= e(hb_profile_url($c['sport'], $c['grade'], $c['card_key'])) ?>">History</a><a class="btn btn-sm" href="<?= e(epn_search_link($c['title'])) ?>" target="_blank" rel="noopener">Find one →</a></div></td>
            </tr>
            <?php endforeach; ?>
        </table></div>
        <?php endif; ?>
    </div>

    <div class="row" style="gap:16px;align-items:flex-start">
        <div class="card" style="flex:1;min-width:320px">
            <h2 style="margin-top:0">🔨 Most bids ever</h2>
            <p class="sub" style="margin-bottom:12px">Single sales that drew the fiercest bidding.</p>
            <?php if (!$topBids): ?><div class="empty" style="padding:24px">Nothing recorded yet.</div><?php else: ?>
            <div style="overflow-x:auto"><table>
                <tr><th>#</th><th>Card</th><th>Bids</th><th>Sold</th></tr>
                <?php foreach ($topBids as $i => $c): ?>
                <tr>
                    <td style="color:var(--muted)"><?= $i + 1 ?></td>
                    <td style="max-width:260px"><a href="<?= e(hb_profile_url($c['sport'], $c['grade'], $c['card_key'])) ?>"><?= e($c['canonical_card'] ?: $c['title']) ?></a><br>
                        <small style="color:var(--muted)"><?= e(date('M j, Y', strtotime((string)$c['closed_at']))) ?></small></td>
                    <td style="white-space:nowrap"><strong style="color:var(--red)"><?= (int)$c['final_bids'] ?></strong></td>
                    <td style="white-space:nowrap"><?= e(money((float)$c['final_price'], $c['currency'])) ?></td>
                </tr>
                <?php endforeach; ?>
            </table></div>
            <?php endif; ?>
        </div>

        <div class="card" style="flex:1;min-width:320px">
            <h2 style="margin-top:0">💰 Highest sales</h2>
            <p class="sub" style="margin-bottom:12px">The biggest prices in the archive.</p>
            <?php if (!$topPrice): ?><div class="empty" style="padding:24px">Nothing recorded yet.</div><?php else: ?>
            <div style="overflow-x:auto"><table>
                <tr><th>#</th><th>Card</th><th>Sold</th><th>Bids</th></tr>
                <?php foreach ($topPrice as $i => $c): ?>
                <tr>
                    <td style="color:var(--muted)"><?= $i + 1 ?></td>
                    <td style="max-width:260px"><a href="<?= e(hb_profile_url($c['sport'], $c['grade'], $c['card_key'])) ?>"><?= e($c['canonical_card'] ?: $c['title']) ?></a><br>
                        <small style="color:var(--muted)"><?= e(date('M j, Y', strtotime((string)$c['closed_at']))) ?></small></td>
                    <td style="white-space:nowrap"><strong><?= e(money((float)$c['final_price'], $c['currency'])) ?></strong></td>
                    <td style="white-space:nowrap">🔨 <?= (int)$c['final_bids'] ?></td>
                </tr>
                <?php endforeach; ?>
            </table></div>
            <?php endif; ?>
        </div>
    </div>

<?php endif; ?>
<?php
layout_footer();
