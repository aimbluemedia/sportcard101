<?php
declare(strict_types=1);

require __DIR__ . '/src/bootstrap.php';
require __DIR__ . '/src/layout.php';

use SportCard101\Auth;
use SportCard101\ErrorCards;

/*
 * Public error-card library. Free to read — error-card searches are a real
 * acquisition channel ("1990 frank thomas no name on front value"). Members
 * additionally see which live auctions currently match each error.
 */

ErrorCards::ensureTable($pdo);

$SPORTS   = card_sports();
$isMember = Auth::check();
$slug     = trim((string)($_GET['card'] ?? ''));

// ---------------------------------------------------------------- Detail page
if ($slug !== '') {
    $card = ErrorCards::bySlug($pdo, $slug);
    if (!$card) {
        http_response_code(404);
        layout_header('Error card not found', 'public');
        echo '<div class="empty" style="padding:50px">That error card isn\'t in the library. <a href="/errors.php">Browse all error cards ›</a></div>';
        layout_footer();
        return;
    }
    $pdo->prepare('UPDATE error_cards SET views = views + 1 WHERE id = ?')->execute([(int)$card['id']]);

    $cardTitle = trim(($card['year'] ?? '') . ' ' . ($card['set_name'] ?? '') . ' ' . ($card['player'] ?? ''));
    $meta = trim((string)$card['description']) !== ''
        ? (string)$card['description']
        : "How to identify the {$card['error_name']} error card.";

    // Members see live auctions that might be this error.
    $matches = [];
    if ($isMember) {
        try {
            $listings = $pdo->query(
                "SELECT title, price, bid_count, end_time, item_url FROM listings
                 WHERE end_time > UTC_TIMESTAMP() ORDER BY end_time ASC LIMIT 600"
            )->fetchAll();
            $matches = ErrorCards::matchListings($pdo, $listings, [$card]);
        } catch (\Throwable $e) {
        }
    }

    layout_header($card['error_name'] . ' — ' . $cardTitle, 'public', $meta);
    ?>
    <p style="margin:0 0 6px"><a href="/errors.php">‹ Error card library</a></p>
    <h1><?= e((string)$card['error_name']) ?></h1>
    <p class="sub"><?= e($cardTitle) ?><?= $card['card_number'] ? ' · #' . e((string)$card['card_number']) : '' ?>
        · <?= e(ErrorCards::TYPES[$card['error_type']] ?? 'Variation') ?></p>

    <div class="card" style="margin-bottom:16px">
        <?php if ($card['image_url']): ?>
            <img src="<?= e((string)$card['image_url']) ?>" alt="<?= e((string)$card['error_name']) ?>" style="max-width:280px;border-radius:10px;margin-bottom:14px">
        <?php endif; ?>
        <?php if ($card['description']): ?>
            <h2 style="margin-top:0">What the error is</h2>
            <p style="margin:0"><?= e((string)$card['description']) ?></p>
        <?php endif; ?>
        <?php if ($card['what_to_look_for']): ?>
            <h2>How to spot it</h2>
            <p style="margin:0"><?= e((string)$card['what_to_look_for']) ?></p>
        <?php endif; ?>
        <h2>Why it matters</h2>
        <ul style="margin:0;padding-left:20px;line-height:1.9">
            <li><?= $card['corrected_exists'] ? 'A corrected version exists.' : 'No corrected version was issued.' ?>
                <?= e(ErrorCards::SCARCER[$card['scarcer']] ?? '') ?></li>
            <?php if ($card['premium_note']): ?><li>Value vs. the normal card: <?= e((string)$card['premium_note']) ?></li><?php endif; ?>
            <?php if ($card['rarity_note']): ?><li>How often it appears: <?= e((string)$card['rarity_note']) ?></li><?php endif; ?>
            <?php if ($card['slab_label']): ?><li>On a graded slab it's usually designated: <?= e((string)$card['slab_label']) ?></li><?php endif; ?>
        </ul>
        <p style="margin:16px 0 0">
            <a class="btn btn-primary" href="<?= e(ebay_sold_link(trim($cardTitle . ' ' . $card['error_name']))) ?>" target="_blank" rel="noopener">See recent sold prices ›</a>
        </p>
        <p style="margin:14px 0 0;color:var(--muted)"><small>Values move constantly and identification can be subtle — always compare against a known example and recent sold listings before you buy or sell.</small></p>
    </div>

    <?php if ($isMember): ?>
        <div class="card" style="margin-bottom:16px<?= $matches ? ';border-left:4px solid #3aa66a' : '' ?>">
            <h2 style="margin-top:0">🎯 Live auctions that might be this error<?= $matches ? ' (' . count($matches) . ')' : '' ?></h2>
            <?php if (!$matches): ?>
                <p style="margin:0;color:var(--muted)">Nothing currently tracked matches this one. We check every 30 minutes.</p>
            <?php else: ?>
            <div style="overflow-x:auto"><table>
                <tr><th>Listing</th><th>Now</th><th>Bids</th><th></th></tr>
                <?php foreach ($matches as $m): $l = $m['listing']; ?>
                <tr>
                    <td style="max-width:420px"><?= e((string)$l['title']) ?></td>
                    <td style="white-space:nowrap">$<?= number_format((float)$l['price'], 2) ?></td>
                    <td><?= (int)$l['bid_count'] ?></td>
                    <td><a class="btn btn-sm" href="<?= e(epn_link((string)$l['item_url'])) ?>" target="_blank" rel="noopener">View</a></td>
                </tr>
                <?php endforeach; ?>
            </table></div>
            <p style="margin:10px 0 0;color:var(--muted)"><small>A title match is a lead, not proof — verify with "How to spot it" above before bidding.</small></p>
            <?php endif; ?>
        </div>
    <?php else: ?>
        <div class="mock-note">🔒 Members see <strong>live auctions that might be this error</strong>, checked every 30 minutes.
            <a href="/signup.php">Start free ›</a></div>
    <?php endif; ?>
    <?php
    layout_footer();
    return;
}

