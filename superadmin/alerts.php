<?php
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';
require __DIR__ . '/../src/layout.php';

use SportCard101\Auth;
use SportCard101\DealAlerts;
use SportCard101\Mailer;

Auth::requireAdmin();

$SPORTS     = card_sports();
$GRADE_NUMS = card_grade_nums();

$triggersReady = true;
try {
    $pdo->query('SELECT 1 FROM alert_triggers LIMIT 1');
} catch (\Throwable $e) {
    $triggersReady = false;
}

/** Human summary of a trigger's conditions (also used to auto-name). */
function trigger_summary(array $t, array $sports): string
{
    $p = [];
    $p[] = ($t['sport'] ?? 'all') === 'all' ? 'Any sport' : ($sports[$t['sport']]['label'] ?? $t['sport']);
    $p[] = ($t['grade'] ?? 'any') === 'any' ? 'any PSA grade' : ('PSA ' . $t['grade']);
    if (!empty($t['signed']))   $p[] = 'signed/auto';
    if (!empty($t['keywords'])) $p[] = '“' . $t['keywords'] . '”';
    if (($t['max_price'] ?? null) !== null && $t['max_price'] !== '') {
        $p[] = 'under $' . rtrim(rtrim(number_format((float)$t['max_price'], 2), '0'), '.');
    }
    $minU = $t['min_under_comp'] ?? null;
    if (!empty($t['require_comp']) && ($minU === null || $minU === '' || (float)$minU <= 0)) {
        $p[] = 'under comp';
    } elseif ($minU !== null && $minU !== '' && (float)$minU > 0) {
        $p[] = '≥' . rtrim(rtrim(number_format((float)$minU, 1), '0'), '.') . '% under comp';
    }
    if (($t['within_hours'] ?? null) !== null && $t['within_hours'] !== '') {
        $p[] = 'ends ≤' . rtrim(rtrim(number_format((float)$t['within_hours'], 1), '0'), '.') . 'h';
    }
    return implode(' · ', $p);
}

// ---- Test email --------------------------------------------------------
if (isset($_GET['test'])) {
    $to = trim((string) setting('notify_email', ''));
    if ($to === '') {
        flash('error', 'Add an alert email address and Save settings first.');
    } else {
        $ok = Mailer::send($to, 'SportCard101: test deal alert',
            "This is a test from your SportCard101 deal agent.\n\nIf you got this, email alerts work.\n");
        $how = (string) setting('smtp_host', '') !== '' ? 'via SMTP' : 'via PHP mail()';
        flash($ok ? 'success' : 'error', $ok
            ? "Test email sent to {$to} {$how}. Check your inbox (and spam)."
            : 'Send failed ' . $how . ': ' . (Mailer::$lastError ?? 'unknown error'));
    }
    redirect('/superadmin/alerts.php');
}

// ---- POST actions ------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';

    if ($action === 'save_settings') {
        $set = $pdo->prepare('INSERT INTO settings (skey, sval) VALUES (?, ?) ON DUPLICATE KEY UPDATE sval = VALUES(sval)');
        $set->execute(['notify_enabled', isset($_POST['notify_enabled']) ? '1' : '0']);
        foreach (['notify_email', 'notify_from', 'notify_from_name', 'smtp_host', 'smtp_port', 'smtp_secure', 'smtp_user'] as $k) {
            $set->execute([$k, trim((string)($_POST[$k] ?? ''))]);
        }
        // Password is secret — only overwrite when a new value is entered.
        $pw = (string)($_POST['smtp_pass'] ?? '');
        if ($pw !== '') {
            $set->execute(['smtp_pass', $pw]);
        }
        flash('success', 'Alert settings saved.');
        redirect('/superadmin/alerts.php');
    }

    if (($action === 'add_trigger' || $action === 'update_trigger') && $triggersReady) {
        $sport = isset($SPORTS[$_POST['sport'] ?? '']) ? (string)$_POST['sport'] : 'all';
        $grade = in_array($_POST['grade'] ?? '', $GRADE_NUMS, true) ? (string)$_POST['grade'] : 'any';
        $numOrNull = fn ($k) => ($_POST[$k] ?? '') === '' ? null : (float)$_POST[$k];
        $data = [
            'sport'          => $sport,
            'grade'          => $grade,
            'signed'         => isset($_POST['signed']) ? 1 : 0,
            'keywords'       => trim((string)($_POST['keywords'] ?? '')) ?: null,
            'max_price'      => $numOrNull('max_price'),
            'min_under_comp' => $numOrNull('min_under_comp'),
            'require_comp'   => isset($_POST['require_comp']) ? 1 : 0,
            'within_hours'   => $numOrNull('within_hours'),
        ];
        // Name is optional — auto-name from the conditions when blank.
        $label = trim((string)($_POST['label'] ?? ''));
        if ($label === '') {
            $label = trigger_summary($data, $SPORTS);
        }
        $label = mb_substr($label, 0, 120);

        if ($action === 'add_trigger') {
            $pdo->prepare(
                'INSERT INTO alert_triggers
                    (label, active, sport, grade, signed, keywords, max_price, min_under_comp, require_comp, within_hours)
                 VALUES (?,1,?,?,?,?,?,?,?,?)'
            )->execute([$label, $data['sport'], $data['grade'], $data['signed'], $data['keywords'],
                        $data['max_price'], $data['min_under_comp'], $data['require_comp'], $data['within_hours']]);
            flash('success', 'Trigger added.');
        } else {
            $pdo->prepare(
                'UPDATE alert_triggers SET label=?, sport=?, grade=?, signed=?, keywords=?,
                    max_price=?, min_under_comp=?, require_comp=?, within_hours=? WHERE id=?'
            )->execute([$label, $data['sport'], $data['grade'], $data['signed'], $data['keywords'],
                        $data['max_price'], $data['min_under_comp'], $data['require_comp'], $data['within_hours'],
                        (int)($_POST['id'] ?? 0)]);
            flash('success', 'Trigger updated.');
        }
        redirect('/superadmin/alerts.php');
    }

    if ($action === 'toggle' && $triggersReady) {
        $pdo->prepare('UPDATE alert_triggers SET active = 1 - active WHERE id = ?')->execute([(int)($_POST['id'] ?? 0)]);
    } elseif ($action === 'delete' && $triggersReady) {
        $pdo->prepare('DELETE FROM alert_triggers WHERE id = ?')->execute([(int)($_POST['id'] ?? 0)]);
        flash('success', 'Trigger deleted.');
    }
    redirect('/superadmin/alerts.php');
}

