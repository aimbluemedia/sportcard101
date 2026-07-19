<?php
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';
require __DIR__ . '/../src/layout.php';

use SportCard101\Auth;
use SportCard101\Inventory;

Auth::requireAdmin();

Inventory::ensureTables($pdo);

/*
 * Member collections overview — engagement dashboard. Per member: how many
 * cards they've cataloged, what they've invested, and their realized P&L.
 * Members with empty collections aren't using the stickiest feature — that's
 * the churn-risk list.
 */
$rows = $pdo->query(
    "SELECT u.id, u.username, u.email, u.role, u.sub_status,
            COUNT(i.id)                                        AS total_cards,
            SUM(i.status <> 'SOLD')                            AS active_cards,
            SUM(CASE WHEN i.status <> 'SOLD' THEN i.card_cost + i.ship_cost ELSE 0 END) AS invested,
            SUM(i.status = 'SOLD')                             AS sold_cards,
            SUM(CASE WHEN i.status = 'SOLD' AND i.sold_price IS NOT NULL
                 THEN i.sold_price - COALESCE(i.sold_fees,0) - COALESCE(i.sold_ship,0) - i.card_cost - i.ship_cost
                 ELSE 0 END)                                   AS realized
     FROM users u
     LEFT JOIN inventory i ON i.user_id = u.id
     GROUP BY u.id, u.username, u.email, u.role, u.sub_status
     ORDER BY total_cards DESC, u.id ASC"
)->fetchAll();

$totCards = array_sum(array_map(fn ($r) => (int)$r['total_cards'], $rows));
$totInvested = array_sum(array_map(fn ($r) => (float)$r['invested'], $rows));
$using = count(array_filter($rows, fn ($r) => (int)$r['total_cards'] > 0));

layout_header('Collections', 'admin');
?>
<h1>🗃️ Member Collections</h1>
<p class="sub"><?= $totCards ?> cards cataloged across <?= $using ?> of <?= count($rows) ?> accounts · $<?= number_format($totInvested, 2) ?> invested (active cards). Members with 0 cards aren't using the stickiest feature — worth a nudge email.</p>

<div class="card">
    <div style="overflow-x:auto"><table>
        <tr><th>Member</th><th>Plan status</th><th>Cards</th><th>Active</th><th>Invested</th><th>Sold</th><th>Realized P&amp;L</th></tr>
        <?php foreach ($rows as $r): $rlz = (float)$r['realized']; ?>
        <tr>
            <td><strong><?= e((string)$r['username']) ?></strong><?= $r['role'] === 'superadmin' ? ' <small style="color:var(--muted)">(admin)</small>' : '' ?><br>
                <small style="color:var(--muted)"><?= e((string)$r['email']) ?></small></td>
            <td><?= e((string)$r['sub_status']) ?></td>
            <td><?= (int)$r['total_cards'] ?></td>
            <td><?= (int)$r['active_cards'] ?></td>
            <td>$<?= number_format((float)$r['invested'], 2) ?></td>
            <td><?= (int)$r['sold_cards'] ?></td>
            <td><strong style="color:<?= $rlz >= 0 ? '#1d7d46' : '#e05555' ?>">$<?= number_format($rlz, 2) ?></strong></td>
        </tr>
        <?php endforeach; ?>
    </table></div>
</div>
<?php
layout_footer();
