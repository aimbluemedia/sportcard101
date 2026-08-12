<?php
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';
require __DIR__ . '/../src/layout.php';

use SportCard101\Auth;
use SportCard101\AiAnalyst;
use SportCard101\ErrorCards;

Auth::requireAdmin();
ErrorCards::ensureTable($pdo);

$SPORTS = card_sports();

// ---- Actions ---------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = (string)($_POST['action'] ?? '');
    $id     = (int)($_POST['id'] ?? 0);
    $s      = fn (string $k) => trim((string)($_POST[$k] ?? '')) ?: null;

    if ($action === 'draft') {
        $ai = new AiAnalyst($config['ai']);
        if ($ai->isMock()) {
            flash('error', 'No Anthropic API key configured — add one in config.php to draft entries.');
        } else {
            $sport = isset($SPORTS[$_POST['sport'] ?? '']) ? (string)$_POST['sport'] : 'all';
            $n     = max(1, min(15, (int)($_POST['count'] ?? 8)));
            $drafts = $ai->draftErrorCards($sport, $n, ErrorCards::existingSummaries($pdo));
            $added = 0;
            foreach ($drafts as $d) {
                if (trim((string)($d['error_name'] ?? '')) === '') {
                    continue;
                }
                ErrorCards::insert($pdo, $d + ['source' => 'AI', 'status' => 'DRAFT']);
                $added++;
            }
            flash($added ? 'success' : 'error', $added
                ? "{$added} draft(s) added below — review each one before publishing. Low-confidence entries are listed first."
                : 'The AI returned no entries it was confident about. Try a specific sport, or add entries manually.');
        }
    } elseif ($action === 'save') {
        $fields = [
            'error_name' => $s('error_name') ?? 'Untitled error',
            'sport' => isset($SPORTS[$_POST['sport'] ?? '']) ? (string)$_POST['sport'] : null,
            'year' => $s('year'), 'set_name' => $s('set_name'), 'card_number' => $s('card_number'),
            'player' => $s('player'),
            'error_type' => isset(ErrorCards::TYPES[$_POST['error_type'] ?? '']) ? (string)$_POST['error_type'] : 'other',
            'description' => $s('description'), 'what_to_look_for' => $s('what_to_look_for'),
            'corrected_exists' => isset($_POST['corrected_exists']) ? 1 : 0,
            'scarcer' => isset(ErrorCards::SCARCER[$_POST['scarcer'] ?? '']) ? (string)$_POST['scarcer'] : 'UNKNOWN',
            'slab_label' => $s('slab_label'), 'premium_note' => $s('premium_note'),
            'rarity_note' => $s('rarity_note'), 'image_url' => $s('image_url'),
            'search_terms' => $s('search_terms'),
        ];
        if ($id > 0) {
            $sql = 'UPDATE error_cards SET ' . implode(', ', array_map(fn ($k) => "$k = ?", array_keys($fields))) . ' WHERE id = ?';
            $pdo->prepare($sql)->execute([...array_values($fields), $id]);
            flash('success', 'Entry saved.');
        } else {
            ErrorCards::insert($pdo, $fields + ['source' => 'MANUAL', 'status' => 'PUBLISHED']);
            flash('success', 'Entry added and published.');
        }
    } elseif ($action === 'publish') {
        $pdo->prepare("UPDATE error_cards SET status='PUBLISHED' WHERE id=?")->execute([$id]);
        flash('success', 'Published — it now appears in the public library.');
    } elseif ($action === 'reject') {
        $pdo->prepare("UPDATE error_cards SET status='REJECTED' WHERE id=?")->execute([$id]);
        flash('success', 'Rejected — hidden from the library.');
    } elseif ($action === 'unpublish') {
        $pdo->prepare("UPDATE error_cards SET status='DRAFT' WHERE id=?")->execute([$id]);
        flash('success', 'Moved back to review.');
    } elseif ($action === 'delete') {
        $pdo->prepare('DELETE FROM error_cards WHERE id=?')->execute([$id]);
        flash('success', 'Entry deleted.');
    }
    redirect('/superadmin/errorcards.php');
}

// ---- Data ------------------------------------------------------------------
$editing = null;
if (isset($_GET['edit'])) {
    $stmt = $pdo->prepare('SELECT * FROM error_cards WHERE id = ?');
    $stmt->execute([(int)$_GET['edit']]);
    $editing = $stmt->fetch() ?: null;
}

