<?php
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';
require __DIR__ . '/../src/layout.php';

use SportCard101\Auth;
use SportCard101\EbayClient;

Auth::requireAdmin();

// Site / school settings.
$siteFields = [
    'site_url'               => ['Site URL (for referral links)', 'text'],
    'hero_title'             => ['Homepage headline', 'text'],
    'hero_subtitle'          => ['Homepage subtitle', 'textarea'],
    'skool_url'              => ['Skool community URL', 'text'],
    'enable_member_searches' => ['Let members run their own AI searches (1 = on, 0 = off)', 'text'],
];

// eBay Partner Network (affiliate) — the credentials shown on EPN's API page.
$epnFields = [
    'ebay_account_sid' => ['Account SID', 'From your eBay Partner Network API credentials (the screen with Account SID / Auth Token / Reset Token).'],
    'ebay_auth_token'  => ['Auth Token', 'Your eBay Partner Network Auth Token. Keep it secret — if it leaks, click "Reset Token" in EPN.'],
    'ebay_campaign_id' => ['ePN Campaign ID', 'Your eBay Partner Network campid — stamps affiliate tracking onto deal links. It is NOT your App ID.'],
    'ebay_rotation_id' => ['Tracking Rotation ID (advanced)', 'Optional. eBay Partner rotation id (mkrid). Leave blank to auto-pick by marketplace.'],
    'ebay_custom_id'   => ['Affiliate Reference ID / Custom ID', 'Optional tracking label. eBay calls this customid / SUB-ID.'],
    'ebay_marketplace' => ['Marketplace ID', 'Examples: EBAY_US, EBAY_GB, EBAY_CA, EBAY_AU.'],
    'ebay_endpoint'    => ['API Endpoint', 'Live: https://api.ebay.com  ·  Sandbox: https://api.sandbox.ebay.com'],
    'ebay_cache_hours' => ['Cache Hours', 'How long to cache eBay results.'],
];

// Browse API developer keyset — only needed to run the LIVE AI deal scanner.
$browseFields = [
    'ebay_app_id'  => ['App ID / Client ID', 'From the eBay Developer Program (Production). Required for live deal scanning.'],
    'ebay_cert_id' => ['Cert ID / Client Secret', 'From the same Production keyset as the App ID. Required for live deal scanning.'],
];

$allKeys = [...array_keys($siteFields), ...array_keys($epnFields), ...array_keys($browseFields)];

// Test connection (uses currently SAVED settings).
if (isset($_GET['test']) && $_GET['test'] === 'ebay') {
    [$ok, $msg] = (new EbayClient(ebay_config($config['ebay'])))->testConnection();
    flash($ok ? 'success' : 'error', 'eBay: ' . $msg);
    redirect('/superadmin/settings.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $stmt = $pdo->prepare('INSERT INTO settings (skey, sval) VALUES (?, ?) ON DUPLICATE KEY UPDATE sval = VALUES(sval)');
    foreach ($allKeys as $key) {
        $val = trim((string)($_POST[$key] ?? ''));
        if ($key === 'ebay_marketplace' && $val === '') $val = 'EBAY_US';
        if ($key === 'ebay_endpoint' && $val === '')    $val = 'https://api.ebay.com';
        if ($key === 'ebay_cache_hours' && $val === '')  $val = '12';
        // Don't wipe a stored Auth Token when the (masked) field is submitted blank.
        if ($key === 'ebay_auth_token' && $val === '') {
            continue;
        }
        $stmt->execute([$key, $val]);
    }
    flash('success', 'Settings saved.');
    redirect('/superadmin/settings.php');
}

$ebayDefault = fn (string $k) => $k === 'ebay_marketplace' ? 'EBAY_US' : ($k === 'ebay_endpoint' ? 'https://api.ebay.com' : ($k === 'ebay_cache_hours' ? '12' : ''));
$tokenSet = setting('ebay_auth_token', '') !== '';

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

    <hr style="margin:26px 0 6px">
    <h2 style="margin-top:14px">🛒 eBay Partner Network</h2>
    <p class="sub">Your EPN <strong>Account SID + Auth Token</strong> and Campaign ID. These power affiliate
        tracking on deal links. (Running the live deal scanner also needs a Browse API keyset — see Advanced below.)</p>

    <?php foreach ($epnFields as $key => [$label, $help]):
        $isToken = $key === 'ebay_auth_token';
        $val = $isToken ? '' : setting($key, $ebayDefault($key));
    ?>
        <label><?= e($label) ?></label>
        <input name="<?= e($key) ?>" value="<?= e($val) ?>"<?= $isToken ? ' type="password" autocomplete="off" placeholder="' . ($tokenSet ? '•••••••• (saved — leave blank to keep)' : 'paste your Auth Token') . '"' : '' ?>>
        <p class="field-help"><?= e($help) ?></p>
    <?php endforeach; ?>

    <details style="margin-top:10px">
        <summary style="cursor:pointer;font-weight:600">Advanced — Browse API keyset (only for live deal scanning)</summary>
        <div style="margin-top:10px">
            <?php foreach ($browseFields as $key => [$label, $help]):
                $isSecret = $key === 'ebay_cert_id';
            ?>
                <label><?= e($label) ?></label>
                <input name="<?= e($key) ?>" value="<?= e(setting($key, '')) ?>"<?= $isSecret ? ' type="password" autocomplete="off"' : '' ?>>
                <p class="field-help"><?= e($help) ?></p>
            <?php endforeach; ?>
        </div>
    </details>

    <div style="margin-top:18px;display:flex;gap:10px;flex-wrap:wrap">
        <button class="btn btn-primary" type="submit">Save settings</button>
        <a class="btn" href="/superadmin/settings.php?test=ebay">⚡ Test eBay connection</a>
    </div>
</form>
<?php
layout_footer();
