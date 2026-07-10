<?php
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';
require __DIR__ . '/../src/layout.php';

use SportCard101\Auth;
use SportCard101\AiAnalyst;
use SportCard101\Playbook;

Auth::requireAdmin();

// Tables ready?
$ready = true;
try {
    $pdo->query('SELECT 1 FROM daily_plans LIMIT 1');
    $pdo->query('SELECT 1 FROM trades LIMIT 1');
} catch (\Throwable $e) {
    $ready = false;
}

// ---- Actions ---------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $ready) {
    csrf_verify();
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'generate') {
        $res = Playbook::build($pdo, new AiAnalyst($config['ai']));
        flash('success', "Plan generated: {$res['buys']} buy target(s), {$res['watch']} on the watchlist, \${$res['exposure']} exposure (AI: {$res['ai']}).");
    } elseif ($action === 'settings') {
        set_setting('plan_daily_budget', (string) max(0, (float)($_POST['plan_daily_budget'] ?? 150)));
        set_setting('plan_max_per_card', (string) max(0, (float)($_POST['plan_max_per_card'] ?? 75)));
        set_setting('plan_margin_pct', (string) max(1, (float)($_POST['plan_margin_pct'] ?? 20)));
        flash('success', 'Playbook settings saved. Regenerate the plan to apply them.');
    } elseif ($action === 'trade_add') {
        $card = trim((string)($_POST['card'] ?? ''));
        $buy  = (float)($_POST['buy_price'] ?? 0);
        if ($card !== '' && $buy > 0) {
            $pdo->prepare('INSERT INTO trades (card, ebay_item_id, sport, grade, buy_price, bought_at) VALUES (?,?,?,?,?,CURDATE())')
                ->execute([
                    mb_substr($card, 0, 250),
                    trim((string)($_POST['ebay_item_id'] ?? '')) ?: null,
                    trim((string)($_POST['sport'] ?? '')) ?: null,
                    trim((string)($_POST['grade'] ?? '')) ?: null,
                    $buy,
                ]);
            flash('success', 'Trade logged: ' . $card . ' @ $' . number_format($buy, 2));
        } else {
            flash('error', 'Card name and a buy price are required.');
        }
    } elseif ($action === 'trade_listed') {
        $pdo->prepare("UPDATE trades SET status='LISTED', listed_at=CURDATE() WHERE id=? AND status='BOUGHT'")
            ->execute([(int)($_POST['id'] ?? 0)]);
        flash('success', 'Marked as listed.');
    } elseif ($action === 'trade_sold') {
        $sell = (float)($_POST['sell_price'] ?? 0);
        if ($sell > 0) {
            $pdo->prepare("UPDATE trades SET status='SOLD', sold_at=CURDATE(), sell_price=? WHERE id=?")
                ->execute([$sell, (int)($_POST['id'] ?? 0)]);
            flash('success', 'Sold — nice. P&L updated below.');
        } else {
            flash('error', 'Enter the sale price.');
        }
    } elseif ($action === 'trade_delete') {
        $pdo->prepare('DELETE FROM trades WHERE id=?')->execute([(int)($_POST['id'] ?? 0)]);
        flash('success', 'Trade removed.');
    }
    redirect('/superadmin/dailyplan.php');
}

// ---- Data ------------------------------------------------------------------
$plan   = $ready ? Playbook::load($pdo) : null;
$sells  = $ready ? Playbook::sellActions($pdo) : [];
$trades = $ready ? $pdo->query('SELECT * FROM trades ORDER BY bought_at DESC, id DESC LIMIT 200')->fetchAll() : [];
$cfg    = Playbook::config();

// Realised P&L: sold trades, estimating fees when not recorded.
$realised = 0.0; $soldCount = 0;
foreach ($trades as $t) {
    if ($t['status'] === 'SOLD' && $t['sell_price'] !== null) {
        $fees = $t['fees'] !== null
            ? (float)$t['fees']
            : round((float)$t['sell_price'] * Playbook::FEE_RATE + Playbook::SHIP_COST, 2);
        $realised += (float)$t['sell_price'] - $fees - (float)$t['buy_price'];
        $soldCount++;
    }
}

$buys  = $plan ? array_values(array_filter($plan['targets'], fn ($t) => $t['kind'] === 'BUY')) : [];
$watch = $plan ? array_values(array_filter($plan['targets'], fn ($t) => $t['kind'] === 'WATCH')) : [];

layout_header('Daily Plan', 'admin');
?>
<h1>📋 Daily Plan</h1>
<p class="sub">The Morning Playbook — buy targets with hard max bids, a watchlist, sell actions, and your trade log. Generated daily by cron (<code>task=daily</code>) or on demand.</p>

<?php if (!$ready): ?>
    <div class="card" style="border-left:4px solid #e0a935">
        <strong>One-time setup needed</strong>
        <p style="margin:6px 0 0">Run <code>migrations/2026_daily_plans.sql</code> in phpMyAdmin to create the plan and trade tables, then reload this page.</p>
    </div>
<?php layout_footer(); return; endif; ?>

