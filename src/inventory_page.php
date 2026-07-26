<?php
declare(strict_types=1);

/**
 * Shared "My Collection" page — included by member/inventory.php (member
 * layout) and superadmin/mycards.php (admin layout). The caller must set:
 *   $INV_OWNER int    the user whose collection is shown (always the viewer)
 *   $INV_AREA  string 'member' | 'admin'   (layout chrome)
 *   $INV_SELF  string this page's URL, for form posts and redirects
 * Collections are strictly per-owner — no cross-user access here.
 */

use SportCard101\Inventory;

Inventory::ensureTables($pdo);

$SPORTS = card_sports();
$GRADE_NUMS = card_grade_nums();

// ---- Actions ---------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = (string)($_POST['action'] ?? '');
    $id     = (int)($_POST['id'] ?? 0);
    $numOrNull = fn ($k) => ($_POST[$k] ?? '') === '' ? null : round((float)$_POST[$k], 2);
    $strOrNull = fn ($k) => trim((string)($_POST[$k] ?? '')) ?: null;

    if ($action === 'add' || $action === 'update') {
        $name = trim((string)($_POST['card_name'] ?? ''));
        if ($name === '') {
            flash('error', 'Card name is required.');
            redirect($INV_SELF);
        }
        [$cardKey, $baseKey] = Inventory::keysFor($name);
        $company = in_array($_POST['grade_company'] ?? 'PSA', Inventory::COMPANIES, true) ? (string)$_POST['grade_company'] : 'PSA';
        $status  = isset(Inventory::STATUSES[$_POST['status'] ?? '']) ? (string)$_POST['status'] : 'GRADED';
        $vals = [
            mb_substr($name, 0, 250),
            isset($SPORTS[$_POST['sport'] ?? '']) ? (string)$_POST['sport'] : null,
            $strOrNull('year'), $strOrNull('set_name'), $strOrNull('player'),
            $strOrNull('card_number'), $strOrNull('parallel'),
            $company,
            $company === 'RAW' ? null : (in_array($_POST['grade'] ?? '', $GRADE_NUMS, true) ? (string)$_POST['grade'] : null),
            $strOrNull('cert_number'),
            max(0.0, (float)($_POST['card_cost'] ?? 0)),
            max(0.0, (float)($_POST['ship_cost'] ?? 0)),
            $strOrNull('purchase_source'),
            $strOrNull('purchased_at') ?: date('Y-m-d'),
            $status,
            $strOrNull('location'), $strOrNull('image_url'),
            $strOrNull('notes') !== null ? mb_substr((string)$strOrNull('notes'), 0, 1000) : null,
            $cardKey, $baseKey,
        ];
        if ($action === 'add') {
            $pdo->prepare(
                'INSERT INTO inventory (user_id, card_name, sport, year, set_name, player, card_number, parallel,
                    grade_company, grade, cert_number, card_cost, ship_cost, purchase_source, purchased_at, status,
                    location, image_url, notes, card_key, base_key)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
            )->execute([$INV_OWNER, ...$vals]);
            flash('success', 'Card added to your collection.');
        } else {
            $pdo->prepare(
                'UPDATE inventory SET card_name=?, sport=?, year=?, set_name=?, player=?, card_number=?, parallel=?,
                    grade_company=?, grade=?, cert_number=?, card_cost=?, ship_cost=?, purchase_source=?, purchased_at=?,
                    status=?, location=?, image_url=?, notes=?, card_key=?, base_key=?
                 WHERE id=? AND user_id=?'
            )->execute([...$vals, $id, $INV_OWNER]);
            flash('success', 'Card updated.');
        }
    } elseif ($action === 'to_grader') {
        $pdo->prepare("UPDATE inventory SET status='AT_GRADER' WHERE id=? AND user_id=? AND status='RAW'")->execute([$id, $INV_OWNER]);
        flash('success', 'Marked as sent to the grader. 🤞');
    } elseif ($action === 'graded') {
        $grade = in_array($_POST['grade'] ?? '', $GRADE_NUMS, true) ? (string)$_POST['grade'] : null;
        $pdo->prepare("UPDATE inventory SET status='GRADED', grade=COALESCE(?, grade) WHERE id=? AND user_id=? AND status='AT_GRADER'")
            ->execute([$grade, $id, $INV_OWNER]);
        flash('success', 'Back from grading — congrats on the slab.');
    } elseif ($action === 'listed') {
        $price = $numOrNull('list_price');
        $pdo->prepare("UPDATE inventory SET status='LISTED', list_price=?, listed_at=CURDATE() WHERE id=? AND user_id=? AND status IN ('RAW','GRADED')")
            ->execute([$price, $id, $INV_OWNER]);
        flash('success', 'Marked as listed.');
    } elseif ($action === 'sold') {
        $price = $numOrNull('sold_price');
        if ($price === null || $price <= 0) {
            flash('error', 'Enter the sale price.');
        } else {
            $pdo->prepare("UPDATE inventory SET status='SOLD', sold_price=?, sold_fees=?, sold_ship=?, sold_at=COALESCE(?, CURDATE())
                           WHERE id=? AND user_id=? AND status <> 'SOLD'")
                ->execute([$price, $numOrNull('sold_fees'), $numOrNull('sold_ship'), $strOrNull('sold_at'), $id, $INV_OWNER]);
            flash('success', 'Sold — P&L updated. 🎉');
        }
    } elseif ($action === 'delete') {
        $pdo->prepare('DELETE FROM inventory WHERE id=? AND user_id=?')->execute([$id, $INV_OWNER]);
        flash('success', 'Card removed.');
    }
    redirect($INV_SELF);
}

