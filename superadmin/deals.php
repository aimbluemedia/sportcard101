<?php
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';
require __DIR__ . '/../src/layout.php';

use SportCard101\Auth;
use SportCard101\EbayClient;
use SportCard101\AiAnalyst;

Auth::requireAdmin();

$ebay = new EbayClient($config['ebay']);
$ai   = new AiAnalyst($config['ai']);

$deals = $pdo->query(
    "SELECT l.*, s.label AS search_label FROM listings l
     JOIN searches s ON s.id = l.search_id
     WHERE l.is_deal = 1
     ORDER BY (l.ai_verdict='BUY') DESC, l.ai_hidden_gem DESC, l.discount_pct DESC, l.last_seen_at DESC
     LIMIT 300"
)->fetchAll();

layout_header('AI Deals', 'admin');
?>
<h1>💰 AI deals (admin view)</h1>
<p class="sub">Everything the engine has flagged. This is what members see (Pro = full, Free = top 3).</p>

<?php if ($ebay->isMock() || $ai->isMock()): ?>
<div class="mock-note">
    ⚠️ <strong>MOCK mode:</strong>
    <?php if ($ebay->isMock()): ?> eBay = sample data (add eBay keys).<?php endif; ?>
    <?php if ($ai->isMock()): ?> AI = heuristic (add <code>ANTHROPIC_API_KEY</code>).<?php endif; ?>
</div>
<?php endif; ?>

<form method="post" action="/superadmin/scan.php" class="inline" style="margin-bottom:18px"><?= csrf_field() ?>
    <button class="btn btn-scan" type="submit">⟳ Scan now</button></form>

<?php if (!$deals): ?>
    <div class="empty">No deals yet. Add searches on the <a href="/superadmin/searches.php">AI App</a> page and scan.</div>
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
                            <?php if ($d['ai_flip_pct'] !== null): ?><span class="flip">~<?= rtrim(rtrim(number_format((float)$d['ai_flip_pct'], 1), '0'), '.') ?>% flip</span><?php endif; ?>
                        </div>
                    <?php endif; ?>
                    <div class="title"><?= e($d['ai_card'] ?: $d['title']) ?></div>
                    <div class="price"><?= money((float)$d['price'], $d['currency']) ?></div>
                    <div class="baseline">market ≈ <?= money((float)$d['baseline_price'], $d['currency']) ?> · <?= e($d['search_label']) ?></div>
                    <?php if (!empty($d['ai_reason'])): ?><div class="ai-reason">🤖 <?= e($d['ai_reason']) ?></div><?php endif; ?>
                    <div class="actions"><a class="btn btn-primary btn-sm" href="<?= e($d['item_url']) ?>" target="_blank" rel="noopener">View on eBay →</a></div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
<?php
layout_footer();