<div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:16px">
    <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="generate">
        <button class="btn btn-primary" type="submit">⚡ Generate today's plan now</button>
    </form>
</div>

<?php if (!$plan): ?>
    <div class="card"><p style="margin:0">No plan yet — generate one above, or wait for the daily cron. Add the daily cron in Hostinger:
    <code>0 7 * * * curl -s "https://sportcard101.com/cron.php?key=YOUR_SECRET&amp;task=daily" &gt;/dev/null 2&gt;&amp;1</code></p></div>
<?php else: ?>
    <div class="card" style="margin-bottom:16px">
        <h2 style="margin-top:0"><?= e(date('l, M j', strtotime((string)$plan['plan_date']))) ?>
            <small style="color:var(--muted);font-weight:400">· AI: <?= e((string)$plan['ai_mode']) ?> · generated <?= e(date('g:ia', strtotime((string)$plan['created_at']))) ?></small></h2>
        <p style="margin:6px 0 10px"><?= e((string)$plan['summary']) ?></p>
        <p style="margin:0;color:var(--muted)">Budget <strong>$<?= number_format((float)$plan['budget_day'], 2) ?></strong>
            · planned exposure <strong>$<?= number_format((float)$plan['exposure'], 2) ?></strong>
            · The max bid is a promise to yourself — never chase past it.</p>
    </div>

    <div class="card" style="margin-bottom:16px">
        <h2 style="margin-top:0">🎯 Buy targets (<?= count($buys) ?>)</h2>
        <?php if (!$buys): ?>
            <p style="margin:0;color:var(--muted)">None today. Nothing met the comp-confidence + margin bar — holding the bankroll is the plan.</p>
        <?php else: ?>
        <div style="overflow-x:auto"><table>
            <tr><th>Card</th><th>Now</th><th>Bids</th><th>Ends</th><th>Comp (90d)</th><th>Max bid</th><th>Est. net</th><th></th><th></th></tr>
            <?php foreach ($buys as $t):
                $hrs = hours_until((string)$t['end_time']); ?>
            <tr>
                <td><strong><?= e((string)$t['card']) ?></strong><br><small style="color:var(--muted)"><?= e((string)$t['reason']) ?></small></td>
                <td>$<?= number_format((float)$t['current_price'], 2) ?></td>
                <td><?= (int)$t['bid_count'] ?></td>
                <td><?= $hrs === null ? '—' : '~' . round($hrs) . 'h' ?></td>
                <td>$<?= number_format((float)$t['comp_median'], 2) ?> <small style="color:var(--muted)">(<?= (int)$t['comp_count'] ?> sales)</small></td>
                <td><strong style="color:#0071e3;font-size:15px">$<?= number_format((float)$t['max_bid'], 2) ?></strong></td>
                <td style="color:#1d7d46">$<?= number_format((float)$t['est_net'], 2) ?></td>
                <td><a class="btn" href="<?= e(epn_link((string)$t['item_url'])) ?>" target="_blank" rel="noopener">View on eBay</a></td>
                <td>
                    <form method="post" style="display:flex;gap:6px;align-items:center"><?= csrf_field() ?>
                        <input type="hidden" name="action" value="trade_add">
                        <input type="hidden" name="card" value="<?= e((string)$t['card']) ?>">
                        <input type="hidden" name="ebay_item_id" value="<?= e((string)$t['ebay_item_id']) ?>">
                        <input type="hidden" name="sport" value="<?= e((string)$t['sport']) ?>">
                        <input type="hidden" name="grade" value="<?= e((string)$t['grade']) ?>">
                        <input name="buy_price" type="number" step="0.01" min="0.01" placeholder="won @ $" style="width:90px">
                        <button class="btn" type="submit">I bought this</button>
                    </form>
                </td>
            </tr>
            <?php endforeach; ?>
        </table></div>
        <?php endif; ?>
    </div>

    <?php if ($watch): ?>
    <div class="card" style="margin-bottom:16px">
        <h2 style="margin-top:0">👀 Watchlist (<?= count($watch) ?>)</h2>
        <div style="overflow-x:auto"><table>
            <tr><th>Card</th><th>Now</th><th>Bids</th><th>Why it's not a buy (yet)</th><th></th></tr>
            <?php foreach ($watch as $t): ?>
            <tr>
                <td><strong><?= e((string)$t['card']) ?></strong></td>
                <td>$<?= number_format((float)$t['current_price'], 2) ?></td>
                <td><?= (int)$t['bid_count'] ?></td>
                <td><small><?= e((string)$t['reason']) ?></small></td>
                <td><a class="btn" href="<?= e(epn_link((string)$t['item_url'])) ?>" target="_blank" rel="noopener">View on eBay</a></td>
            </tr>
            <?php endforeach; ?>
        </table></div>
    </div>
    <?php endif; ?>
<?php endif; ?>

