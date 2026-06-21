<?php
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';
require __DIR__ . '/../src/layout.php';

use SportCard101\Auth;

Auth::requireMember();
Auth::refresh($pdo);

$uid = Auth::userId();
$me  = $pdo->prepare('SELECT u.*, p.name AS plan_name FROM users u LEFT JOIN plans p ON p.id = u.plan_id WHERE u.id = ?');
$me->execute([$uid]);
$user = $me->fetch();

// Affiliate stats.
$refCount = $pdo->prepare('SELECT COUNT(*) FROM referrals WHERE affiliate_user_id = ?');
$refCount->execute([$uid]);
$referrals = (int)$refCount->fetchColumn();

$plans = $pdo->query('SELECT * FROM plans WHERE is_active = 1 AND price_cents > 0 ORDER BY sort, price_cents')->fetchAll();
$base  = setting('site_url', 'https://sportcard101.com');
$refLink = rtrim($base, '/') . '/signup.php?ref=' . urlencode((string)$user['affiliate_code']);

layout_header('Account', 'member');
?>
<h1>Your account</h1>

<div class="cards-2">
    <section class="panel">
        <div class="panel-head"><h2>Subscription</h2></div>
        <p><strong>Plan:</strong> <?= e($user['plan_name'] ?: 'Free') ?></p>
        <p><strong>Status:</strong> <span class="verdict verdict-<?= Auth::isPro() ? 'buy' : 'pass' ?>"><?= e(strtoupper($user['sub_status'])) ?></span></p>
        <?php if (!Auth::isPro()): ?>
            <h3>Upgrade to Pro</h3>
            <?php foreach ($plans as $p): ?>
                <div class="mini-deal">
                    <span class="mini-title"><?= e($p['name']) ?> — <?= money_cents((int)$p['price_cents']) ?>/<?= e($p['bill_interval']) ?></span>
                    <!-- Stripe Checkout wires in here (stripe_price_id: <?= e($p['stripe_price_id'] ?: 'not set') ?>) -->
                    <a class="btn btn-primary btn-sm" href="#" onclick="alert('Stripe checkout connects here once your keys are added.');return false;">Upgrade</a>
                </div>
            <?php endforeach; ?>
            <p class="sub" style="margin-top:8px">Billing connects to Stripe — add your keys to go live.</p>
        <?php endif; ?>
    </section>

    <section class="panel">
        <div class="panel-head"><h2>Refer & earn 💸</h2></div>
        <p class="sub">Share your link. When someone joins, it's tracked to you.</p>
        <p><strong>Your code:</strong> <code><?= e($user['affiliate_code']) ?></code></p>
        <input class="copy-field" value="<?= e($refLink) ?>" readonly onclick="this.select()">
        <p class="sub" style="margin-top:8px"><strong><?= $referrals ?></strong> referral<?= $referrals === 1 ? '' : 's' ?> so far.</p>
    </section>
</div>

<section class="panel">
    <div class="panel-head"><h2>Profile</h2></div>
    <p><strong>Username:</strong> <?= e($user['username']) ?> · <strong>Email:</strong> <?= e($user['email']) ?></p>
    <p><a class="btn" href="/logout.php">Log out</a></p>
</section>
<?php
layout_footer();
