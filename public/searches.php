<?php
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';
require __DIR__ . '/../src/layout.php';

use Vipsvault\Auth;

Auth::require();
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
        $buying   = in_array($_POST['buying_option'] ?? '', ['AUCTION', 'FIXED_PRICE', 'ANY'], true)
                    ? $_POST['buying_option'] : 'AUCTION';

        if ($label === '' || $keywords === '') {
            flash('error', 'Label and keywords are required.');
        } else {
            $stmt = $pdo->prepare(
                'INSERT INTO searches (user_id, label, keywords, grade, max_price, threshold_pct, buying_option)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([$uid, $label, $keywords, $grade, $maxPrice, $thr, $buying]);
            flash('success', 'Search created. Hit “Scan now” to find deals.');
        }
        redirect('searches.php');
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $pdo->prepare('DELETE FROM searches WHERE id = ? AND user_id = ?')->execute([$id, $uid]);
        flash('info', 'Search deleted.');
        redirect('searches.php');
    }

    if ($action === 'toggle') {
        $id = (int)($_POST['id'] ?? 0);
        $pdo->prepare('UPDATE searches SET active = 1 - active WHERE id = ? AND user_id = ?')->execute([$id, $uid]);
        redirect('searches.php');
    }
}

$stmt = $pdo->prepare(
    'SELECT s.*, (SELECT COUNT(*) FROM listings l WHERE l.search_id = s.id AND l.is_deal = 1) AS deal_count
     FROM searches s WHERE s.user_id = ? ORDER BY s.created_at DESC'
);
$stmt->execute([$uid]);
$rows = $stmt->fetchAll();

layout_header('Searches');
?>
<h1>🔎 Saved searches</h1>
<p class="sub">Each search tells the scanner what to hunt for on eBay and when to flag a deal.</p>

<div class="card" style="margin-bottom:28px">
    <h2 style="margin-top:0">New search</h2>
    <form method="post" class="stack">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="create">
        <div class="row">
            <div>
                <label for="label">Label</label>
                <input id="label" name="label" placeholder="Jordan Rookie" required>
            </div>
            <div>
                <label for="keywords">eBay keywords</label>
                <input id="keywords" name="keywords" placeholder="Michael Jordan Fleer Rookie" required>
            </div>
        </div>
        <div class="row">
            <div>
                <label for="grade">Grade</label>
                <input id="grade" name="grade" value="PSA 10">
            </div>
            <div>
                <label for="buying_option">Listing type</label>
                <select id="buying_option" name="buying_option">
                    <option value="AUCTION">Auctions only</option>
                    <option value="FIXED_PRICE">Buy It Now only</option>
                    <option value="ANY">Both</option>
                </select>
            </div>
            <div>
                <label for="max_price">Max price ($, optional)</label>
                <input id="max_price" name="max_price" type="number" step="0.01" min="0" placeholder="no cap">
            </div>
            <div>
                <label for="threshold_pct">Deal threshold (% below market)</label>
                <input id="threshold_pct" name="threshold_pct" type="number" min="1" max="95"
                       value="<?= (int)($config['deals']['default_threshold_pct'] ?? 25) ?>">
            </div>
        </div>
        <button class="btn btn-primary" style="margin-top:20px" type="submit">+ Add search</button>
    </form>
</div>

<?php if (!$rows): ?>
    <div class="empty">No searches yet. Add one above to get started.</div>
<?php else: ?>
<table>
    <thead>
        <tr>
            <th>Label</th><th>Query</th><th>Type</th><th>Max $</th><th>Threshold</th>
            <th>Deals</th><th>Last scan</th><th>Status</th><th></th>
        </tr>
    </thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
        <tr>
            <td><strong><?= e($r['label']) ?></strong></td>
            <td><?= e($r['grade'] . ' ' . $r['keywords']) ?></td>
            <td><?= e(['AUCTION' => 'Auction', 'FIXED_PRICE' => 'BIN', 'ANY' => 'Both'][$r['buying_option']] ?? $r['buying_option']) ?></td>
            <td><?= $r['max_price'] !== null ? money((float)$r['max_price']) : '—' ?></td>
            <td><?= (int)$r['threshold_pct'] ?>%</td>
            <td><?= (int)$r['deal_count'] ?></td>
            <td><?= $r['last_scanned_at'] ? e(date('M j, g:ia', strtotime($r['last_scanned_at']))) : 'never' ?></td>
            <td>
                <form method="post" class="inline">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="toggle">
                    <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                    <button class="btn btn-sm" type="submit"><?= $r['active'] ? '🟢 Active' : '⚪ Paused' ?></button>
                </form>
            </td>
            <td>
                <form method="post" class="inline" onsubmit="return confirm('Delete this search and its listings?')">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                    <button class="btn btn-sm btn-danger" type="submit">Delete</button>
                </form>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
<?php endif; ?>
<?php
layout_footer();