// Low confidence first — those need the most scrutiny.
$drafts = $pdo->query(
    "SELECT * FROM error_cards WHERE status='DRAFT' ORDER BY COALESCE(confidence,0) ASC, id DESC LIMIT 100"
)->fetchAll();
$live = $pdo->query(
    "SELECT * FROM error_cards WHERE status='PUBLISHED' ORDER BY year DESC, player ASC LIMIT 300"
)->fetchAll();
$rejected = (int) $pdo->query("SELECT COUNT(*) FROM error_cards WHERE status='REJECTED'")->fetchColumn();

// Detector proof: live auctions matching published entries.
$liveListings = [];
try {
    $liveListings = $pdo->query(
        "SELECT l.title, l.price, l.bid_count, l.end_time, l.item_url
         FROM listings l WHERE l.end_time > UTC_TIMESTAMP() ORDER BY l.end_time ASC LIMIT 600"
    )->fetchAll();
} catch (\Throwable $e) {
}
$matches = ErrorCards::matchListings($pdo, $liveListings, $live);
$mined   = ErrorCards::mineCandidates($pdo, 25);

$fv = fn (string $k, string $d = '') => e((string)($editing[$k] ?? $d));

layout_header('Error Cards', 'admin');
?>
<h1>🔍 Error Cards</h1>
<p class="sub">The catalog behind the public error-card library and the live-listing detector. AI drafts land here for review — <strong>nothing reaches the public library until you publish it</strong>.</p>

<div class="card" style="margin-bottom:16px">
    <h2 style="margin-top:0">🤖 Draft new entries</h2>
    <form method="post" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center"><?= csrf_field() ?>
        <input type="hidden" name="action" value="draft">
        <select name="sport">
            <option value="all">All sports</option>
            <?php foreach ($SPORTS as $k => $m): ?><option value="<?= e($k) ?>"><?= e($m['emoji'] . ' ' . $m['label']) ?></option><?php endforeach; ?>
        </select>
        <input name="count" type="number" min="1" max="15" value="8" style="width:80px" title="How many to attempt">
        <button class="btn btn-primary" type="submit">Draft entries with AI</button>
        <span style="color:var(--muted)"><small>Already catalogued entries are excluded so drafts don't repeat.</small></span>
    </form>
    <p style="margin:12px 0 0;color:var(--muted)"><small>⚠️ AI-drafted facts need verification — card numbers, which version is scarcer, and premiums are all easy to get subtly wrong. Each entry reports its own confidence, and the lowest scores are listed first below. Spot-check against a sold-listings search before publishing.</small></p>
</div>

<?php if ($drafts): ?>
<div class="card" style="margin-bottom:16px;border-left:4px solid #e0a935">
    <h2 style="margin-top:0">📝 Review queue (<?= count($drafts) ?>)</h2>
    <?php foreach ($drafts as $d): ?>
    <div style="border-top:1px solid var(--border);padding:14px 0">
        <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:baseline">
            <strong style="font-size:1.05rem"><?= e((string)$d['error_name']) ?></strong>
            <span style="color:var(--muted)"><?= e(trim(($d['year'] ?? '') . ' ' . ($d['set_name'] ?? '') . ' · ' . ($d['player'] ?? ''))) ?><?= $d['card_number'] ? ' · #' . e((string)$d['card_number']) : '' ?></span>
            <?php $c = (int)($d['confidence'] ?? 0); ?>
            <span style="color:<?= $c >= 80 ? '#1d7d46' : ($c >= 60 ? '#e0a935' : '#e05555') ?>;font-weight:700">confidence <?= $c ?>%</span>
            <span style="color:var(--muted)"><?= e(ErrorCards::TYPES[$d['error_type']] ?? $d['error_type']) ?></span>
        </div>
        <?php if ($d['description']): ?><p style="margin:6px 0 0"><?= e((string)$d['description']) ?></p><?php endif; ?>
        <?php if ($d['what_to_look_for']): ?><p style="margin:6px 0 0;color:var(--muted)"><strong>Look for:</strong> <?= e((string)$d['what_to_look_for']) ?></p><?php endif; ?>
        <p style="margin:6px 0 0;color:var(--muted)"><small>
            <?= $d['corrected_exists'] ? 'Corrected version exists · ' : '' ?><?= e(ErrorCards::SCARCER[$d['scarcer']] ?? '') ?>
            <?= $d['premium_note'] ? ' · ' . e((string)$d['premium_note']) : '' ?>
            <?= $d['search_terms'] ? ' · terms: ' . e((string)$d['search_terms']) : '' ?>
        </small></p>
        <div style="display:flex;gap:6px;margin-top:10px;flex-wrap:wrap">
            <a class="btn btn-sm" href="<?= e(ebay_sold_link(trim(($d['year'] ?? '') . ' ' . ($d['player'] ?? '') . ' ' . ($d['error_name'] ?? '')))) ?>" target="_blank" rel="noopener">Verify on eBay sold ›</a>
            <a class="btn btn-sm" href="/superadmin/errorcards.php?edit=<?= (int)$d['id'] ?>">Edit</a>
            <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="action" value="publish"><input type="hidden" name="id" value="<?= (int)$d['id'] ?>"><button class="btn btn-sm btn-primary" type="submit">Publish</button></form>
            <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="action" value="reject"><input type="hidden" name="id" value="<?= (int)$d['id'] ?>"><button class="btn btn-sm" type="submit">Reject</button></form>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<?php if ($matches): ?>
