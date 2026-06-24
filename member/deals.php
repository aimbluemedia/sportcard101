<?php
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';
require __DIR__ . '/../src/layout.php';

use SportCard101\Auth;

Auth::requireMember();
Auth::refresh($pdo);
$isPro = Auth::isPro();

// Free members see a teaser (top 3); Pro members see the full board.
$limit = $isPro ? 200 : 3;
$deals = $pdo->query(
    "SELECT l.*, s.label AS search_label FROM listings l
     JOIN searches s ON s.id = l.search_id
     WHERE l.is_deal = 1
     ORDER BY (l.ai_verdict='BUY') DESC, l.ai_hidden_gem DESC, l.discount_pct DESC, l.last_seen_at DESC
     LIMIT {$limit}"
)->fetchAll();

layout_header('AI Deals', 'member');
?>
<h1>💰 AI deal opportunities</h1>
<p class="sub">Underpriced PSA 10 listings, scored by the AI Opportunity Engine — best buys and hidden gems first.</p>

<?php if (!$isPro): ?>
<div class="mock-note">
    🔒 You're seeing a <strong>free preview</strong> (top 3). <a href="/member/account.php">Upgrade to Pro</a> to unlock the full board, alerts, and flip-margin math.
</div>
<?php endif; ?>

<?php if (!$deals): ?>
    <div class="empty">No deals found yet — check back after the next scan.</div>
<?php else: ?>
    <div class="deals">
        <?php foreach ($deals as $d):
            $discount = $d['discount_pct'] !== null ? rtrim(rtrim(number_format((float)$d['discount_pct'], 1), '0'), '.') : '0';
            $verdict  = $d['ai_verdict'] ?? null;
        ?>
            <div class="deal is-deal<?= $verdict ? ' v-' . strtolower($verdict) : '' ?>">
                <span class="badge">−<?= e($discount) ?>%</span>
                <?php if ($d['image_url']): ?><img src="<?= e($d['image_url']) ?>" alt="" loading="lazy"><?php endif; ?>
                <div class="info">
                    <?php if ($verdict): ?>
                        <div class="ai-row">
                            <span class="verdict verdict-<?= e(strtolower($verdict)) ?>"><?= e($verdict) ?></span>
                            <?php if ((int)$d['ai_confidence'] > 0): ?><span class="conf"><?= (int)$d['ai_confidence'] ?>% conf</span><?php endif; ?>
                            <?php if (!empty($d['ai_hidden_gem'])): ?><span class="gem">💎 hidden gem</span><?php endif; ?>
                            <?php if ($d['ai_flip_pct'] !== null && $isPro): ?><span class="flip">~<?= rtrim(rtrim(number_format((float)$d['ai_flip_pct'], 1), '0'), '.') ?>% flip</span><?php endif; ?>
                        </div>
                    <?php endif; ?>
                    <div class="title"><?= e($d['ai_card'] ?: $d['title']) ?></div>
                    <div class="price"><?= money((float)$d['price'], $d['currency']) ?></div>
                    <div class="baseline">market ≈ <?= money((float)$d['baseline_price'], $d['currency']) ?> · <?= e($d['search_label']) ?></div>
                    <?php if (!empty($d['ai_reason'])): ?><div class="ai-reason">🤖 <?= e($d['ai_reason']) ?></div><?php endif; ?>
                    <div class="meta">
                        <?php if ($d['buying_option'] === 'AUCTION'): ?>
                            <span>🔨 <?= (int)$d['bid_count'] ?> bids</span><span>⏳ <?= e(time_left($d['end_time'])) ?></span>
                        <?php else: ?><span>🏷️ Buy It Now</span><?php endif; ?>
                    </div>
                    <div class="actions">
                        <a class="btn btn-primary btn-sm" href="<?= e(epn_link($d['item_url'])) ?>" target="_blank" rel="noopener">Bid on eBay →</a>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
    <?php if (!$isPro): ?>
        <div class="upsell"><a class="btn btn-primary btn-lg" href="/member/account.php">Unlock all deals with Pro →</a></div>
    <?php endif; ?>
<?php endif; ?>
<?php
layout_footer();
