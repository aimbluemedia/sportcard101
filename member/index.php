<?php
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';
require __DIR__ . '/../src/layout.php';

use SportCard101\Auth;

Auth::requireMember();
Auth::refresh($pdo);

// Top deals preview.
$deals = $pdo->query(
    "SELECT l.*, s.label AS search_label FROM listings l
     JOIN searches s ON s.id = l.search_id
     WHERE l.is_deal = 1
     ORDER BY (l.ai_verdict='BUY') DESC, l.ai_hidden_gem DESC, l.discount_pct DESC
     LIMIT 3"
)->fetchAll();

$modules = $pdo->query(
    'SELECT * FROM content_modules WHERE is_published = 1 ORDER BY sort, id LIMIT 4'
)->fetchAll();

$isPro = Auth::isPro();

layout_header('Dashboard', 'member');
?>
<h1>Welcome back, <?= e(Auth::username()) ?> 👋</h1>
<p class="sub">
    <?php if ($isPro): ?>
        You're on a <strong>Pro</strong> plan — full AI deal engine unlocked.
    <?php else: ?>
        You're on the <strong>Free</strong> plan. <a href="/member/account.php">Upgrade to Pro</a> for the full AI deal engine and all lessons.
    <?php endif; ?>
</p>

<div class="cards-2">
    <section class="panel">
        <div class="panel-head"><h2>🔥 Top AI deals</h2><a href="/member/deals.php">View all →</a></div>
        <?php if (!$deals): ?>
            <p class="sub">No deals yet — check back soon.</p>
        <?php else: foreach ($deals as $d): ?>
            <div class="mini-deal">
                <span class="verdict verdict-<?= e(strtolower((string)($d['ai_verdict'] ?: 'watch'))) ?>"><?= e($d['ai_verdict'] ?: 'WATCH') ?></span>
                <span class="mini-title"><?= e($d['ai_card'] ?: $d['title']) ?></span>
                <span class="mini-price"><?= money((float)$d['price'], $d['currency']) ?></span>
            </div>
        <?php endforeach; endif; ?>
    </section>

    <section class="panel">
        <div class="panel-head"><h2>🎓 Keep learning</h2><a href="/member/learn.php">All lessons →</a></div>
        <?php if (!$modules): ?>
            <p class="sub">Lessons are coming soon.</p>
        <?php else: foreach ($modules as $m): ?>
            <div class="mini-deal">
                <span class="mini-title"><?= e($m['title']) ?></span>
                <a class="btn btn-sm" href="/member/learn.php#m<?= (int)$m['id'] ?>">Open</a>
            </div>
        <?php endforeach; endif; ?>
    </section>
</div>
<?php
layout_footer();
