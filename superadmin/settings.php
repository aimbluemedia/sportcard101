<?php
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';
require __DIR__ . '/../src/layout.php';

use SportCard101\AiAnalyst;
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

// Anthropic (Claude) — powers every AI feature in the app.
$aiFields = [
    'ai_api_key'      => ['Anthropic API Key', 'From console.anthropic.com → API Keys (starts with sk-ant-). Secret — leave blank to keep the saved value.', 'secret'],
    'ai_model'        => ['Model', 'e.g. claude-opus-4-8. Larger models reason better on card identity; smaller ones cost less.'],
    'ai_max_per_scan' => ['Max listings per scan', 'Cost control: how many deal candidates get sent to Claude on each 30-minute scan.'],
];

$allFields = $siteFields + $epnFields + $devFields + $aiFields;
$secretKeys = ['ebay_auth_token', 'ebay_cert_id', 'ai_api_key'];

$defaults = [
    'ebay_marketplace' => 'EBAY_US',
    'ebay_endpoint'    => 'https://api.ebay.com',
    'ebay_cache_hours' => '12',
    'ai_model'         => 'claude-opus-4-8',
    'ai_max_per_scan'  => '15',
];

// Test the Browse keyset (powers the scanner) using the currently SAVED values.
if (isset($_GET['test']) && $_GET['test'] === 'ebay') {
    [$ok, $msg] = (new EbayClient(ebay_config($config['ebay'])))->testConnection();
    flash($ok ? 'success' : 'error', 'eBay: ' . $msg);
    redirect('/superadmin/settings.php');
}
if (isset($_GET['test']) && $_GET['test'] === 'ai') {
    [$ok, $msg] = (new AiAnalyst(ai_config($config['ai'])))->testConnection();
    flash($ok ? 'success' : 'error', 'Claude: ' . $msg);
    redirect('/superadmin/settings.php');
}