// ---- Data ------------------------------------------------------------------
$statusFilter = isset(Inventory::STATUSES[$_GET['status'] ?? '']) ? (string)$_GET['status'] : 'all';
$editing = null;
if (isset($_GET['edit'])) {
    $stmt = $pdo->prepare('SELECT * FROM inventory WHERE id=? AND user_id=?');
    $stmt->execute([(int)$_GET['edit'], $INV_OWNER]);
    $editing = $stmt->fetch() ?: null;
}

$stmt = $pdo->prepare(
    'SELECT * FROM inventory WHERE user_id = ?' .
    ($statusFilter !== 'all' ? ' AND status = ' . $pdo->quote($statusFilter) : '') .
    " ORDER BY status='SOLD', created_at DESC LIMIT 500"
);
$stmt->execute([$INV_OWNER]);
$rows = Inventory::value($pdo, $stmt->fetchAll());
$pf   = Inventory::portfolio($rows);

$fv = fn (string $k, string $d = '') => e((string)($editing[$k] ?? $d));

layout_header('My Collection', $INV_AREA);
?>
<h1>🗃️ My Collection</h1>
<p class="sub">Your cards, costs, and sales — valued live against SportCard101's sold-comp database. Quick-add takes 10 seconds; details can come later.</p>

<div class="card" style="margin-bottom:16px">
    <div style="display:flex;gap:26px;flex-wrap:wrap">
        <div><small style="color:var(--muted)">Active cards</small><br><strong style="font-size:1.3rem"><?= (int)$pf['active'] ?></strong></div>
        <div><small style="color:var(--muted)">Invested (cost + ship)</small><br><strong style="font-size:1.3rem">$<?= number_format($pf['invested'], 2) ?></strong></div>
        <div><small style="color:var(--muted)">Est. value (<?= (int)$pf['valued'] ?> of <?= (int)$pf['active'] ?> valued)</small><br><strong style="font-size:1.3rem"><?= $pf['valued'] ? '$' . number_format($pf['est'], 2) : '—' ?></strong></div>
        <div><small style="color:var(--muted)">Unrealized P&amp;L (valued cards)</small><br><strong style="font-size:1.3rem;color:<?= $pf['unrealized'] >= 0 ? '#1d7d46' : '#e05555' ?>"><?= $pf['valued'] ? '$' . number_format($pf['unrealized'], 2) : '—' ?></strong></div>
        <div><small style="color:var(--muted)">Realized P&amp;L (<?= (int)$pf['sold'] ?> sold)</small><br><strong style="font-size:1.3rem;color:<?= $pf['realized'] >= 0 ? '#1d7d46' : '#e05555' ?>">$<?= number_format($pf['realized'], 2) ?></strong></div>
    </div>
    <p style="margin:10px 0 0;color:var(--muted)"><small>Valuations use the median of real tracked auction sales (90 days) for your exact card and grade — PSA-graded cards only for now. "—" means the market hasn't produced enough sales yet; the database grows every 30 minutes.</small></p>