// ---- Load state --------------------------------------------------------
$enabled  = (string) setting('notify_enabled', '0') === '1';
$triggers = $triggersReady ? $pdo->query('SELECT * FROM alert_triggers ORDER BY active DESC, id DESC')->fetchAll() : [];
$compCount = 0;
try { $compCount = (int) $pdo->query('SELECT COUNT(*) FROM sold_comps')->fetchColumn(); } catch (\Throwable $e) {}

// Editing an existing trigger?
$editing = null;
if ($triggersReady && isset($_GET['edit'])) {
    $stmt = $pdo->prepare('SELECT * FROM alert_triggers WHERE id = ?');
    $stmt->execute([(int)$_GET['edit']]);
    $editing = $stmt->fetch() ?: null;
}
// Field values for the form (from $editing or blank).
$fv = fn (string $k, $d = '') => $editing !== null ? (($editing[$k] ?? '') === null ? '' : (string)$editing[$k]) : $d;
$chk = fn (string $k) => $editing !== null && !empty($editing[$k]);

// Dry-run preview: what would alert right now (ignores the once-only rule).
$preview = null;
if (isset($_GET['preview'])) {
    try { $preview = DealAlerts::evaluate($pdo, true); } catch (\Throwable $e) { $preview = null; }
}

layout_header('Deal Alerts', 'admin');
?>
<h1>🔔 Deal Alert Triggers</h1>
<p class="sub">Create as many triggers as you want — you're emailed when a PSA auction matches <em>any</em> active one. e.g. <em>“Baseball PSA 10, under $25, ending within 1 hour.”</em> Runs on every scan / cron.</p>

<?php if (!$triggersReady): ?>
    <div class="flash flash-error">The <code>alert_triggers</code> table isn't created yet. Run <code>migrations/2026_alert_triggers.sql</code> in phpMyAdmin, then reload.</div>
<?php endif; ?>