<div class="card" style="margin-bottom:16px;border-left:4px solid #3aa66a">
    <h2 style="margin-top:0">🎯 Possible error cards in live auctions (<?= count($matches) ?>)</h2>
    <p class="sub" style="margin-bottom:12px">Live listings matching a published catalog entry. Verify against the "look for" note before bidding — a title match isn't proof.</p>
    <div style="overflow-x:auto"><table>
        <tr><th>Listing</th><th>Now</th><th>Possible error</th><th>Look for</th><th></th></tr>
        <?php foreach ($matches as $m): $l = $m['listing']; $er = $m['error']; ?>
        <tr>
            <td style="max-width:300px"><?= e((string)$l['title']) ?></td>
            <td style="white-space:nowrap">$<?= number_format((float)$l['price'], 2) ?></td>
            <td><strong><?= e((string)$er['error_name']) ?></strong></td>
            <td style="max-width:280px"><small><?= e((string)($er['what_to_look_for'] ?? '')) ?></small></td>
            <td><a class="btn btn-sm" href="<?= e(epn_link((string)$l['item_url'])) ?>" target="_blank" rel="noopener">View</a></td>
        </tr>
        <?php endforeach; ?>
    </table></div>
</div>
<?php endif; ?>

<div class="card" style="margin-bottom:16px">
    <h2 style="margin-top:0"><?= $editing ? 'Edit entry' : 'Add an entry manually' ?></h2>
    <form method="post"><?= csrf_field() ?>
        <input type="hidden" name="action" value="save">
        <?php if ($editing): ?><input type="hidden" name="id" value="<?= (int)$editing['id'] ?>"><?php endif; ?>
        <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(190px,1fr));gap:10px">
            <div style="grid-column:1/-1"><label>Error name</label><input name="error_name" value="<?= $fv('error_name') ?>" placeholder="No Name on Front (NNOF)" required></div>
            <div><label>Sport</label><select name="sport"><option value="">—</option>
                <?php foreach ($SPORTS as $k => $m): ?><option value="<?= e($k) ?>"<?= ($editing['sport'] ?? '') === $k ? ' selected' : '' ?>><?= e($m['label']) ?></option><?php endforeach; ?>
            </select></div>
            <div><label>Year</label><input name="year" value="<?= $fv('year') ?>" placeholder="1990"></div>
            <div><label>Set</label><input name="set_name" value="<?= $fv('set_name') ?>" placeholder="Topps"></div>
            <div><label>Card #</label><input name="card_number" value="<?= $fv('card_number') ?>" placeholder="414"></div>
            <div><label>Player</label><input name="player" value="<?= $fv('player') ?>" placeholder="Frank Thomas"></div>
            <div><label>Error type</label><select name="error_type">
                <?php foreach (ErrorCards::TYPES as $k => $label): ?><option value="<?= e($k) ?>"<?= ($editing['error_type'] ?? '') === $k ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
            </select></div>
            <div><label>Scarcer version</label><select name="scarcer">
                <?php foreach (ErrorCards::SCARCER as $k => $label): ?><option value="<?= e($k) ?>"<?= ($editing['scarcer'] ?? '') === $k ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
            </select></div>
            <div><label style="display:flex;gap:6px;align-items:center;margin-top:22px"><input type="checkbox" name="corrected_exists" value="1" <?= !empty($editing['corrected_exists']) ? 'checked' : '' ?>> Corrected version exists</label></div>
            <div style="grid-column:1/-1"><label>Description</label><input name="description" value="<?= $fv('description') ?>" placeholder="What the error is"></div>
            <div style="grid-column:1/-1"><label>What to look for</label><input name="what_to_look_for" value="<?= $fv('what_to_look_for') ?>" placeholder="The physical check — where on the card, and what the normal version shows"></div>
            <div><label>Slab label</label><input name="slab_label" value="<?= $fv('slab_label') ?>" placeholder="PSA: 'NNOF'"></div>
            <div><label>Premium vs base</label><input name="premium_note" value="<?= $fv('premium_note') ?>" placeholder="Many times the base card"></div>
            <div><label>Rarity</label><input name="rarity_note" value="<?= $fv('rarity_note') ?>" placeholder="Turns up a few times a month"></div>
            <div style="grid-column:1/-1"><label>Search terms (comma separated, lowercase)</label><input name="search_terms" value="<?= $fv('search_terms') ?>" placeholder="nnof, no name on front, no name"></div>
            <div style="grid-column:1/-1"><label>Image URL</label><input name="image_url" value="<?= $fv('image_url') ?>" placeholder="https://…"></div>
        </div>
        <div style="margin-top:14px;display:flex;gap:8px">
            <button class="btn btn-primary" type="submit"><?= $editing ? 'Save entry' : 'Add + publish' ?></button>
            <?php if ($editing): ?><a class="btn" href="/superadmin/errorcards.php">Cancel</a><?php endif; ?>
        </div>
    </form>