</div>

<div class="card" style="margin-bottom:16px">
    <h2 style="margin-top:0"><?= $editing ? 'Edit card' : 'Add a card' ?></h2>
    <form method="post"><?= csrf_field() ?>
        <input type="hidden" name="action" value="<?= $editing ? 'update' : 'add' ?>">
        <?php if ($editing): ?><input type="hidden" name="id" value="<?= (int)$editing['id'] ?>"><?php endif; ?>

        <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
            <input name="card_name" style="flex:2;min-width:260px" placeholder="Card (e.g. 2018 Prizm Luka Dončić Rookie #280 Silver)" value="<?= $fv('card_name') ?>" required>
            <input name="card_cost" type="number" step="0.01" min="0" style="width:110px" placeholder="card $" value="<?= $fv('card_cost') ?>">
            <input name="ship_cost" type="number" step="0.01" min="0" style="width:100px" placeholder="ship $" value="<?= $fv('ship_cost') ?>">
            <select name="status">
                <?php foreach (Inventory::STATUSES as $k => $label): if ($k === 'SOLD' && !$editing) continue; ?>
                    <option value="<?= e($k) ?>"<?= ($editing['status'] ?? 'GRADED') === $k ? ' selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
            <button class="btn btn-primary" type="submit"><?= $editing ? 'Save card' : 'Add card' ?></button>
            <?php if ($editing): ?><a class="btn" href="<?= e($INV_SELF) ?>">Cancel</a><?php endif; ?>
        </div>

        <details style="margin-top:12px"<?= $editing ? ' open' : '' ?>>
            <summary style="cursor:pointer;color:var(--muted)">More details (all optional)</summary>
            <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:10px;margin-top:12px">
                <div><label>Sport</label><select name="sport"><option value="">—</option>
                    <?php foreach ($SPORTS as $k => $m): ?><option value="<?= e($k) ?>"<?= ($editing['sport'] ?? '') === $k ? ' selected' : '' ?>><?= e($m['emoji'] . ' ' . $m['label']) ?></option><?php endforeach; ?>
                </select></div>
                <div><label>Year</label><input name="year" value="<?= $fv('year') ?>" placeholder="2018"></div>
                <div><label>Set / brand</label><input name="set_name" value="<?= $fv('set_name') ?>" placeholder="Panini Prizm"></div>
                <div><label>Player</label><input name="player" value="<?= $fv('player') ?>" placeholder="Luka Dončić"></div>
                <div><label>Card #</label><input name="card_number" value="<?= $fv('card_number') ?>" placeholder="#280"></div>
                <div><label>Parallel / variation</label><input name="parallel" value="<?= $fv('parallel') ?>" placeholder="Silver"></div>
                <div><label>Grading company</label><select name="grade_company">
                    <?php foreach (Inventory::COMPANIES as $c): ?><option value="<?= e($c) ?>"<?= ($editing['grade_company'] ?? 'PSA') === $c ? ' selected' : '' ?>><?= e($c === 'RAW' ? 'Raw (ungraded)' : $c) ?></option><?php endforeach; ?>
                </select></div>
                <div><label>Grade</label><select name="grade"><option value="">—</option>
                    <?php foreach ($GRADE_NUMS as $g): ?><option value="<?= e($g) ?>"<?= ($editing['grade'] ?? '') === $g ? ' selected' : '' ?>><?= e($g) ?></option><?php endforeach; ?>
                </select></div>
                <div><label>Cert number</label><input name="cert_number" value="<?= $fv('cert_number') ?>" placeholder="71984062"></div>
                <div><label>Purchase source</label><input name="purchase_source" value="<?= $fv('purchase_source') ?>" placeholder="eBay / card show"></div>
                <div><label>Purchase date</label><input name="purchased_at" type="date" value="<?= $fv('purchased_at', date('Y-m-d')) ?>"></div>
                <div><label>Storage location</label><input name="location" value="<?= $fv('location') ?>" placeholder="Box 3, row 2"></div>
                <div style="grid-column:1/-1"><label>Photo URL</label><input name="image_url" value="<?= $fv('image_url') ?>" placeholder="https://…"></div>
                <div style="grid-column:1/-1"><label>Notes</label><input name="notes" value="<?= $fv('notes') ?>" placeholder="corner ding top-left"></div>
            </div>
        </details>
    </form>
