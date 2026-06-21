<?php
declare(strict_types=1);

require __DIR__ . '/src/bootstrap.php';
require __DIR__ . '/src/layout.php';

use Sportscard101\Auth;
use Sportscard101\EbayClient;
use Sportscard101\AiAnalyst;

Auth::require();

$uid  = Auth::userId();
$ebay = new EbayClient($config['ebay']);
$ai   = new AiAnalyst($config['ai']);

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
// AI-first ordering: BUY verdicts, then hidden gems, then biggest discount.
$sql .= " ORDER BY (l.ai_verdict = 'BUY') DESC, l.ai_hidden_gem DESC,
                   l.discount_pct DESC, l.last_seen_at DESC LIMIT 200";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$deals = $stmt->fetchAll();

// Count active searches for the empty-state hint.
$hasSearches = count($searches) > 0;

layout_header('Deals');
?>
<h1>💰 AI deal opportunities</h1>
<p class="sub">Listings below market, scored by the AI Opportunity Engine — best buys and hidden gems first.</p>

<?php if ($ebay->isMock() || $ai->isMock()): ?>
<div class="mock-note">
    ⚠️ <strong>MOCK mode:</strong>
    <?php if ($ebay->isMock()): ?> eBay data is sample data (add eBay API keys to <code>config.php</code>).<?php endif; ?>
    <?php if ($ai->isMock()): ?> AI scoring is heuristic (add <code>ANTHROPIC_API_KEY</code> for real AI analysis).<?php endif; ?>
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
            <?php $verdict = $d['ai_verdict'] ?? null; ?>
            <div class="deal is-deal<?= $verdict ? ' v-' . strtolower($verdict) : '' ?>">
                <span class="badge">−<?= e($discount) ?>%</span>
                <?php if ($d['image_url']): ?>
                    <img src="<?= e($d['image_url']) ?>" alt="" loading="lazy">
                <?php endif; ?>
                <div class="info">
                    <?php if ($verdict): ?>
                        <div class="ai-row">
                            <span class="verdict verdict-<?= e(strtolower($verdict)) ?>"><?= e($verdict) ?></span>
                            <?php if ((int)$d['ai_confidence'] > 0): ?>
                                <span class="conf"><?= (int)$d['ai_confidence'] ?>% conf</span>
                            <?php endif; ?>
                            <?php if (!empty($d['ai_hidden_gem'])): ?>
                                <span class="gem">💎 hidden gem</span>
                            <?php endif; ?>
                            <?php if ($d['ai_flip_pct'] !== null): ?>
                                <span class="flip">~<?= rtrim(rtrim(number_format((float)$d['ai_flip_pct'], 1), '0'), '.') ?>% flip</span>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                    <div class="title"><?= e($d['ai_card'] ?: $d['title']) ?></div>
                    <div class="price"><?= money((float)$d['price'], $d['currency']) ?></div>
                    <div class="baseline">market ≈ <?= money((float)$d['baseline_price'], $d['currency']) ?> · <?= e($d['search_label']) ?></div>
                    <?php if (!empty($d['ai_reason'])): ?>
                        <div class="ai-reason">🤖 <?= e($d['ai_reason']) ?></div>
                    <?php endif; ?>
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
