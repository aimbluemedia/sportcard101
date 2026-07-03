<?php
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';
require __DIR__ . '/../src/layout.php';

use SportCard101\Auth;

Auth::requireAdmin();

$SPORTS     = card_sports();
$GRADE_NUMS = card_grade_nums();

$triggersReady = true;
try {
    $pdo->query('SELECT 1 FROM alert_triggers LIMIT 1');
} catch (\Throwable $e) {
    $triggersReady = false; // table not migrated yet
}

// ---- Test email --------------------------------------------------------
if (isset($_GET['test'])) {
    $to = trim((string) setting('notify_email', ''));
    if ($to === '') {
        flash('error', 'Add an alert email address and Save settings first.');
    } else {
        $from = (string) setting('notify_from', '') ?: 'alerts@sportcard101.com';
        $ok = @mail($to, 'SportCard101: test deal alert',
            "This is a test from your SportCard101 deal agent.\n\nIf you got this, email alerts work.\n",
            'From: ' . $from . "\r\nContent-Type: text/plain; charset=UTF-8\r\n");
        flash($ok ? 'success' : 'error', $ok
            ? "Test email sent to {$to}. Check your inbox (and spam)."
            : 'PHP mail() could not send — on Hostinger this can be blocked; consider SMTP.');
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
        $set->execute(['notify_email', trim((string)($_POST['notify_email'] ?? ''))]);
        $set->execute(['notify_from', trim((string)($_POST['notify_from'] ?? ''))]);
        flash('success', 'Alert settings saved.');
    } elseif ($action === 'add_trigger' && $triggersReady) {
        $label = trim((string)($_POST['label'] ?? ''));
        if ($label === '') {
            flash('error', 'Give the trigger a name.');
        } else {
            $sport = isset($SPORTS[$_POST['sport'] ?? '']) ? (string)$_POST['sport'] : 'all';
            $grade = in_array($_POST['grade'] ?? '', $GRADE_NUMS, true) ? (string)$_POST['grade'] : 'any';
            $numOrNull = fn ($k) => ($_POST[$k] ?? '') === '' ? null : (float)$_POST[$k];
            $pdo->prepare(
                'INSERT INTO alert_triggers
                    (label, active, sport, grade, signed, keywords, max_price, min_under_comp, require_comp, within_hours)
                 VALUES (?,1,?,?,?,?,?,?,?,?)'
            )->execute([
                mb_substr($label, 0, 120),
                $sport,
                $grade,
                isset($_POST['signed']) ? 1 : 0,
                trim((string)($_POST['keywords'] ?? '')) ?: null,
                $numOrNull('max_price'),
                $numOrNull('min_under_comp'),
                isset($_POST['require_comp']) ? 1 : 0,
                $numOrNull('within_hours'),
            ]);
            flash('success', 'Trigger added.');
        }
    } elseif ($action === 'toggle' && $triggersReady) {
        $pdo->prepare('UPDATE alert_triggers SET active = 1 - active WHERE id = ?')->execute([(int)($_POST['id'] ?? 0)]);
    } elseif ($action === 'delete' && $triggersReady) {
        $pdo->prepare('DELETE FROM alert_triggers WHERE id = ?')->execute([(int)($_POST['id'] ?? 0)]);
        flash('success', 'Trigger deleted.');
    }
    redirect('/superadmin/alerts.php');
}

$enabled = (string) setting('notify_enabled', '0') === '1';
$triggers = $triggersReady ? $pdo->query('SELECT * FROM alert_triggers ORDER BY active DESC, id DESC')->fetchAll() : [];
$compCount = 0;
try { $compCount = (int) $pdo->query('SELECT COUNT(*) FROM sold_comps')->fetchColumn(); } catch (\Throwable $e) {}

/** Human summary of a trigger's conditions. */
function trigger_summary(array $t, array $sports): string
{
    $p = [];
    $p[] = $t['sport'] === 'all' ? 'Any sport' : ($sports[$t['sport']]['label'] ?? $t['sport']);
    $p[] = $t['grade'] === 'any' ? 'any PSA grade' : ('PSA ' . $t['grade']);
    if (!empty($t['signed']))                         $p[] = 'signed/auto';
    if (!empty($t['keywords']))                       $p[] = '“' . $t['keywords'] . '”';
    if ($t['max_price'] !== null)                     $p[] = 'under $' . rtrim(rtrim(number_format((float)$t['max_price'], 2), '0'), '.');
    if (!empty($t['require_comp']) && ($t['min_under_comp'] === null || (float)$t['min_under_comp'] <= 0)) {
        $p[] = 'under comp';
    } elseif ($t['min_under_comp'] !== null && (float)$t['min_under_comp'] > 0) {
        $p[] = '≥' . rtrim(rtrim(number_format((float)$t['min_under_comp'], 1), '0'), '.') . '% under comp';
    }
    if ($t['within_hours'] !== null)                  $p[] = 'ends ≤' . rtrim(rtrim(number_format((float)$t['within_hours'], 1), '0'), '.') . 'h';
    return implode(' · ', $p);
}

