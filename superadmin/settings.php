<?php
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';
require __DIR__ . '/../src/layout.php';

use SportCard101\Auth;
use SportCard101\EbayClient;

Auth::requireAdmin();

// Site / school settings.
$siteFields = [
    'site_url'               => ['Site URL (for referral links)', 'text', ''],
    'hero_title'             => ['Homepage headline', 'text', ''],
    'hero_subtitle'          => ['Homepage subtitle', 'textarea', ''],
    'skool_url'              => ['Skool community URL', 'text', ''],
    'enable_member_searches' => ['Let members run their own AI searches (1 = on, 0 = off)', 'text', ''],
];

// eBay Partner / Browse API settings.
$ebayFields = [
    'ebay_app_id'      => ['App ID / Client ID', 'Paste your eBay App ID here. Use Production keys, not Sandbox, for live eBay.com results.'],
    'ebay_dev_id'      => ['Dev ID', 'Stored for your eBay keyset. The Buy Browse API token flow does not send this field, but keeping it here makes your keyset complete.'],
    'ebay_cert_id'     => ['Cert ID / Client Secret', 'Paste your eBay Cert ID here. It must be from the same Production keyset as the App ID.'],
    'ebay_campaign_id' => ['ePN Campaign ID', 'Your eBay Partner Network campid. It is NOT your App ID.'],
    'ebay_custom_id'   => ['Affiliate Reference ID / Custom ID', 'Optional tracking label. eBay calls this customid / SUB-ID.'],
    'ebay_marketplace' => ['Marketplace ID', 'Examples: EBAY_US, EBAY_GB, EBAY_CA, EBAY_AU.'],
    'ebay_endpoint'    => ['API Endpoint', 'Live: https://api.ebay.com  ·  Sandbox: https://api.sandbox.ebay.com'],
    'ebay_cache_hours' => ['Cache Hours', 'How long to cache eBay results.'],
];

// Test connection (uses currently SAVED settings).
if (isset($_GET['test']) && $_GET['test'] === 'ebay') {
    [$ok, $msg] = (new EbayClient(ebay_config($config['ebay'])))->testConnection();
    flash($ok ? 'success' : 'error', 'eBay: ' . $msg);
    redirect('/superadmin/settings.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $stmt = $pdo->prepare('INSERT INTO settings (skey, sval) VALUES (?, ?) ON DUPLICATE KEY UPDATE sval = VALUES(sval)');
    foreach ([...array_keys($siteFields), ...array_keys($ebayFields)] as $key) {
        $val = (string)($_POST[$key] ?? '');
        // sensible defaults for a couple of eBay fields
        if ($key === 'ebay_marketplace' && $val === '') $val = 'EBAY_US';
        if ($key === 'ebay_endpoint' && $val === '')    $val = 'https://api.ebay.com';
        if ($key === 'ebay_cache_hours' && $val === '')  $val = '12';
        $stmt->execute([$key, $val]);
    }
    flash('success', 'Settings saved.');
    redirect('/superadmin/settings.php');
}

layout_header('Settings', 'admin');
?>
<h1>Site settings</h1>
<p class="sub">Homepage copy, community link, and feature flags members see.</p>

<form method="post" class="card" style="max-width:760px"><?= csrf_field() ?>
    <?php foreach ($siteFields as $key => [$label, $type]): $val = setting($key, ''); ?>
        <label><?= e($label) ?></label>
        <?php if ($type === 'textarea'): ?>
            <textarea name="<?= e($key) ?>" rows="3"><?= e($val) ?></textarea>
        <?php else: ?>
            <input name="<?= e($key) ?>" value="<?= e($val) ?>">
        <?php endif; ?>
    <?php endforeach; ?>

    <hr style="border-color:var(--border);margin:26px 0 6px">
    <h2 style="margin-top:14px">🛒 VIPSVault eBay API</h2>
    <p class="sub">
        Use your three eBay Developer keys here: <strong>App ID</strong> goes in Client ID,
        <strong>Dev ID</strong> in Dev ID, and <strong>Cert ID</strong> in Client Secret.
        The Browse API uses App&nbsp;ID + Cert&nbsp;ID to create an OAuth app token; Dev&nbsp;ID is saved
        but mainly for legacy APIs. Powers the AI deal scanner and eBay Partner Network affiliate links.
    </p>

    <?php foreach ($ebayFields as $key => [$label, $help]):
        $val = setting($key, $key === 'ebay_marketplace' ? 'EBAY_US' : ($key === 'ebay_endpoint' ? 'https://api.ebay.com' : ($key === 'ebay_cache_hours' ? '12' : '')));
        $isSecret = $key === 'ebay_cert_id';
    ?>
        <label><?= e($label) ?></label>
        <input name="<?= e($key) ?>" value="<?= e($val) ?>"<?= $isSecret ? ' type="password" autocomplete="off"' : '' ?>>
        <p class="field-help"><?= e($help) ?></p>
    <?php endforeach; ?>

    <div style="margin-top:18px;display:flex;gap:10px;flex-wrap:wrap">
        <button class="btn btn-primary" type="submit">Save settings</button>
        <a class="btn" href="/superadmin/settings.php?test=ebay">⚡ Test eBay connection</a>
    </div>
</form>
<?php
layout_footer();
