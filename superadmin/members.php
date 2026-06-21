<?php
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';
require __DIR__ . '/../src/layout.php';

use SportCard101\Auth;

Auth::requireAdmin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $id = (int)($_POST['id'] ?? 0);
    switch ($_POST['action'] ?? '') {
        case 'toggle_status':
            $pdo->prepare("UPDATE users SET status = IF(status='active','suspended','active') WHERE id=? AND role='member'")->execute([$id]);
            flash('info', 'Member status updated.');
            break;
        case 'set_plan':
            $planId = ($_POST['plan_id'] ?? '') !== '' ? (int)$_POST['plan_id'] : null;
            $sub    = in_array($_POST['sub_status'] ?? '', ['none','trialing','active','past_due','canceled'], true) ? $_POST['sub_status'] : 'none';
            $pdo->prepare("UPDATE users SET plan_id=?, sub_status=? WHERE id=? AND role='member'")->execute([$planId, $sub, $id]);
            flash('success', 'Subscription updated.');
            break;
    }
    redirect('/superadmin/members.php');
}

$members = $pdo->query(
    "SELECT u.*, p.name AS plan_name,
            (SELECT COUNT(*) FROM referrals r WHERE r.affiliate_user_id=u.id) AS refs
     FROM users u LEFT JOIN plans p ON p.id=u.plan_id
     WHERE u.role='member' ORDER BY u.created_at DESC"
)->fetchAll();
$plans = $pdo->query('SELECT id, name FROM plans ORDER BY sort, price_cents')->fetchAll();

layout_header('Members', 'admin');
?>
<h1>Members</h1>
<p class="sub"><?= count($members) ?> member account<?= count($members) === 1 ? '' : 's' ?>.</p>

<?php if (!$members): ?>
    <div class="empty">No members yet. They sign up at <a href="/signup.php">/signup.php</a>.</div>
<?php else: ?>
<table>
    <thead><tr><th>Member</th><th>Status</th><th>Plan / Sub</th><th>Refs</th><th>Joined</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($members as $m): ?>
        <tr>
            <td><strong><?= e($m['username']) ?></strong><br><span class="sub"><?= e($m['email']) ?></span><br><span class="sub">code <?= e($m['affiliate_code']) ?></span></td>
            <td>
                <form method="post" class="inline"><?= csrf_field() ?>
                    <input type="hidden" name="action" value="toggle_status"><input type="hidden" name="id" value="<?= (int)$m['id'] ?>">
                    <button class="btn btn-sm" type="submit"><?= $m['status']==='active' ? '🟢 active' : '⚪ suspended' ?></button>
                </form>
            </td>
            <td>
                <form method="post" class="inline-form"><?= csrf_field() ?>
                    <input type="hidden" name="action" value="set_plan"><input type="hidden" name="id" value="<?= (int)$m['id'] ?>">
                    <select name="plan_id">
                        <option value="">— no plan —</option>
                        <?php foreach ($plans as $p): ?>
                            <option value="<?= (int)$p['id'] ?>"<?= (int)$m['plan_id']===(int)$p['id']?' selected':'' ?>><?= e($p['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <select name="sub_status">
                        <?php foreach (['none','trialing','active','past_due','canceled'] as $s): ?>
                            <option value="<?= $s ?>"<?= $m['sub_status']===$s?' selected':'' ?>><?= $s ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button class="btn btn-sm btn-primary" type="submit">Save</button>
                </form>
            </td>
            <td><?= (int)$m['refs'] ?></td>
            <td><?= e(date('M j, Y', strtotime($m['created_at']))) ?></td>
            <td></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
<?php endif; ?>
<?php
layout_footer();