layout_header('Deal Alerts', 'admin');
?>
<h1>🔔 Deal Alert Triggers</h1>
<p class="sub">Create as many triggers as you like — you'll get an email when a PSA auction matches <em>any</em> active one. e.g. “Signed PSA 10 under $25” or “Any PSA 10 under comp”. Runs on every scan / cron.</p>

<div class="stat-grid" style="margin-bottom:20px">
    <div class="stat"><div class="stat-num"><?= $enabled ? 'ON' : 'OFF' ?></div><div class="stat-label">Email alerts</div></div>
    <div class="stat"><div class="stat-num"><?= count(array_filter($triggers, fn ($t) => $t['active'])) ?></div><div class="stat-label">Active triggers</div></div>
    <div class="stat"><div class="stat-num"><?= number_format($compCount) ?></div><div class="stat-label">Sold comps</div></div>
</div>

<?php if (!$triggersReady): ?>
    <div class="flash flash-error">The <code>alert_triggers</code> table isn't created yet. Run <code>migrations/2026_alert_triggers.sql</code> in phpMyAdmin, then reload.</div>
<?php endif; ?>

<!-- Master email settings -->
<form method="post" class="card" style="max-width:720px;margin-bottom:18px"><?= csrf_field() ?>
    <input type="hidden" name="action" value="save_settings">
    <label class="checkbox"><input type="checkbox" name="notify_enabled" value="1" <?= $enabled ? 'checked' : '' ?>> Email alerts turned on</label>
    <label>Send alerts to</label>
    <input type="email" name="notify_email" value="<?= e((string)setting('notify_email','')) ?>" placeholder="you@example.com">
    <label>“From” address (optional)</label>
    <input name="notify_from" value="<?= e((string)setting('notify_from','')) ?>" placeholder="alerts@sportcard101.com">
    <div style="margin-top:16px;display:flex;gap:10px;flex-wrap:wrap">
        <button class="btn btn-primary" type="submit">Save settings</button>
        <a class="btn" href="/superadmin/alerts.php?test=1">✉️ Send test email</a>
    </div>
</form>

<!-- Existing triggers -->
<h2>Your triggers</h2>
<?php if (!$triggers): ?>
    <div class="empty" style="padding:30px">No triggers yet — add one below.</div>
<?php else: ?>
    <div class="trigger-list">
        <?php foreach ($triggers as $t): ?>
            <div class="trigger<?= $t['active'] ? '' : ' trigger-off' ?>">
                <div class="trigger-main">
                    <div class="trigger-label"><?= e($t['label']) ?> <?php if (!$t['active']): ?><span class="sub">(paused)</span><?php endif; ?></div>
                    <div class="trigger-conds"><?= e(trigger_summary($t, $SPORTS)) ?></div>
                </div>
                <div class="trigger-actions">
                    <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
                        <button class="btn btn-sm" type="submit"><?= $t['active'] ? 'Pause' : 'Resume' ?></button></form>
                    <form method="post" class="inline" onsubmit="return confirm('Delete this trigger?')"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
                        <button class="btn btn-sm btn-danger" type="submit">Delete</button></form>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<!-- Add trigger -->
<h2 style="margin-top:28px">Add a trigger</h2>
<form method="post" class="card" style="max-width:720px"><?= csrf_field() ?>
    <input type="hidden" name="action" value="add_trigger">
    <label>Name</label>
    <input name="label" placeholder="e.g. Signed PSA 10 under $25" required>
    <div class="row">
        <div>
            <label>Sport</label>
            <select name="sport">
                <option value="all">Any sport</option>
                <?php foreach ($SPORTS as $key => $meta): ?><option value="<?= e($key) ?>"><?= e($meta['emoji'].' '.$meta['label']) ?></option><?php endforeach; ?>
            </select>
        </div>
        <div>
            <label>PSA grade</label>
            <select name="grade">
                <option value="any">Any grade</option>
                <?php foreach ($GRADE_NUMS as $g): ?><option value="<?= e($g) ?>">PSA <?= e($g) ?></option><?php endforeach; ?>
            </select>
        </div>
    </div>
    <div class="row">
        <div><label>Max price ($, optional)</label><input name="max_price" type="number" step="0.01" min="0" placeholder="e.g. 25"></div>
        <div><label>Min % under comp (optional)</label><input name="min_under_comp" type="number" step="0.1" min="0" placeholder="e.g. 20"></div>
        <div><label>Ends within (hours, optional)</label><input name="within_hours" type="number" step="0.5" min="0" placeholder="e.g. 8"></div>
    </div>
    <label>Title keyword (optional)</label>
    <input name="keywords" placeholder="e.g. Jordan, rookie, Topps Chrome">
    <label class="checkbox"><input type="checkbox" name="signed" value="1"> Only signed / autograph cards</label>
    <label class="checkbox"><input type="checkbox" name="require_comp" value="1"> Only when priced under its comp (needs comp history)</label>
    <div style="margin-top:16px"><button class="btn btn-primary" type="submit">Add trigger</button></div>
    <p class="field-help" style="margin-top:12px">Leave a field blank to ignore it. A trigger fires only when <em>all</em> its set conditions are met; you're alerted if an auction matches <em>any</em> active trigger. “Min % under comp” or “under comp” need sold-comp history for that card.</p>
</form>
<?php
layout_footer();