</div>

<form method="get" action="<?= e($INV_SELF) ?>" class="searchbar" style="margin-bottom:12px">
    <select name="status" onchange="this.form.submit()">
        <option value="all">All statuses</option>
        <?php foreach (Inventory::STATUSES as $k => $label): ?>
            <option value="<?= e($k) ?>"<?= $statusFilter === $k ? ' selected' : '' ?>><?= e($label) ?></option>
        <?php endforeach; ?>
    </select>
</form>

<?php
$activeRows = array_values(array_filter($rows, fn ($r) => $r['status'] !== 'SOLD'));
$soldRows   = array_values(array_filter($rows, fn ($r) => $r['status'] === 'SOLD'));
?>
<?php if (!$rows): ?>
    <div class="empty" style="padding:30px">No cards yet — add your first one above. Everything except the name is optional.</div>
<?php endif; ?>

<?php if ($activeRows): ?>
<div class="card" style="margin-bottom:16px">
    <h2 style="margin-top:0">📦 My cards (<?= count($activeRows) ?>)</h2>
    <div style="overflow-x:auto"><table>
        <tr><th style="min-width:220px">Card</th><th>Grade</th><th>All-in cost</th><th>Est. value</th><th>Paper P&amp;L</th><th>Status</th><th style="min-width:330px">Actions</th></tr>
        <?php foreach ($activeRows as $r):
            $allIn  = Inventory::allIn($r);
            $unreal = $r['live_value'] !== null ? (float)$r['live_value'] - $allIn : null; ?>
        <tr>
            <td><strong><?= e((string)$r['card_name']) ?></strong>
                <?php if ($r['location']): ?><br><small style="color:var(--muted)">📍 <?= e((string)$r['location']) ?></small><?php endif; ?></td>
            <td style="white-space:nowrap"><?= e($r['grade_company'] === 'RAW' ? 'Raw' : $r['grade_company'] . ' ' . (string)($r['grade'] ?? '?')) ?></td>
            <td style="white-space:nowrap">$<?= number_format($allIn, 2) ?><br><small style="color:var(--muted)">$<?= number_format((float)$r['card_cost'], 2) ?> + $<?= number_format((float)$r['ship_cost'], 2) ?> ship</small></td>
            <td style="white-space:nowrap"><?php if ($r['live_value'] !== null): ?>
                    <strong>$<?= number_format((float)$r['live_value'], 2) ?></strong> <small style="color:var(--muted)">(<?= (int)$r['live_comp_count'] ?> sales)</small><br>
                    <small><a href="<?= e(ebay_sold_link((string)$r['card_name'])) ?>" target="_blank" rel="noopener">sold prices ›</a></small>
                <?php else: ?><span style="color:var(--muted)">not enough sales yet</span><?php endif; ?></td>
            <td style="white-space:nowrap"><?= $unreal !== null
                ? '<strong style="color:' . ($unreal >= 0 ? '#1d7d46' : '#e05555') . '">$' . number_format($unreal, 2) . '</strong>'
                : '—' ?></td>
            <td style="white-space:nowrap"><?= e(Inventory::STATUSES[$r['status']] ?? $r['status']) ?><?= $r['status'] === 'LISTED' && $r['list_price'] !== null ? '<br><small style="color:var(--muted)">@ $' . number_format((float)$r['list_price'], 2) . '</small>' : '' ?></td>
            <td>
                <div style="display:flex;gap:6px;flex-wrap:wrap;align-items:center;margin-bottom:6px">
                    <?php if ($r['status'] === 'RAW'): ?>
                        <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="action" value="to_grader"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><button class="btn btn-sm" type="submit">Sent to grader</button></form>
                    <?php elseif ($r['status'] === 'AT_GRADER'): ?>
                        <form method="post" class="inline" style="display:flex;gap:4px"><?= csrf_field() ?><input type="hidden" name="action" value="graded"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                            <select name="grade" style="width:70px"><option value="">grade</option><?php foreach ($GRADE_NUMS as $g): ?><option value="<?= e($g) ?>"><?= e($g) ?></option><?php endforeach; ?></select>
                            <button class="btn btn-sm" type="submit">Came back</button></form>
                    <?php endif; ?>
                    <?php if (in_array($r['status'], ['RAW', 'GRADED'], true)): ?>
                        <form method="post" class="inline" style="display:flex;gap:4px"><?= csrf_field() ?><input type="hidden" name="action" value="listed"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                            <input name="list_price" type="number" step="0.01" min="0" placeholder="list $" style="width:80px">
                            <button class="btn btn-sm" type="submit">Listed</button></form>
                    <?php endif; ?>
                    <a class="btn btn-sm" href="<?= e($INV_SELF) ?>?edit=<?= (int)$r['id'] ?>">Edit</a>
                    <form method="post" class="inline" onsubmit="return confirm('Remove this card?')"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><button class="btn btn-sm" type="submit">✕</button></form>
                </div>
                <form method="post" style="display:flex;gap:4px;flex-wrap:wrap;align-items:center;margin:0"><?= csrf_field() ?>
                    <input type="hidden" name="action" value="sold"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                    <input name="sold_price" type="number" step="0.01" min="0.01" placeholder="sold $" style="width:82px">
                    <input name="sold_fees" type="number" step="0.01" min="0" placeholder="fees $" style="width:72px">
                    <input name="sold_ship" type="number" step="0.01" min="0" placeholder="ship $" style="width:72px">
                    <input name="sold_at" type="date" title="Sold date (blank = today)" style="width:135px">
                    <button class="btn btn-sm btn-primary" type="submit">Mark sold</button>
                </form>
            </td>
        </tr>
        <?php endforeach; ?>
    </table></div>
