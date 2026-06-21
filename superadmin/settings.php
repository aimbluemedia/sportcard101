<?php
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';
require __DIR__ . '/../src/layout.php';

use SportCard101\Auth;

Auth::requireAdmin();

// Editable settings: key => [label, type]
$fields = [
    'site_url'              => ['Site URL (for referral links)', 'text'],
    'hero_title'            => ['Homepage headline', 'text'],
    'hero_subtitle'         => ['Homepage subtitle', 'textarea'],
    'skool_url'             => ['Skool community URL', 'text'],
    'enable_member_searches'=> ['Let members run their own AI searches (1/0)', 'text'],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $stmt = $pdo->prepare(
        'INSERT INTO settings (skey, sval) VALUES (?, ?) ON DUPLICATE KEY UPDATE sval = VALUES(sval)'
    );
    foreach (array_keys($fields) as $key) {
        $stmt->execute([$key, (string)($_POST[$key] ?? '')]);
    }
    flash('success', 'Settings saved.');
    redirect('/superadmin/settings.php');
}

layout_header('Settings', 'admin');
?>
<h1>Site settings</h1>
<p class="sub">Control homepage copy, the community link, and feature flags members see.</p>

<div class="card" style="max-width:680px">
    <form method="post" class="stack"><?= csrf_field() ?>
        <?php foreach ($fields as $key => [$label, $type]): $val = setting($key, ''); ?>
            <label><?= e($label) ?></label>
            <?php if ($type === 'textarea'): ?>
                <textarea name="<?= e($key) ?>" rows="3"><?= e($val) ?></textarea>
            <?php else: ?>
                <input name="<?= e($key) ?>" value="<?= e($val) ?>">
            <?php endif; ?>
        <?php endforeach; ?>
        <div style="margin-top:16px"><button class="btn btn-primary" type="submit">Save settings</button></div>
    </form>
</div>
<?php
layout_footer();
