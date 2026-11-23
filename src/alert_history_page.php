<?php
declare(strict_types=1);

/**
 * Deal-alert history — the body shared by /member/alerts.php and
 * /superadmin/alerthistory.php.
 *
 * The including page sets, before the require:
 *   $AH_AREA  'member' | 'admin'   which nav to render
 *   $AH_PRO   bool                 false shows the free preview + upsell
 *
 * Every alert email that actually sent is listed newest-first, grouped by the
 * day it went out, newest day first. Figures are the snapshot taken when the
 * mail was sent, not current eBay prices.
 */

use SportCard101\Auth;
use SportCard101\DealAlerts;

/** @var PDO $pdo */
$AH_AREA = $AH_AREA ?? 'member';
$AH_PRO  = (bool) ($AH_PRO ?? false);

// Free members get the newest email only, trimmed to its first few auctions.
const AH_FREE_EMAILS = 1;
const AH_FREE_ITEMS  = 3;

$emails = DealAlerts::history($pdo, $AH_PRO ? 120 : AH_FREE_EMAILS);
$total  = count($emails);

if (!$AH_PRO) {
    $emails = array_slice($emails, 0, AH_FREE_EMAILS);
    foreach ($emails as $i => $e) {
        $emails[$i]['items']  = array_slice($e['items'], 0, AH_FREE_ITEMS);
        $emails[$i]['capped'] = $e['match_count'] > AH_FREE_ITEMS;
    }
}

/**
 * Group emails under the local day they were sent. Dates are formatted in the
 * site's configured timezone (bootstrap sets it), so "Today" means today where
 * the site lives, not UTC.
 */
$byDay = [];
foreach ($emails as $e) {
    $ts  = strtotime($e['sent_at']) ?: time();
    $key = date('Y-m-d', $ts);
    $byDay[$key][] = $e + ['ts' => $ts];
}
krsort($byDay); // newest day first; history() already sorts within a day

/** "Today" / "Yesterday" / "Tue 18 Nov" for a Y-m-d key. */
$dayLabel = static function (string $ymd): string {
    $today = date('Y-m-d');
    $yday  = date('Y-m-d', strtotime('-1 day'));
    if ($ymd === $today) { return 'Today'; }
    if ($ymd === $yday)  { return 'Yesterday'; }
    $ts = strtotime($ymd) ?: time();
    return date('D j M', $ts) . (date('Y', $ts) !== date('Y') ? date(' Y', $ts) : '');
};

layout_header('Deal Alerts', $AH_AREA);
?>
<h1>🔔 Deal alerts</h1>
<p class="sub">
    Every alert email SportCard101 has sent, newest first. Bids, comps and time
    left are as they stood <em>when each email went out</em> — not current eBay prices.
</p>

<?php if (!$AH_PRO): ?>
    <div class="mock-note">
        🔒 You're seeing a <strong>free preview</strong> — the latest alert's top
        <?= AH_FREE_ITEMS ?> auctions. <a href="/member/account.php">Upgrade to Pro</a>
        for the full alert history.
    </div>
<?php endif; ?>

<?php if (!$emails): ?>
    <div class="empty">
        No alerts sent yet.
        <?php if ($AH_AREA === 'admin'): ?>
            History starts from the first email sent after this page went live —
            alerts sent before then were never recorded. Check
            <a href="/superadmin/alerts.php">Alerts</a> has triggers set, alerts
            switched on and an email address entered.
        <?php else: ?>
            Alert emails will appear here as they go out.
        <?php endif; ?>
    </div>
<?php else: ?>

<?php foreach ($byDay as $ymd => $dayEmails): ?>
    <h2 class="ah-day"><?= e($dayLabel((string) $ymd)) ?>
        <span><?= count($dayEmails) ?> alert<?= count($dayEmails) === 1 ? '' : 's' ?></span>
    </h2>

    <?php foreach ($dayEmails as $idx => $em):
        // Newest email of the newest day starts open; the rest collapse, so a
        // 51-auction email doesn't bury everything under it.
        $isFirst = ($ymd === array_key_first($byDay)) && $idx === 0;
        $shown   = count($em['items']);
        ?>
        <details class="ah-email"<?= $isFirst ? ' open' : '' ?>>
            <summary>
                <span class="ah-time"><?= e(date('g:ia', $em['ts'])) ?></span>
                <span class="ah-count"><?= (int) $em['match_count'] ?> auction<?= (int) $em['match_count'] === 1 ? '' : 's' ?> matched your alert triggers, best value first.</span>
            </summary>

            <?php if (!$em['items']): ?>
                <div class="empty">This alert's detail wasn't recorded.</div>
            <?php else: ?>
                <div class="ah-items">
                    <?php foreach ($em['items'] as $it):
                        $comp = $it['comp'] ?? null; ?>
                        <article class="ah-item">
                            <h3><?= e((string) ($it['card'] ?? '')) ?></h3>
                            <p class="ah-bid">
                                Current bid <strong><?= e((string) ($it['price'] ?? '')) ?></strong>
                                · <?= (int) ($it['bids'] ?? 0) ?> bid<?= (int) ($it['bids'] ?? 0) === 1 ? '' : 's' ?>
                                · ends in <?= e((string) ($it['ends'] ?? '')) ?>
                            </p>
                            <?php if ($comp): ?>
                                <p class="ah-comp">
                                    <?php if (!empty($comp['under'])): ?>
                                        <span class="ah-under"><?= (int) $comp['under'] ?>% under comp</span> ·
                                    <?php endif; ?>
                                    median <?= e((string) $comp['median']) ?> · <?= (int) $comp['count'] ?> sale<?= (int) $comp['count'] === 1 ? '' : 's' ?>
                                </p>
                            <?php endif; ?>
                            <?php if (!empty($it['trig'])): ?>
                                <p class="ah-trig">Matched: <?= e((string) $it['trig']) ?></p>
                            <?php endif; ?>
                            <?php if (!empty($it['url'])): ?>
                                <a class="btn btn-primary btn-sm" href="<?= e((string) $it['url']) ?>" target="_blank" rel="noopener">View on eBay →</a>
                            <?php endif; ?>
                        </article>
                    <?php endforeach; ?>
                </div>

                <?php if (!empty($em['capped'])): ?>
                    <p class="ah-more">
                        + <?= (int) $em['match_count'] - $shown ?> more in this alert —
                        <a href="/member/account.php">upgrade to Pro</a> to see them.
                    </p>
                <?php endif; ?>
            <?php endif; ?>
        </details>
    <?php endforeach; ?>
<?php endforeach; ?>

    <?php if (!$AH_PRO): ?>
        <div class="upsell"><a class="btn btn-primary btn-lg" href="/member/account.php">Unlock every alert with Pro →</a></div>
    <?php else: ?>
        <p class="sub" style="margin-top:22px">
            Alerts older than <?= DealAlerts::KEEP_DAYS ?> days are removed automatically.
        </p>
    <?php endif; ?>
<?php endif; ?>
<?php
layout_footer();
