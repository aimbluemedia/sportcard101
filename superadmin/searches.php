<?php
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';
require __DIR__ . '/../src/layout.php';

use SportCard101\Auth;

Auth::requireAdmin();
$uid = Auth::userId();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';
    if ($action === 'create') {
        $label    = trim((string)($_POST['label'] ?? ''));
        $keywords = trim((string)($_POST['keywords'] ?? ''));
        $grade    = trim((string)($_POST['grade'] ?? 'PSA 10')) ?: 'PSA 10';
        $maxPrice = $_POST['max_price'] !== '' ? (float)$_POST['max_price'] : null;
        $thr      = max(1, min(95, (int)($_POST['threshold_pct'] ?? 25)));
        $buying   = in_array($_POST['buying_option'] ?? '', ['AUCTION','FIXED_PRICE','ANY'], true) ? $_POST['buying_option'] : 'AUCTION';
        if ($label === '' || $keywords === '') {
            flash('error', 'Label and keywords are required.');
        } else {
            $pdo->prepare('INSERT INTO searches (user_id, label, keywords, grade, max_price, threshold_pct, buying_option) VALUES (?,?,?,?,?,?,?)')
                ->execute([$uid, $label, $keywords, $grade, $maxPrice, $thr, $buying]);
            flash('success', 'Search created. Run a scan to find deals.');
        }
    } elseif ($action === 'delete') {
        $pdo->prepare('DELETE FROM searches WHERE id=?')->execute([(int)($_POST['id'] ?? 0)]);
        flash('info', 'Search deleted.');
    } elseif ($action === 'toggle') {
        $pdo->prepare('UPDATE searches SET active = 1 - active WHERE id=?')->execute([(int)($_POST['id'] ?? 0)]);
    }
    redirect('/superadmin/searches.php');
}

$rows = $pdo->query(
    'SELECT s.*, (SELECT COUNT(*) FROM listings l WHERE l.search_id=s.id AND l.is_deal=1) AS deal_count
     FROM searches s ORDER BY s.created_at DESC'
)->fetchAll();

layout_header('AI App', 'admin');
?>
<h1>🔎 AI scanner</h1>
<p class="sub">These searches drive the AI deal engine. Members see the resulting deals. Hit <strong>Scan now</strong> to run them.</p>

<form method="post" action="/superadmin/scan.php" class="inline" style="margin-bottom:18px">
    <?= csrf_field() ?>
    <button class="btn btn-scan" type="submit">⟳ Scan now</button>
</form>

<div class="card" style="margin-bottom:24px">
    <h2 style="margin-top:0">New search</h2>
    <form method="post" class="stack"><?= csrf_field() ?>
        <input type="hidden" name="action" value="create">
        <div class="row">
            <div><label>Label</label><input name="label" placeholder="Jordan Rookie" required></div>
            <div><label>eBay keywords</label><input name="keywords" placeholder="Michael Jordan Fleer Rookie" required></div>
        </div>
        <div class="row">
            <div><label>Grade</label><input name="grade" value="PSA 10"></div>
            <div><label>Listing type</label>
                <select name="buying_option"><option value="AUCTION">Auctions</option><option value="FIXED_PRICE">Buy It Now</option><option value="ANY">Both</option></select>
            </div>
            <div><label>Max price ($)</label><input name="max_price" type="number" step="0.01" min="0" placeholder="no cap"></div>
            <div><label>Deal threshold (%)</label><input name="threshold_pct" type="number" min="1" max="95" value="<?= (int)($config['deals']['default_threshold_pct'] ?? 25) ?>"></div>
        </div>
        <div style="margin-top:14px"><button class="btn btn-primary" type="submit">+ Add search</button></div>
    </form>
</div>

<?php if (!$rows): ?>
    <div class="empty">No searches yet. Add one above.</div>
<?php else: ?>
<table>
    <thead><tr><th>Label</th><th>Query</th><th>Type</th><th>Thr</th><th>Deals</th><th>Last scan</th><th>Status</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
        <tr>
            <td><strong><?= e($r['label']) ?></strong></td>
            <td><?= e($r['grade'].' '.$r['keywords']) ?></td>
            <td><?= e(['AUCTION'=>'Auction','FIXED_PRICE'=>'BIN','ANY'=>'Both'][$r['buying_option']] ?? $r['buying_option']) ?></td>
            <td><?= (int)$r['threshold_pct'] ?>%</td>
            <td><?= (int)$r['deal_count'] ?></td>
            <td><?= $r['last_scanned_at'] ? e(date('M j, g:ia', strtotime($r['last_scanned_at']))) : 'never' ?></td>
            <td>
                <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                    <button class="btn btn-sm" type="submit"><?= $r['active'] ? '🟢 Active' : '⚪ Paused' ?></button></form>
            </td>
            <td>
                <form method="post" class="inline" onsubmit="return confirm('Delete this search?')"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                    <button class="btn btn-sm btn-danger" type="submit">Delete</button></form>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
<?php endif; ?>
<?php
layout_footer();
