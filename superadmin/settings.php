<?php
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';
require __DIR__ . '/../src/layout.php';

use SportCard101\Auth;
use SportCard101\EbayClient;

Auth::requireAdmin();

/*
 * Settings groups. Keys map 1:1 to the `settings` table and to ebay_config()
 * in src/helpers.php, which feeds the EbayClient used by the AI deal scanner.
 */
$siteFields = [
    'site_url'               => ['Site URL (for referral links)', 'text'],
    'hero_title'             => ['Homepage headline', 'text'],
    'hero_subtitle'          => ['Homepage subtitle', 'textarea'],
    'skool_url'              => ['Skool community URL', 'text'],
    'enable_member_searches' => ['Let members run their own AI searches (1 = on, 0 = off)', 'text'],
    'cron_key'               => ['Cron secret key (for automatic scanning via cron.php)', 'text'],
];

// eBay Partner Network — affiliate tracking (commission links).
$epnFields = [
    'ebay_account_sid' => ['Account SID', 'From your eBay Partner Network API credentials (Account SID / Auth Token / Reset Token).'],
    'ebay_auth_token'  => ['Auth Token', 'Your eBay Partner Network Auth Token. Secret — leave blank to keep the saved value.', 'secret'],
    'ebay_campaign_id' => ['ePN Campaign ID', 'Your eBay Partner Network campid — stamps affiliate tracking on links. NOT your App ID.'],
    'ebay_custom_id'   => ['Affiliate Reference ID / Custom ID', 'Optional tracking label (eBay calls this customid / SUB-ID).'],
    'ebay_rotation_id' => ['Tracking Rotation ID (mkrid)', 'Optional. Leave blank to auto-pick by marketplace.'],
];

// eBay Developer Program — Browse API keyset that POWERS THE AI DEAL SCANNER.
$devFields = [
    'ebay_app_id'      => ['App ID / Client ID', 'Production App ID from developer.ebay.com (looks like Name-app-PRD-xxxx-xxxx).'],
    'ebay_dev_id'      => ['Dev ID', 'From the same keyset. Stored to complete your keyset (Browse token flow does not send it).'],
    'ebay_cert_id'     => ['Cert ID / Client Secret', 'Production Cert ID (starts with PRD-). Secret — leave blank to keep the saved value.', 'secret'],
    'ebay_marketplace' => ['Marketplace ID', 'EBAY_US, EBAY_GB, EBAY_CA, EBAY_AU, …'],
    'ebay_endpoint'    => ['API Endpoint', 'Live: https://api.ebay.com   ·   Sandbox: https://api.sandbox.ebay.com'],
    'ebay_cache_hours' => ['Cache Hours', 'How long to cache eBay results.'],
];

$allFields = $siteFields + $epnFields + $devFields;
$secretKeys = ['ebay_auth_token', 'ebay_cert_id'];

$defaults = [
    'ebay_marketplace' => 'EBAY_US',
    'ebay_endpoint'    => 'https://api.ebay.com',
    'ebay_cache_hours' => '12',
];

// Test the Browse keyset (powers the scanner) using the currently SAVED values.
if (isset($_GET['test']) && $_GET['test'] === 'ebay') {
    [$ok, $msg] = (new EbayClient(ebay_config($config['ebay'])))->testConnection();
    flash($ok ? 'success' : 'error', 'eBay: ' . $msg);
    redirect('/superadmin/settings.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $stmt = $pdo->prepare('INSERT INTO settings (skey, sval) VALUES (?, ?) ON DUPLICATE KEY UPDATE sval = VALUES(sval)');
    foreach (array_keys($allFields) as $key) {
        $val = trim((string)($_POST[$key] ?? ''));
        if ($val === '' && isset($defaults[$key])) {
            $val = $defaults[$key];
        }
        // Don't wipe a stored secret when its masked field is left blank.
        if (in_array($key, $secretKeys, true) && $val === '') {
            continue;
        }
        $stmt->execute([$key, $val]);
    }
    flash('success', 'Settings saved.');
    redirect('/superadmin/settings.php');
}

/** Render one labelled field. */
layout_header('Settings', 'admin');
?>
<h1>Settings</h1>
<p class="sub">Site copy, eBay Partner (affiliate) keys, and eBay Developer (Browse) keys for the AI deal scanner.</p>

<form method="post" class="card" style="max-width:780px"><?= csrf_field() ?>

    <h2 style="margin-top:0">Site</h2>
    <?php foreach ($siteFields as $key => $def):
        [$label, $type] = $def; $val = setting($key, ''); ?>
        <label><?= e($label) ?></label>
        <?php if ($type === 'textarea'): ?>
            <textarea name="<?= e($key) ?>" rows="3"><?= e((string)$val) ?></textarea>
        <?php else: ?>
            <input name="<?= e($key) ?>" value="<?= e((string)$val) ?>">
        <?php endif; ?>
    <?php endforeach; ?>

    <hr style="margin:26px 0 8px">
    <h2>🛒 eBay Partner Network <small style="color:var(--muted);font-weight:400">— affiliate tracking</small></h2>
    <?php foreach ($epnFields as $key => $def):
        [$label, $help] = [$def[0], $def[1] ?? ''];
        $isSecret = in_array($key, $secretKeys, true);
        $set = (string) setting($key, '') !== '';
    ?>
        <label><?= e($label) ?></label>
        <?php if ($isSecret): ?>
            <input name="<?= e($key) ?>" type="password" autocomplete="off" value="" placeholder="<?= $set ? '•••••••• (saved — leave blank to keep)' : 'paste value' ?>">
        <?php else: ?>
            <input name="<?= e($key) ?>" value="<?= e((string) setting($key, '')) ?>">
        <?php endif; ?>
        <p class="field-help"><?= e($help) ?></p>
    <?php endforeach; ?>

    <hr style="margin:26px 0 8px">
    <h2>🔎 eBay Developer API <small style="color:var(--muted);font-weight:400">— powers the AI deal scanner</small></h2>
    <p class="sub">Production keyset from <a href="https://developer.ebay.com/my/keys" target="_blank" rel="noopener">developer.ebay.com</a>. Required to scan live listings.</p>
    <?php foreach ($devFields as $key => $def):
        [$label, $help] = [$def[0], $def[1] ?? ''];
        $isSecret = in_array($key, $secretKeys, true);
        $set = (string) setting($key, '') !== '';
        $val = setting($key, $defaults[$key] ?? '');
    ?>
        <label><?= e($label) ?></label>
        <?php if ($isSecret): ?>
            <input name="<?= e($key) ?>" type="password" autocomplete="off" value="" placeholder="<?= $set ? '•••••••• (saved — leave blank to keep)' : 'paste value' ?>">
        <?php else: ?>
            <input name="<?= e($key) ?>" value="<?= e((string)$val) ?>">
        <?php endif; ?>
        <p class="field-help"><?= e($help) ?></p>
    <?php endforeach; ?>

    <div style="margin-top:20px;display:flex;gap:10px;flex-wrap:wrap">
        <button class="btn btn-primary" type="submit">Save settings</button>
        <a class="btn" href="/superadmin/settings.php?test=ebay">⚡ Test eBay connection</a>
    </div>
</form>
<?php
layout_footer();
