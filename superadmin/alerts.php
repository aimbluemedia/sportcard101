<?php
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';
require __DIR__ . '/../src/layout.php';

use SportCard101\Auth;

Auth::requireAdmin();

$fields = [
    'notify_email'          => 'Send alerts to this email address',
    'notify_min_under_comp' => 'Alert when the current bid is at least this % under the comp median',
    'notify_within_hours'   => 'Only alert when the auction ends within this many hours (blank = any time)',
    'notify_max_price'      => 'Only alert at or below this price (blank = no cap)',
    'notify_from'           => '“From” address for alert emails (optional)',
];
$defaults = ['notify_min_under_comp' => '20'];

// Send a quick test email to confirm delivery works.
if (isset($_GET['test'])) {
    $to = trim((string) setting('notify_email', ''));
    if ($to === '') {
        flash('error', 'Add an alert email address and Save first.');
    } else {
        $from = (string) setting('notify_from', '') ?: 'alerts@sportcard101.com';
        $ok = @mail(
            $to,
            'SportCard101: test deal alert',
            "This is a test from your SportCard101 deal agent.\n\nIf you received this, email alerts are working.\n",
            'From: ' . $from . "\r\nContent-Type: text/plain; charset=UTF-8\r\n"
        );
        flash($ok ? 'success' : 'error', $ok
            ? "Test email sent to {$to}. Check your inbox (and spam)."
            : 'PHP mail() could not send. On Hostinger this can be blocked — consider SMTP.');
    }
    redirect('/superadmin/alerts.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $stmt = $pdo->prepare('INSERT INTO settings (skey, sval) VALUES (?, ?) ON DUPLICATE KEY UPDATE sval = VALUES(sval)');
    // Checkbox → explicit 1/0.
    $stmt->execute(['notify_enabled', isset($_POST['notify_enabled']) ? '1' : '0']);
    foreach (array_keys($fields) as $key) {
        $val = trim((string)($_POST[$key] ?? ''));
        if ($val === '' && isset($defaults[$key])) {
            $val = $defaults[$key];
        }
        $stmt->execute([$key, $val]);
    }
    flash('success', 'Deal alert triggers saved.');
    redirect('/superadmin/alerts.php');
}

$enabled = (string) setting('notify_enabled', '0') === '1';

// Status helpers.
$compCount = 0;
try {
    $compCount = (int) $pdo->query('SELECT COUNT(*) FROM sold_comps')->fetchColumn();
} catch (\Throwable $e) {
}
$cronSet = (string) setting('cron_key', '') !== '';

layout_header('Deal Alerts', 'admin');
?>
<h1>🔔 Deal Alert Triggers</h1>
<p class="sub">Get an email the moment a PSA auction is worth bidding on — when the current bid is well under what the card actually sells for (its comp median). The deal agent checks on every scan/cron run and only emails each auction once.</p>

<div class="stat-grid" style="margin-bottom:22px">
    <div class="stat"><div class="stat-num"><?= $enabled ? 'ON' : 'OFF' ?></div><div class="stat-label">Alerts</div></div>
    <div class="stat"><div class="stat-num"><?= number_format($compCount) ?></div><div class="stat-label">Sold comps to compare</div></div>
    <div class="stat"><div class="stat-num"><?= $cronSet ? 'YES' : 'NO' ?></div><div class="stat-label">Cron key set (auto-runs)</div></div>
</div>

<?php if ($compCount === 0): ?>
    <div class="flash flash-info">No sold comps recorded yet — alerts compare live auctions to your comp history, so they start firing once auctions close and build up comps. Keep the scanner/cron running.</div>
<?php endif; ?>

<form method="post" class="card" style="max-width:720px"><?= csrf_field() ?>
    <label class="checkbox">
        <input type="checkbox" name="notify_enabled" value="1" <?= $enabled ? 'checked' : '' ?>>
        Email me when an auction beats my triggers
    </label>

    <?php foreach ($fields as $key => $label):
        $val = setting($key, $defaults[$key] ?? ''); ?>
        <label><?= e($label) ?></label>
        <input name="<?= e($key) ?>" value="<?= e((string)$val) ?>"
               <?= $key === 'notify_email' ? 'type="email" placeholder="you@example.com"' : '' ?>>
    <?php endforeach; ?>

    <div style="margin-top:20px;display:flex;gap:10px;flex-wrap:wrap">
        <button class="btn btn-primary" type="submit">Save triggers</button>
        <a class="btn" href="/superadmin/alerts.php?test=1">✉️ Send test email</a>
    </div>
</form>

<div class="card" style="max-width:720px;margin-top:18px">
    <h2 style="margin-top:0">How it works</h2>
    <p class="sub" style="margin:0 0 8px">
        Every scan (and every cron run) the agent looks at your live PSA auctions, matches each to its sold comps, and emails you the ones that beat the triggers above — the same auctions flagged <strong>🎯 SNIPE</strong> and <strong>📊 comp</strong> on the
        <a href="/superadmin/auctions.php">Auctions</a> page. To make it fully automatic, set a <strong>Cron secret key</strong> in
        <a href="/superadmin/settings.php">Settings</a> and add the Hostinger cron job.
    </p>
    <p class="sub" style="margin:0">Alerts send via PHP mail(); on Hostinger that works but can land in spam. If delivery is unreliable, switch to SMTP.</p>
</div>
<?php
layout_footer();