<?php if ($sells): ?>
<div class="card" style="margin-bottom:16px">
    <h2 style="margin-top:0">📤 Sell actions (<?= count($sells) ?>)</h2>
    <div style="overflow-x:auto"><table>
        <tr><th>Card</th><th>Bought</th><th>Status</th><th>Action</th><th></th></tr>
        <?php foreach ($sells as $s): ?>
        <tr>
            <td><strong><?= e((string)$s['card']) ?></strong></td>
            <td>$<?= number_format((float)$s['buy_price'], 2) ?> on <?= e(date('M j', strtotime((string)$s['bought_at']))) ?></td>
            <td><?= e((string)$s['status']) ?></td>
            <td><small><?= e((string)$s['action']) ?></small></td>
            <td>
                <div style="display:flex;gap:6px;align-items:center;flex-wrap:wrap">
                <?php if ($s['status'] === 'BOUGHT'): ?>
                    <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="trade_listed"><input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
                        <button class="btn" type="submit">Mark listed</button></form>
                <?php endif; ?>
                    <form method="post" style="display:flex;gap:6px;align-items:center"><?= csrf_field() ?>
                        <input type="hidden" name="action" value="trade_sold"><input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
                        <input name="sell_price" type="number" step="0.01" min="0.01" placeholder="sold @ $" style="width:90px">
                        <button class="btn" type="submit">Sold</button>
                    </form>
                </div>
            </td>
        </tr>
        <?php endforeach; ?>
    </table></div>
</div>
<?php endif; ?>

<div class="card" style="margin-bottom:16px">
    <h2 style="margin-top:0">📒 Trade log
        <small style="color:var(--muted);font-weight:400">— realised P&amp;L:
        <strong style="color:<?= $realised >= 0 ? '#1d7d46' : '#e05555' ?>">$<?= number_format($realised, 2) ?></strong>
        over <?= $soldCount ?> completed flip<?= $soldCount === 1 ? '' : 's' ?> (fees estimated at ~13% + $5 ship when not recorded)</small></h2>

    <form method="post" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-bottom:14px"><?= csrf_field() ?>
        <input type="hidden" name="action" value="trade_add">
        <input name="card" placeholder="Card (e.g. 2018 Prizm Luka RC PSA 10)" style="flex:1;min-width:240px">
        <input name="buy_price" type="number" step="0.01" min="0.01" placeholder="buy $" style="width:100px">
        <button class="btn btn-primary" type="submit">Log a buy</button>
    </form>

    <?php if (!$trades): ?>
        <p style="margin:0;color:var(--muted)">No trades yet. When you win an auction, log it here (or click "I bought this" on a target) — the report grades real results, not vibes.</p>
    <?php else: ?>
    <div style="overflow-x:auto"><table>
        <tr><th>Card</th><th>Buy</th><th>Status</th><th>Sold</th><th>Net P&amp;L</th><th></th></tr>
        <?php foreach ($trades as $t):
            $net = null;
            if ($t['status'] === 'SOLD' && $t['sell_price'] !== null) {
                $fees = $t['fees'] !== null ? (float)$t['fees'] : round((float)$t['sell_price'] * Playbook::FEE_RATE + Playbook::SHIP_COST, 2);
                $net  = (float)$t['sell_price'] - $fees - (float)$t['buy_price'];
            } ?>
        <tr>
            <td><strong><?= e((string)$t['card']) ?></strong></td>
            <td>$<?= number_format((float)$t['buy_price'], 2) ?> <small style="color:var(--muted)"><?= e(date('M j', strtotime((string)$t['bought_at']))) ?></small></td>
            <td><?= e((string)$t['status']) ?></td>
            <td><?= $t['sell_price'] !== null ? '$' . number_format((float)$t['sell_price'], 2) : '—' ?></td>
            <td><?= $net !== null ? '<strong style="color:' . ($net >= 0 ? '#1d7d46' : '#e05555') . '">$' . number_format($net, 2) . '</strong>' : '—' ?></td>
            <td>
                <form method="post" onsubmit="return confirm('Remove this trade?')"><?= csrf_field() ?>
                    <input type="hidden" name="action" value="trade_delete"><input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
                    <button class="btn" type="submit">✕</button>
                </form>
            </td>
        </tr>
        <?php endforeach; ?>
    </table></div>
    <?php endif; ?>
</div>

<div class="card" style="max-width:640px">
    <h2 style="margin-top:0">⚙️ Playbook settings</h2>
    <form method="post"><?= csrf_field() ?>
        <input type="hidden" name="action" value="settings">
        <label>Daily budget — max total exposure across all max bids ($)</label>
        <input name="plan_daily_budget" type="number" step="1" min="0" value="<?= e((string)$cfg['budget']) ?>">
        <label>Per-card cap — highest max bid on any single card ($)</label>
        <input name="plan_max_per_card" type="number" step="1" min="0" value="<?= e((string)$cfg['per_card']) ?>">
        <label>Required margin — net profit target per flip (%)</label>
        <input name="plan_margin_pct" type="number" step="1" min="1" value="<?= e((string)$cfg['margin_pct']) ?>">
        <div style="margin-top:14px"><button class="btn btn-primary" type="submit">Save settings</button></div>
    </form>
</div>
<?php
layout_footer();
