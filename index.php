<?php
declare(strict_types=1);

require __DIR__ . '/src/bootstrap.php';
require __DIR__ . '/src/layout.php';

use SportCard101\Auth;

// Already logged in? Send them where they belong.
if (Auth::isAdmin()) {
    redirect('/superadmin/');
}

$plans = $pdo->query('SELECT * FROM plans WHERE is_active = 1 ORDER BY sort, price_cents')->fetchAll();

$heroTitle = setting('hero_title', 'Learn to find and flip sports cards — with AI.');
$heroSub   = setting('hero_subtitle', 'SportCard101 teaches you the hobby and hands you an AI engine that spots underpriced PSA 10 deals on eBay before everyone else.');

layout_header('Home', 'public');
?>
<section class="hero">
    <h1><?= e($heroTitle) ?></h1>
    <p class="hero-sub"><?= e($heroSub) ?></p>
    <div class="hero-cta">
        <a class="btn btn-primary btn-lg" href="/signup.php">Start free</a>
        <a class="btn btn-lg" href="#pricing">See pricing</a>
    </div>
    <p class="hero-note">No credit card to start · Cancel anytime</p>
</section>

<section id="features" class="features">
    <div class="feature">
        <div class="fi">🤖</div>
        <h3>AI Opportunity Engine</h3>
        <p>Our AI reads messy eBay listings, finds underpriced cards and hidden gems, and tells you the flip margin — in plain English.</p>
    </div>
    <div class="feature">
        <div class="fi">🎓</div>
        <h3>SportCard101 School</h3>
        <p>Step-by-step lessons: grading, spotting fakes, where to buy, and how to flip your first card with confidence.</p>
    </div>
    <div class="feature">
        <div class="fi">💸</div>
        <h3>Deals + Community</h3>
        <p>Curated daily deals, a members community, and an affiliate program so you can earn while you learn.</p>
    </div>
</section>

<section id="pricing" class="pricing">
    <h2>Simple pricing</h2>
    <div class="plan-grid">
        <?php foreach ($plans as $p): ?>
            <div class="plan<?= $p['price_cents'] > 0 ? ' plan-featured' : '' ?>">
                <h3><?= e($p['name']) ?></h3>
                <div class="plan-price">
                    <?= $p['price_cents'] === 0 ? 'Free' : money_cents((int)$p['price_cents']) ?>
                    <?php if ($p['price_cents'] > 0): ?><span>/<?= e($p['bill_interval']) ?></span><?php endif; ?>
                </div>
                <?php if ($p['blurb']): ?><p class="plan-blurb"><?= e($p['blurb']) ?></p><?php endif; ?>
                <ul class="plan-features">
                    <?php foreach (array_filter(array_map('trim', explode("\n", (string)$p['features']))) as $f): ?>
                        <li><?= e($f) ?></li>
                    <?php endforeach; ?>
                </ul>
                <a class="btn btn-primary" href="/signup.php?plan=<?= e($p['slug']) ?>">Get started</a>
            </div>
        <?php endforeach; ?>
    </div>
</section>
<?php
layout_footer();