// ----------------------------------------------------------------- Index page
$sport = isset($SPORTS[$_GET['sport'] ?? '']) ? (string)$_GET['sport'] : 'all';
$type  = isset(ErrorCards::TYPES[$_GET['type'] ?? '']) ? (string)$_GET['type'] : 'all';
$q     = trim((string)($_GET['q'] ?? ''));
$cards = ErrorCards::published($pdo, $sport, $type, $q, 300);

layout_header(
    'Error Card Library — misprints, wrong photos and variations',
    'public',
    'A free reference of sports card errors and variations: what each error is, exactly how to spot it, whether a corrected version exists, and what it means for value.'
);
?>
<h1>🔍 Error Card Library</h1>
<p class="sub">Errors and variations are the one thing that can make a common card valuable — and the one thing most listings never mention. Learn what to look for, and stop scrolling past them.</p>

<form method="get" class="searchbar" style="margin-bottom:16px;display:flex;gap:8px;flex-wrap:wrap">
    <input name="q" value="<?= e($q) ?>" placeholder="Search player, set, or error…" style="flex:1;min-width:200px">
    <select name="sport">
        <option value="all">All sports</option>
        <?php foreach ($SPORTS as $k => $m): ?><option value="<?= e($k) ?>"<?= $sport === $k ? ' selected' : '' ?>><?= e($m['emoji'] . ' ' . $m['label']) ?></option><?php endforeach; ?>
    </select>
    <select name="type">
        <option value="all">All error types</option>
        <?php foreach (ErrorCards::TYPES as $k => $label): ?><option value="<?= e($k) ?>"<?= $type === $k ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
    </select>
    <button class="btn btn-primary" type="submit">Search</button>
</form>

<?php if (!$cards): ?>
    <div class="empty" style="padding:40px">
        <?= $q !== '' || $sport !== 'all' || $type !== 'all'
            ? 'No error cards match that search. <a href="/errors.php">See all ›</a>'
            : 'The library is being built — check back shortly.' ?>
    </div>
<?php else: ?>
    <div class="deals">
        <?php foreach ($cards as $c): ?>
        <div class="deal" style="display:block">
            <div style="font-size:.78rem;color:var(--muted);text-transform:uppercase;letter-spacing:.05em">
                <?= e(ErrorCards::TYPES[$c['error_type']] ?? 'Variation') ?>
            </div>
            <div class="title" style="margin-top:4px;font-size:1.05rem"><a href="/errors.php?card=<?= e((string)$c['slug']) ?>"><?= e((string)$c['error_name']) ?></a></div>
            <div class="baseline" style="margin-top:4px"><?= e(trim(($c['year'] ?? '') . ' ' . ($c['set_name'] ?? '') . ' · ' . ($c['player'] ?? ''))) ?></div>
            <?php if ($c['what_to_look_for']): ?>
                <p style="margin:10px 0 0;font-size:.88rem;color:var(--muted)"><strong style="color:var(--text)">Look for:</strong>
                    <?= e(mb_strimwidth((string)$c['what_to_look_for'], 0, 140, '…')) ?></p>
            <?php endif; ?>
            <div class="actions" style="margin-top:12px"><a class="btn btn-sm" href="/errors.php?card=<?= e((string)$c['slug']) ?>">How to spot it ›</a></div>
        </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php if (!$isMember): ?>
<div class="card" style="margin-top:22px;text-align:center">
    <h2 style="margin-top:0">Want to be told when one shows up?</h2>
    <p style="margin:0 0 14px;color:var(--muted)">Members get live auctions matched against this library every 30 minutes — plus AI deal alerts, sold-comp pricing, and collection tracking.</p>
    <a class="btn btn-primary btn-lg" href="/signup.php">Start free</a>
</div>
<?php endif; ?>
<?php
layout_footer();
