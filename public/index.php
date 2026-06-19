<?php
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';
require __DIR__ . '/../src/layout.php';

use Vipsvault\Auth;
use Vipsvault\EbayClient;

Auth::require();

$uid  = Auth::userId();
$ebay = new EbayClient($config['ebay']);

// Filter: show all deals, or only one search.
$searchFilter = isset($_GET['search']) ? (int)$_GET['search'] : 0;

// Load the user's searches for the filter bar.
$sStmt = $pdo->prepare('SELECT id, label FROM searches WHERE user_id = ? ORDER BY label');
$sStmt->execute([$uid]);
$searches = $sStmt->fetchAll();

// Load deals (best discounts first), scoped to this user's searches.
$params = [$uid];
$sql = 'SELECT l.*, s.label AS search_label
        FROM listings l
        JOIN searches s ON s.id = l.search_id
        WHERE s.user_id = ? AND l.is_deal = 1';
if ($searchFilter) {
    $sql .= ' AND l.search_id = ?';
    $params[] = $searchFilter;
}
$sql .= ' ORDER BY l.discount_pct DESC, l.last_seen_at DESC LIMIT 200';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$deals = $stmt->fetchAll();

// Count active searches for the empty-state hint.
$hasSearches = count($searches) > 0;

layout_header('Deals');
?>
<h1>💰 Best deals</h1>
<p class="sub">PSA 10 listings priced below their market baseline. Sorted by biggest discount.</p>

<?php if ($ebay->isMock()): ?>
<div class="mock-note">
    ⚠️ Running in <strong>MOCK mode</strong> — showing sample data. Add your eBay API
    credentials to <code>config.php</code> to scan live listings.
</div>
<?php endif; ?>

<?php if ($hasSearches): ?>
<div class="meta" style="margin-bottom:18px">
    <a href="index.php"<?= $searchFilter === 0 ? ' style="color:var(--accent)"' : '' ?>>All searches</a>
    <?php foreach ($searches as $s): ?>
        <a href="index.php?search=<?= (int)$s['id'] ?>"<?= $searchFilter === (int)$s['id'] ? ' style="color:var(--accent)"' : '' ?>><?= e($s['label']) ?></a>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<?php if (!$hasSearches): ?>
    <div class="empty">
        <p>You haven't set up any searches yet.</p>
        <p><a class="btn btn-primary" href="searches.php">+ Create your first search</a></p>
    </div>
<?php elseif (!$deals): ?>
    <div class="empty">
        <p>No deals found yet for the current filter.</p>
        <p>Hit <strong>⟳ Scan now</strong> to check eBay, or lower a search's deal threshold.</p>
    </div>
<?php else: ?>
    <div class="deals">
        <?php foreach ($deals as $d): ?>
            <?php
                $discount = $d['discount_pct'] !== null ? rtrim(rtrim(number_format((float)$d['discount_pct'], 1), '0'), '.') : '0';
            ?>
            <div class="deal is-deal">
                <span class="badge">−<?= e($discount) ?>%</span>
                <?php if ($d['image_url']): ?>
                    <img src="<?= e($d['image_url']) ?>" alt="" loading="lazy">
                <?php endif; ?>
                <div class="info">
                    <div class="title"><?= e($d['title']) ?></div>
                    <div class="price"><?= money((float)$d['price'], $d['currency']) ?></div>
                    <div class="baseline">market ≈ <?= money((float)$d['baseline_price'], $d['currency']) ?> · <?= e($d['search_label']) ?></div>
                    <div class="meta">
                        <?php if ($d['buying_option'] === 'AUCTION'): ?>
                            <span>🔨 <?= (int)$d['bid_count'] ?> bids</span>
                            <span>⏳ <?= e(time_left($d['end_time'])) ?></span>
                        <?php else: ?>
                            <span>🏷️ Buy It Now</span>
                        <?php endif; ?>
                    </div>
                    <div class="actions">
                        <a class="btn btn-primary btn-sm" href="<?= e($d['item_url']) ?>" target="_blank" rel="noopener">Bid on eBay →</a>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
<?php
layout_footer();