</div>

<div class="card" style="margin-bottom:16px">
    <h2 style="margin-top:0">✅ Published (<?= count($live) ?>)
        <small style="color:var(--muted);font-weight:400">· <a href="/errors.php" target="_blank">view public library ›</a><?= $rejected ? " · {$rejected} rejected" : '' ?></small></h2>
    <?php if (!$live): ?>
        <p style="margin:0;color:var(--muted)">Nothing published yet — draft some entries above, review them, and publish the ones that check out.</p>
    <?php else: ?>
    <div style="overflow-x:auto"><table>
        <tr><th>Error</th><th>Card</th><th>Type</th><th>Terms</th><th></th></tr>
        <?php foreach ($live as $er): ?>
        <tr>
            <td><strong><?= e((string)$er['error_name']) ?></strong></td>
            <td><?= e(trim(($er['year'] ?? '') . ' ' . ($er['set_name'] ?? '') . ' ' . ($er['player'] ?? ''))) ?></td>
            <td><?= e(ErrorCards::TYPES[$er['error_type']] ?? $er['error_type']) ?></td>
            <td><small style="color:var(--muted)"><?= e((string)($er['search_terms'] ?? '—')) ?></small></td>
            <td><div style="display:flex;gap:6px">
                <a class="btn btn-sm" href="/errors.php?card=<?= e((string)$er['slug']) ?>" target="_blank">View</a>
                <a class="btn btn-sm" href="/superadmin/errorcards.php?edit=<?= (int)$er['id'] ?>">Edit</a>
                <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="action" value="unpublish"><input type="hidden" name="id" value="<?= (int)$er['id'] ?>"><button class="btn btn-sm" type="submit">Unpublish</button></form>
                <form method="post" class="inline" onsubmit="return confirm('Delete this entry?')"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$er['id'] ?>"><button class="btn btn-sm" type="submit">✕</button></form>
            </div></td>
        </tr>
        <?php endforeach; ?>
    </table></div>
    <?php endif; ?>
</div>

<?php if ($mined): ?>
<div class="card">
    <h2 style="margin-top:0">⛏️ Candidates from your scan history (<?= count($mined) ?>)</h2>
    <p class="sub" style="margin-bottom:12px">Listings your scanner already captured whose titles advertise an error or variation — free leads for new catalog entries.</p>
    <div style="overflow-x:auto"><table>
        <tr><th>Listing title</th><th>Price</th><th></th></tr>
        <?php foreach ($mined as $m): ?>
        <tr>
            <td style="max-width:520px"><?= e((string)$m['title']) ?></td>
            <td style="white-space:nowrap">$<?= number_format((float)$m['price'], 2) ?></td>
            <td><a class="btn btn-sm" href="<?= e(epn_link((string)$m['item_url'])) ?>" target="_blank" rel="noopener">View</a></td>
        </tr>
        <?php endforeach; ?>
    </table></div>
</div>
<?php endif; ?>
<?php
layout_footer();