<!-- Add / edit trigger (top) -->
<h2 id="form" style="margin-top:0"><?= $editing ? 'Edit trigger' : 'Add a trigger' ?></h2>
<form method="post" class="searchbar triggerbar"><?= csrf_field() ?>
    <input type="hidden" name="action" value="<?= $editing ? 'update_trigger' : 'add_trigger' ?>">
    <?php if ($editing): ?><input type="hidden" name="id" value="<?= (int)$editing['id'] ?>"><?php endif; ?>

    <input name="label" class="searchbar-input" value="<?= e($fv('label')) ?>" placeholder="Trigger name (optional — auto-named)">
    <select name="sport" class="searchbar-select">
        <option value="all"<?= $fv('sport','all')==='all'?' selected':'' ?>>Any sport</option>
        <?php foreach ($SPORTS as $key => $meta): ?>
            <option value="<?= e($key) ?>"<?= $fv('sport')===$key?' selected':'' ?>><?= e($meta['emoji'].' '.$meta['label']) ?></option>
        <?php endforeach; ?>
    </select>
    <select name="grade" class="searchbar-select">
        <option value="any"<?= $fv('grade','any')==='any'?' selected':'' ?>>Any grade</option>
        <?php foreach ($GRADE_NUMS as $g): ?>
            <option value="<?= e($g) ?>"<?= $fv('grade')===$g?' selected':'' ?>>PSA <?= e($g) ?></option>
        <?php endforeach; ?>
    </select>
    <input name="max_price" type="number" step="0.01" min="0" class="searchbar-input tb-num" value="<?= e($fv('max_price')) ?>" placeholder="Max $">
    <input name="min_under_comp" type="number" step="0.1" min="0" class="searchbar-input tb-num" value="<?= e($fv('min_under_comp')) ?>" placeholder="% under comp">
    <input name="within_hours" type="number" step="0.5" min="0" class="searchbar-input tb-num" value="<?= e($fv('within_hours')) ?>" placeholder="Ends ≤ hrs">
    <input name="keywords" class="searchbar-input" value="<?= e($fv('keywords')) ?>" placeholder="Title keyword">
    <label class="tb-check"><input type="checkbox" name="signed" value="1" <?= $chk('signed')?'checked':'' ?>> ✍️ Signed</label>
    <label class="tb-check"><input type="checkbox" name="require_comp" value="1" <?= $chk('require_comp')?'checked':'' ?>> 📊 Under comp</label>
    <button class="btn-search" type="submit"><?= $editing ? 'Save changes' : 'Add trigger' ?></button>
    <?php if ($editing): ?><a class="btn btn-reset" href="/superadmin/alerts.php">Cancel</a><?php endif; ?>
</form>
<p class="field-help" style="max-width:900px">Leave any field blank to ignore it. A trigger fires only when <em>all</em> its set conditions are met; you're alerted if an auction matches <em>any</em> active trigger. “% under comp” / “Under comp” need sold-comp history for that card.</p>

<!-- Your triggers -->
<h2 style="margin-top:26px">Your triggers</h2>
<?php if (!$triggers): ?>
    <div class="empty" style="padding:30px">No triggers yet — add one above.</div>
<?php else: ?>
    <div class="trigger-list">
        <?php foreach ($triggers as $t): ?>
            <div class="trigger<?= $t['active'] ? '' : ' trigger-off' ?>">
                <div class="trigger-main">
                    <div class="trigger-label"><?= e($t['label']) ?> <?php if (!$t['active']): ?><span class="sub">(paused)</span><?php endif; ?></div>
                    <div class="trigger-conds"><?= e(trigger_summary($t, $SPORTS)) ?></div>
                </div>
                <div class="trigger-actions">
                    <a class="btn btn-sm" href="/superadmin/alerts.php?edit=<?= (int)$t['id'] ?>#form">Edit</a>
                    <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
                        <button class="btn btn-sm" type="submit"><?= $t['active'] ? 'Pause' : 'Resume' ?></button></form>
                    <form method="post" class="inline" onsubmit="return confirm('Delete this trigger?')"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
                        <button class="btn btn-sm btn-danger" type="submit">Delete</button></form>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php if ($preview !== null): ?>
<div class="card" id="preview" style="max-width:900px;margin-top:22px">
    <h2 style="margin-top:0">🔍 Dry-run preview</h2>
    <p class="sub" style="margin-bottom:12px">Checked <strong><?= (int)$preview['candidates'] ?></strong> live PSA auction(s) against <strong><?= (int)$preview['triggers'] ?></strong> active trigger(s). <strong><?= count($preview['matches']) ?></strong> would alert (this preview ignores the once-per-auction rule).</p>
    <?php if (!$preview['matches']): ?>
        <div class="flash flash-info" style="margin:0">
            Nothing matches right now. Common reasons:
            <?php if ((int)$preview['triggers'] === 0): ?><strong>no active triggers</strong> — add one above.
            <?php elseif ((int)$preview['candidates'] === 0): ?><strong>no live PSA auctions captured</strong> — go to <a href="/superadmin/auctions.php">Auctions</a> and run a Scan first.
            <?php else: ?>your triggers' thresholds are tighter than any current auction (price / % under comp / hours), or “under comp” triggers have no comp history yet.<?php endif; ?>
        </div>
    <?php else: ?>
        <table class="comps-table">
            <thead><tr><th>Card</th><th class="num">Bid</th><th class="num">Comp</th><th class="num">Under</th><th>Ends in</th><th>Matched trigger(s)</th></tr></thead>
            <tbody>
                <?php foreach (array_slice($preview['matches'], 0, 30) as $m): ?>
                    <tr>
                        <td><?= e($m['ai_card'] ?: $m['title']) ?></td>
                        <td class="num money"><?= money((float)$m['price'], $m['currency']) ?></td>
                        <td class="num"><?= !empty($m['comp']) ? money((float)$m['comp']['median']) : '—' ?></td>
                        <td class="num"><?= $m['under_pct'] !== null ? (int)$m['under_pct'] . '%' : '—' ?></td>
                        <td><?= (int)max(0, round((float)$m['hours_left'])) ?>h</td>
                        <td class="sub"><?= e(implode(', ', $m['triggers'])) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <p class="sub" style="margin-top:10px">✅ Matching works. If these aren't arriving by email, the issue is email delivery — use “Send test email” below to confirm.</p>
    <?php endif; ?>
