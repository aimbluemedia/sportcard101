<?php
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';
require __DIR__ . '/../src/layout.php';

use SportCard101\Auth;

Auth::requireAdmin();

$one = fn (string $sql): int => (int)$pdo->query($sql)->fetchColumn();
$stats = [
    'Members'        => $one("SELECT COUNT(*) FROM users WHERE role='member'"),
    'Paid members'   => $one("SELECT COUNT(*) FROM users WHERE sub_status IN ('trialing','active')"),
    'Active deals'   => $one("SELECT COUNT(*) FROM listings WHERE is_deal=1"),
    'Lessons'        => $one("SELECT COUNT(*) FROM content_lessons WHERE is_published=1"),
    'Referrals'      => $one("SELECT COUNT(*) FROM referrals"),
    'Searches'       => $one("SELECT COUNT(*) FROM searches WHERE active=1"),
];

layout_header('Dashboard', 'admin');
?>
<h1>Admin dashboard</h1>
<p class="sub">Manage members, pricing, school content, the AI app, and site settings.</p>

<div class="stat-grid">
    <?php foreach ($stats as $label => $val): ?>
        <div class="stat"><div class="stat-num"><?= $val ?></div><div class="stat-label"><?= e($label) ?></div></div>
    <?php endforeach; ?>
</div>

<div class="cards-2" style="margin-top:24px">
    <section class="panel">
        <div class="panel-head"><h2>Quick actions</h2></div>
        <p><a class="btn" href="/superadmin/searches.php">Configure AI searches</a>
           <a class="btn" href="/superadmin/auctions.php">View auctions</a></p>
        <p><a class="btn" href="/superadmin/pricing.php">Edit pricing</a>
           <a class="btn" href="/superadmin/content.php">Edit school content</a></p>
    </section>
    <section class="panel">
        <div class="panel-head"><h2>Member preview</h2></div>
        <p class="sub">See exactly what members see.</p>
        <p><a class="btn" href="/member/" target="_blank">Open member area →</a></p>
    </section>
</div>
<?php
layout_footer();
