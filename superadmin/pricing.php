<?php
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';
require __DIR__ . '/../src/layout.php';

use SportCard101\Auth;

Auth::requireAdmin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';
    if ($action === 'delete') {
        $pdo->prepare('DELETE FROM plans WHERE id=?')->execute([(int)($_POST['id'] ?? 0)]);
        flash('info', 'Plan deleted.');
    } elseif ($action === 'save') {
        $id      = (int)($_POST['id'] ?? 0);
        $name    = trim((string)($_POST['name'] ?? ''));
        $price   = (int)round((float)($_POST['price'] ?? 0) * 100);
        $interval= ($_POST['bill_interval'] ?? 'month') === 'year' ? 'year' : 'month';
        $blurb   = trim((string)($_POST['blurb'] ?? ''));
        $features= trim((string)($_POST['features'] ?? ''));
        $stripe  = trim((string)($_POST['stripe_price_id'] ?? ''));
        $active  = isset($_POST['is_active']) ? 1 : 0;
        $sort    = (int)($_POST['sort'] ?? 0);
        if ($name === '') {
            flash('error', 'Plan name is required.');
        } elseif ($id) {
            $pdo->prepare('UPDATE plans SET name=?, price_cents=?, bill_interval=?, blurb=?, features=?, stripe_price_id=?, is_active=?, sort=? WHERE id=?')
                ->execute([$name,$price,$interval,$blurb,$features,$stripe ?: null,$active,$sort,$id]);
            flash('success', 'Plan updated.');
        } else {
            $pdo->prepare('INSERT INTO plans (name, slug, price_cents, bill_interval, blurb, features, stripe_price_id, is_active, sort) VALUES (?,?,?,?,?,?,?,?,?)')
                ->execute([$name, slugify($name), $price, $interval, $blurb, $features, $stripe ?: null, $active, $sort]);
            flash('success', 'Plan created.');
        }
    }
    redirect('/superadmin/pricing.php');
}

$plans = $pdo->query('SELECT * FROM plans ORDER BY sort, price_cents')->fetchAll();
$edit  = null;
if (isset($_GET['edit'])) {
    $s = $pdo->prepare('SELECT * FROM plans WHERE id=?');
    $s->execute([(int)$_GET['edit']]);
    $edit = $s->fetch() ?: null;
}

layout_header('Pricing', 'admin');
?>
<h1>Pricing & plans</h1>
<p class="sub">These plans show on the homepage and member upgrade screen. Map each paid plan to a Stripe price ID to charge for it.</p>

<div class="card" style="margin-bottom:24px">
    <h2 style="margin-top:0"><?= $edit ? 'Edit plan' : 'New plan' ?></h2>
    <form method="post" class="stack"><?= csrf_field() ?>
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="id" value="<?= (int)($edit['id'] ?? 0) ?>">
        <div class="row">
            <div><label>Name</label><input name="name" value="<?= e($edit['name'] ?? '') ?>" required></div>
            <div><label>Price (USD)</label><input name="price" type="number" step="0.01" min="0" value="<?= $edit ? number_format($edit['price_cents']/100, 2, '.', '') : '0.00' ?>"></div>
            <div><label>Interval</label>
                <select name="bill_interval">
                    <option value="month"<?= ($edit['bill_interval'] ?? '')==='month'?' selected':'' ?>>month</option>
                    <option value="year"<?= ($edit['bill_interval'] ?? '')==='year'?' selected':'' ?>>year</option>
                </select>
            </div>
            <div><label>Sort</label><input name="sort" type="number" value="<?= (int)($edit['sort'] ?? 0) ?>"></div>
        </div>
        <label>Blurb</label><input name="blurb" value="<?= e($edit['blurb'] ?? '') ?>" placeholder="One-line summary">
        <label>Features <small>(one per line)</small></label>
        <textarea name="features" rows="4"><?= e($edit['features'] ?? '') ?></textarea>
        <label>Stripe price ID <small>(price_...)</small></label>
        <input name="stripe_price_id" value="<?= e($edit['stripe_price_id'] ?? '') ?>" placeholder="leave blank until Stripe is set up">
        <label class="checkbox"><input type="checkbox" name="is_active" <?= ($edit['is_active'] ?? 1) ? 'checked' : '' ?>> Active (visible)</label>
        <div style="margin-top:14px">
            <button class="btn btn-primary" type="submit"><?= $edit ? 'Save plan' : 'Create plan' ?></button>
            <?php if ($edit): ?><a class="btn" href="/superadmin/pricing.php">Cancel</a><?php endif; ?>
        </div>
    </form>
</div>

<table>
    <thead><tr><th>Plan</th><th>Price</th><th>Stripe</th><th>Active</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($plans as $p): ?>
        <tr>
            <td><strong><?= e($p['name']) ?></strong><br><span class="sub"><?= e($p['blurb']) ?></span></td>
            <td><?= $p['price_cents']===0 ? 'Free' : money_cents((int)$p['price_cents']).'/'.e($p['bill_interval']) ?></td>
            <td><?= $p['stripe_price_id'] ? '✅' : '<span class="sub">not set</span>' ?></td>
            <td><?= $p['is_active'] ? '🟢' : '⚪' ?></td>
            <td>
                <a class="btn btn-sm" href="/superadmin/pricing.php?edit=<?= (int)$p['id'] ?>">Edit</a>
                <form method="post" class="inline" onsubmit="return confirm('Delete this plan?')"><?= csrf_field() ?>
                    <input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
                    <button class="btn btn-sm btn-danger" type="submit">Delete</button>
                </form>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
<?php
layout_footer();
