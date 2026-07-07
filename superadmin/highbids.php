<?php
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';
require __DIR__ . '/../src/layout.php';

use SportCard101\Auth;
use SportCard101\Comps;

Auth::requireAdmin();

$SPORTS = card_sports();

// Filters: minimum bid count (default 30) and sport.
$min   = isset($_GET['min']) ? max(1, (int)$_GET['min']) : 30;
$sport = isset($_GET['sport']) && isset($SPORTS[$_GET['sport']]) ? (string)$_GET['sport'] : 'all';

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

// ---- Recently closed high-bid sales (from our comp history) --------------
$closed = [];
try {
    $cwhere  = ['final_bids > ?'];
    $cparams = [$min];
    if ($sport !== 'all') { $cwhere[] = 'sport = ?'; $cparams[] = $sport; }
    $cstmt = $pdo->prepare(
        'SELECT canonical_card, title, sport, grade, final_price, final_bids, currency, image_url, item_url, closed_at
         FROM sold_comps WHERE ' . implode(' AND ', $cwhere) . '
         ORDER BY closed_at DESC LIMIT 60'
    );
    $cstmt->execute($cparams);
    $closed = $cstmt->fetchAll();
} catch (\Throwable $e) {
    // sold_comps not migrated yet
}

layout_header('High Bids', 'admin');
?>
<h1>🔥 High Bids</h1>
<p class="sub">Cards drawing heavy interest — auctions with more than <strong><?= (int)$min ?></strong> bids. High bid counts signal strong demand (eBay hides bidder identities, so this is the bid count, not unique bidders).</p>

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

<h2 style="margin-top:28px">🏁 Recently closed <span class="sub" style="font-weight:400">(<?= count($closed) ?>)</span></h2>
<?php if (!$closed): ?>
    <div class="empty">No closed high-bid sales recorded yet. These build up as tracked auctions close (needs the sold-comps table + scanner running).</div>
<?php else: ?>
    <div class="comp-cards">
        <?php foreach ($closed as $c):
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
                <a class="btn btn-sm comp-find" href="<?= e(epn_search_link($c['title'])) ?>" target="_blank" rel="noopener">Find similar →</a>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
<?php
layout_footer();