</div>
<?php endif; ?>

<!-- Email alerts settings (bottom, collapsible) -->
<details class="card email-settings" style="max-width:760px;margin-top:26px"<?= (!$enabled || $preview !== null) ? ' open' : '' ?>>
    <summary>📧 Email alerts: <strong class="<?= $enabled ? 'on' : 'off' ?>"><?= $enabled ? 'ON' : 'OFF' ?></strong> <span class="sub">— click to show / hide settings</span></summary>

    <div class="stat-grid" style="margin:16px 0">
        <div class="stat"><div class="stat-num"><?= $enabled ? 'ON' : 'OFF' ?></div><div class="stat-label">Email alerts</div></div>
        <div class="stat"><div class="stat-num"><?= count(array_filter($triggers, fn ($t) => $t['active'])) ?></div><div class="stat-label">Active triggers</div></div>
        <div class="stat"><div class="stat-num"><?= number_format($compCount) ?></div><div class="stat-label">Sold comps</div></div>
    </div>

    <form method="post"><?= csrf_field() ?>
        <input type="hidden" name="action" value="save_settings">
        <label class="checkbox"><input type="checkbox" name="notify_enabled" value="1" <?= $enabled ? 'checked' : '' ?>> Email alerts turned on</label>
        <div class="row" style="margin-top:8px">
            <div><label>Send alerts to</label><input type="email" name="notify_email" value="<?= e((string)setting('notify_email','')) ?>" placeholder="you@example.com"></div>
            <div><label>“From” address</label><input name="notify_from" value="<?= e((string)setting('notify_from','')) ?>" placeholder="alerts@sportcard101.com"></div>
            <div><label>“From” name</label><input name="notify_from_name" value="<?= e((string)setting('notify_from_name','')) ?>" placeholder="SportCard101"></div>
        </div>

        <hr style="margin:20px 0 6px">
        <h3 style="margin:0 0 2px">📮 Email delivery (SMTP) <small style="color:var(--muted);font-weight:400">— fixes spam; recommended</small></h3>
        <p class="field-help" style="margin-top:2px">Leave SMTP host blank to use basic PHP mail() (often spam-filtered). To send from your Hostinger mailbox, create it in hPanel → Emails, then enter its details here. The “From” address above should equal the SMTP user.</p>
        <div class="row">
            <div><label>SMTP host</label><input name="smtp_host" value="<?= e((string)setting('smtp_host','')) ?>" placeholder="smtp.hostinger.com"></div>
            <div><label>Port</label><input name="smtp_port" value="<?= e((string)setting('smtp_port','587')) ?>" placeholder="587"></div>
            <div><label>Security</label>
                <select name="smtp_secure">
                    <?php $sec = (string)setting('smtp_secure','tls'); foreach (['tls'=>'STARTTLS (587)','ssl'=>'SSL/TLS (465)','none'=>'None'] as $v=>$lbl): ?>
                        <option value="<?= e($v) ?>"<?= $sec===$v?' selected':'' ?>><?= e($lbl) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <div class="row">
            <div><label>SMTP username (full email)</label><input name="smtp_user" value="<?= e((string)setting('smtp_user','')) ?>" placeholder="alerts@sportcard101.com"></div>
            <div><label>SMTP password</label><input type="password" name="smtp_pass" autocomplete="new-password" placeholder="<?= (string)setting('smtp_pass','')!=='' ? '•••••••• (saved — blank keeps it)' : 'mailbox password' ?>"></div>
        </div>

        <div style="margin-top:16px;display:flex;gap:10px;flex-wrap:wrap">
            <button class="btn btn-primary" type="submit">Save settings</button>
            <a class="btn" href="/superadmin/alerts.php?test=1">✉️ Send test email</a>
            <a class="btn" href="/superadmin/alerts.php?preview=1#preview">🔍 Preview matches (dry run)</a>
        </div>
    </form>
</details>
<?php
layout_footer();