// Run a scan synchronously, right now, and report exactly what happened.
// Separates "the scan is broken" from "the schedule is broken".
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'run_scan') {
    csrf_verify();
    @set_time_limit(300);
    try {
        if (!class_exists(\SportCard101\ScanRunner::class)) {
            throw new \RuntimeException('ScanRunner class is missing — the latest code is not deployed.');
        }
        $r = \SportCard101\ScanRunner::run($pdo, $config);
        flash('success', sprintf(
            'Scan completed in %ss — %d deals flagged, %d sold comps, %d alerts sent, %d lots, %d error cards. The heartbeat below is now current.',
            $r['secs'], $r['deals'], $r['comps'], $r['alerts'], $r['lots']['found'], $r['error_alerts']
        ));
    } catch (\Throwable $e) {
        flash('error', 'Scan FAILED: ' . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')');
    }
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

<?php
// Cron heartbeat — cron.php stamps these on every run (expected every 30 min).
$cronLastRun = setting('cron_last_run');
$cronStatus  = setting('cron_last_status', '');
if ($cronLastRun) {
    $ageMin  = (int) floor((time() - strtotime($cronLastRun)) / 60);
    $late    = $ageMin > 40; // 30-min schedule + grace
    $color   = $late ? '#e05555' : '#3aa66a';
    $verdict = $late
        ? "⚠ Last run {$ageMin} min ago — expected every 30 min. Check the cron job on your host."
        : "✓ On schedule — last run {$ageMin} min ago (fires every 30 min).";
} else {
    $color   = '#e0a935';
    $verdict = '⚠ Cron has never run. Set up the cron job on your host (see command below).';
}
?>
<div class="card" style="max-width:780px;border-left:4px solid <?= $color ?>">
    <strong>Automatic scanning (cron)</strong>
    <p style="margin:6px 0 0"><?= e($verdict) ?></p>
    <?php if ($cronLastRun): ?>
        <p style="margin:6px 0 0;color:var(--muted)">
            Last run: <?= e(date('M j, g:ia', strtotime($cronLastRun))) ?>
            <?php if ($cronStatus): ?> · <?= e($cronStatus) ?><?php endif; ?>
        </p>
    <?php endif; ?>
    <p style="margin:6px 0 0;color:var(--muted)">Hostinger cron (type: <strong>PHP</strong>, every 30 min — minute <code>0,30</code>):
        <code>public_html/cron.php YOUR_SECRET</code> · daily playbook (7am): <code>public_html/cron.php YOUR_SECRET daily</code>
    </p>
    <?php
    // Which self-heal methods this host actually supports — the fallback used
    // to fail silently when exec() was disabled, so state it plainly.
    $canExec   = function_exists('exec');
    $canFinish = function_exists('fastcgi_finish_request') || function_exists('litespeed_finish_request');
    $fbNote    = (string) setting('cron_fallback_note', '');
    ?>
    <p style="margin:10px 0 0;color:var(--muted)">
        <strong>Backup (traffic fallback):</strong> if the host cron stalls, a page visit runs the scan itself after 35 quiet minutes.
        This host supports:
        <span style="color:<?= $canExec ? '#3aa66a' : 'var(--muted)' ?>"><?= $canExec ? '✓' : '✕' ?> background process (exec)</span> ·
        <span style="color:<?= $canFinish ? '#3aa66a' : 'var(--muted)' ?>"><?= $canFinish ? '✓' : '✕' ?> inline after page delivery</span>
        <?php if (!$canExec && !$canFinish): ?>
            <br><strong style="color:#e05555">Neither is available — page traffic cannot heal the schedule on this host. Use an external cron service (see below).</strong>
        <?php endif; ?>
    </p>
    <?php if ($fbNote !== ''): ?>
        <p style="margin:6px 0 0;color:var(--muted)">Last fallback attempt: <?= e($fbNote) ?></p>
    <?php endif; ?>
    <p style="margin:10px 0 0;color:var(--muted)"><small><strong>Most reliable option:</strong> an external cron service (e.g. cron-job.org) hitting
        <code>https://sportcard101.com/cron.php?key=YOUR_SECRET</code> every 30 minutes, and the same URL with <code>&amp;task=daily</code> once at 7am.
        It doesn't depend on the host's scheduler at all.</small></p>

    <hr style="margin:18px 0 12px;border:0;border-top:1px solid var(--border)">
    <?php
    // --- Diagnostics: facts about THIS server, so scheduling problems can be
    // diagnosed from the page instead of guessed at.
    $deployed  = class_exists(\SportCard101\ScanRunner::class);
    $disabled  = trim((string) @ini_get('disable_functions'));
    $bootMtime = @filemtime(APP_ROOT . '/src/bootstrap.php');
    $lockDir   = sys_get_temp_dir();
    $diag = [
        'Code deployed (ScanRunner present)' => $deployed ? '✓ yes' : '✕ NO — deploy the latest code',
        'bootstrap.php last changed'         => $bootMtime ? date('M j, Y g:ia', $bootMtime) : 'unknown',
        'PHP'                                => PHP_VERSION . ' (' . PHP_SAPI . ')',
        'exec()'                             => function_exists('exec') ? '✓ available' : '✕ disabled',
        'fastcgi_finish_request()'           => function_exists('fastcgi_finish_request') ? '✓ available' : '✕ missing',
        'litespeed_finish_request()'         => function_exists('litespeed_finish_request') ? '✓ available' : '✕ missing',
        'max_execution_time'                 => (string) @ini_get('max_execution_time') . 's',
        'Lock dir writable'                  => is_writable($lockDir) ? '✓ ' . $lockDir : '✕ ' . $lockDir,
        'Server time'                        => date('M j, Y g:ia T'),
        'disable_functions'                  => $disabled === '' ? '(none)' : $disabled,
    ];
    ?>
    <details class="form-toggle">
        <summary class="btn">🩺 Server diagnostics</summary>
        <div style="overflow-x:auto;margin-top:10px"><table>
            <?php foreach ($diag as $k => $v): ?>
            <tr>
                <td style="white-space:nowrap;color:var(--muted)"><?= e($k) ?></td>
                <td style="word-break:break-word"><?= e((string)$v) ?></td>
            </tr>
            <?php endforeach; ?>
        </table></div>
        <p style="margin:10px 0 0;color:var(--muted)"><small>If "Code deployed" says NO, deploy first — everything else below it is stale. If both <code>exec()</code> and the two <code>finish_request</code> functions are unavailable, page traffic can't run scans on this host and an external cron service is the only fix.</small></p>
    </details>

    <form method="post" style="margin-top:12px" onsubmit="this.querySelector('button').disabled=true;this.querySelector('button').textContent='Running scan… up to 60s, please wait';">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="run_scan">
        <button class="btn btn-primary" type="submit">▶ Run a scan now (and show me what happens)</button>
        <span style="color:var(--muted);margin-left:8px"><small>Runs synchronously — you'll wait ~30s and see the result or the exact error.</small></span>
    </form>
</div>

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

    <hr style="margin:26px 0 8px">
    <?php $aiLive = (string) setting('ai_api_key', (string)($config['ai']['api_key'] ?? '')) !== ''; ?>
    <h2>🤖 Claude API <small style="color:var(--muted);font-weight:400">— powers every AI feature</small></h2>
    <p class="sub">
        API key from <a href="https://console.anthropic.com/settings/keys" target="_blank" rel="noopener">console.anthropic.com</a>.
        Drives deal verdicts and hidden gems, card-name normalisation, the Morning Playbook narrative,
        bulk-lot valuation, and error-card explanations.
        Status: <strong style="color:<?= $aiLive ? '#3aa66a' : '#e0a935' ?>"><?= $aiLive ? 'live' : 'mock mode — heuristics only' ?></strong>.
    </p>
    <?php foreach ($aiFields as $key => $def):
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
    <p class="field-help">Without a key the app still runs — it falls back to deterministic heuristics, and AI-only features (error explanations, playbook narrative, lot valuation) stay quiet rather than guessing.</p>

    <div style="margin-top:20px;display:flex;gap:10px;flex-wrap:wrap">
        <button class="btn btn-primary" type="submit">Save settings</button>
        <a class="btn" href="/superadmin/settings.php?test=ebay">⚡ Test eBay connection</a>
        <a class="btn" href="/superadmin/settings.php?test=ai">🤖 Test Claude connection</a>
    </div>
</form>
<?php
layout_footer();