</div>
<?php endif; ?>

<?php if ($soldRows):
    $soldProfit = 0.0;
    foreach ($soldRows as $r) { $soldProfit += Inventory::realized($r) ?? 0.0; } ?>
<div class="card" style="margin-bottom:16px">
    <h2 style="margin-top:0">✅ Sold (<?= count($soldRows) ?>)
        <small style="color:var(--muted);font-weight:400">— total profit:
        <strong style="color:<?= $soldProfit >= 0 ? '#1d7d46' : '#e05555' ?>">$<?= number_format($soldProfit, 2) ?></strong></small></h2>
    <div style="overflow-x:auto"><table>
        <tr><th style="min-width:220px">Card</th><th>Grade</th><th>All-in cost</th><th>Sold $</th><th>Fees + ship</th><th>Sold date</th><th>Profit $</th><th></th></tr>
        <?php foreach ($soldRows as $r):
            $allIn    = Inventory::allIn($r);
            $realized = Inventory::realized($r);
            $deduct   = (float)($r['sold_fees'] ?? 0) + (float)($r['sold_ship'] ?? 0); ?>
        <tr>
            <td><strong><?= e((string)$r['card_name']) ?></strong></td>
            <td style="white-space:nowrap"><?= e($r['grade_company'] === 'RAW' ? 'Raw' : $r['grade_company'] . ' ' . (string)($r['grade'] ?? '?')) ?></td>
            <td style="white-space:nowrap">$<?= number_format($allIn, 2) ?></td>
            <td style="white-space:nowrap"><strong><?= $r['sold_price'] !== null ? '$' . number_format((float)$r['sold_price'], 2) : '—' ?></strong></td>
            <td style="white-space:nowrap"><?= $deduct > 0 ? '-$' . number_format($deduct, 2) : '$0.00' ?></td>
            <td style="white-space:nowrap"><?= $r['sold_at'] !== null ? e(date('M j, Y', strtotime((string)$r['sold_at']))) : '—' ?></td>
            <td style="white-space:nowrap"><?= $realized !== null
                ? '<strong style="color:' . ($realized >= 0 ? '#1d7d46' : '#e05555') . ';font-size:1.05rem">$' . number_format($realized, 2) . '</strong>'
                : '—' ?></td>
            <td><div style="display:flex;gap:6px">
                <a class="btn btn-sm" href="<?= e($INV_SELF) ?>?edit=<?= (int)$r['id'] ?>">Edit</a>
                <form method="post" class="inline" onsubmit="return confirm('Remove this card?')"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><button class="btn btn-sm" type="submit">✕</button></form>
            </div></td>
        </tr>
        <?php endforeach; ?>
    </table></div>
</div>
<?php endif; ?>
<?php
layout_footer();
