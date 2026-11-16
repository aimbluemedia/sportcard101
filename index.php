<?php
declare(strict_types=1);

/**
 * Public landing page.
 *
 * This page deliberately does NOT use layout_header()/layout_footer(): the
 * marketing site has its own shell and stylesheet (assets/home.css) so the
 * app chrome in style.css can change without touching the front door.
 */

require __DIR__ . '/src/bootstrap.php';

use SportCard101\Auth;
use SportCard101\Playbook;

// Already logged in? Send them where they belong.
if (Auth::isAdmin()) {
    redirect('/superadmin/');
}
$loggedIn = Auth::check();

/* ---------------------------------------------------------------------------
   Pricing comes from the plans table (Superadmin → Pricing is the source of
   truth). Fall back to the advertised $9/month if no paid plan is configured.
   --------------------------------------------------------------------------- */
$paid = null;
$free = null;
try {
    foreach ($pdo->query('SELECT * FROM plans WHERE is_active = 1 ORDER BY sort, price_cents')->fetchAll() as $p) {
        if ((int) $p['price_cents'] > 0) {
            $paid ??= $p;
        } else {
            $free ??= $p;
        }
    }
} catch (\Throwable $e) {
    // A missing/unreachable plans table must never take the homepage down.
}

$paidPrice  = $paid ? money_cents((int) $paid['price_cents']) : '$9';
$paidCycle  = $paid ? (string) $paid['bill_interval'] : 'month';
$paidName   = $paid ? (string) $paid['name'] : 'Pro';
$paidSlug   = $paid ? (string) $paid['slug'] : 'pro';
$freeSlug   = $free ? (string) $free['slug'] : 'free';

// Plan features: use whatever the admin typed, else the defaults below.
$splitFeatures = static function (?string $raw, array $fallback): array {
    $rows = array_values(array_filter(array_map('trim', explode("\n", (string) $raw))));
    return $rows ?: $fallback;
};

$freeFeatures = $splitFeatures($free['features'] ?? null, [
    'The daily email: top deals, fresh comps, one error card to learn',
    'Public error-card library with AI explanations',
    'A member account you can upgrade any time',
    'No credit card, no trial clock',
]);

$paidFeatures = $splitFeatures($paid['features'] ?? null, [
    'Everything in Free, plus every tool below',
    'Morning Playbook — the buy list with a hard max bid on each card',
    'Snap Shot — score what is live on eBay right now, on demand',
    'Full auction board: sport, grade, rookie, signed, brand and HOF filters',
    'Sold-comps engine — median, floor and ceiling by grade',
    'High Bids — all-time leaderboards and per-card demand profiles',
    'Bulk lot analyser with a recommended max per lot',
    'Error-card radar matched against live listings',
    'My Collection — cost, shipping, sold price and real profit per card',
    'Instant email alerts on your own saved triggers',
]);

/* ---------------------------------------------------------------------------
   Hero copy stays editable in Superadmin → Settings. Wrapping words in
   *asterisks* highlights them. The strings seeded by the original installer
   count as "never edited", so this page's own headline wins until an admin
   actually types something.
   --------------------------------------------------------------------------- */
const HERO_TITLE_DEFAULT = 'Sports cards are a *real business*. Most people just bid blind.';
const HERO_SUB_DEFAULT   = 'SportCard101 watches the graded-card auctions all day, prices every card '
    . 'against what it actually sold for, and tells you the one number that decides whether you make '
    . 'money: *your maximum bid*.';
const HERO_LEGACY = [
    'Learn to find and flip sports cards — with AI.',
    'SportCard101 teaches you the hobby and hands you an AI engine that spots underpriced PSA 10 deals on eBay before everyone else.',
];

$heroTitle = trim((string) setting('hero_title', ''));
$heroSub   = trim((string) setting('hero_subtitle', ''));
if ($heroTitle === '' || in_array($heroTitle, HERO_LEGACY, true)) {
    $heroTitle = HERO_TITLE_DEFAULT;
}
if ($heroSub === '' || in_array($heroSub, HERO_LEGACY, true)) {
    $heroSub = HERO_SUB_DEFAULT;
}

/** Escape first, then turn *marked* runs into the given tag — never the reverse. */
$marked = static function (string $raw, string $openTag, string $closeTag): string {
    return preg_replace('/\*(.+?)\*/s', $openTag . '$1' . $closeTag, e($raw)) ?? e($raw);
};

$feeRate  = Playbook::FEE_RATE;
$shipCost = Playbook::SHIP_COST;

$flashes = $_SESSION['flash'] ?? [];
unset($_SESSION['flash']);

$metaDesc = 'SportCard101 finds underpriced graded sports cards on eBay, prices them against real sold comps, '
    . 'and hands you a hard max bid. Free daily email, or ' . $paidPrice . '/' . $paidCycle . ' for every tool.';

/** Members-area tool showcase. */
$tools = [
    ['📋', 'Morning Playbook', false, 'Every morning, the handful of live auctions worth your money — each with a buy-max you should never cross, and why.'],
    ['⚡', 'Snap Shot', false, 'A one-click read on what is on the block right this minute, scored against comps and bidder heat.'],
    ['🔨', 'Live Auction Board', false, 'Every graded auction we track, filterable by sport, grade, rookie, signed, brand and Hall of Fame.'],
    ['📊', 'Sold Comps Engine', false, 'The real closed prices behind every number — median, floor and ceiling, split out by grade.'],
    ['🔥', 'High Bids', false, 'Which cards collectors actually fight over. All-time leaderboards plus a demand profile per card.'],
    ['🔎', 'Error Card Radar', true, 'Misprints, missing names, wrong backs, upside-down logos — explained, then matched to live listings.'],
    ['📦', 'Bulk Lot Analyser', false, 'Lots broken down to a price per card, with a recommended maximum before you bid.'],
    ['🗃️', 'My Collection', false, 'Cost, shipping, sold price, profit and hold time. Unsold on top, closed flips below.'],
    ['🔔', 'Instant Alerts', false, 'Save your own triggers and get an email the moment something matching hits the block.'],
];
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>SportCard101 — build a business flipping graded sports cards</title>
<meta name="description" content="<?= e($metaDesc) ?>">
<meta property="og:title" content="SportCard101 — build a business flipping graded sports cards">
<meta property="og:description" content="<?= e($metaDesc) ?>">
<meta property="og:type" content="website">
<meta name="theme-color" content="#080b11">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/assets/home.css">
</head>
<body class="hm">

<!-- ===================== NAV ===================== -->
<header class="hm-nav">
    <div class="hm-wrap hm-nav-in">
        <a class="hm-brand" href="/"><span class="chip">🃏</span> Sport<b>Card101</b></a>
        <nav class="hm-nav-links">
            <a href="#how">How it works</a>
            <a href="#math">The math</a>
            <a href="#tools">Tools</a>
            <a href="#pricing">Pricing</a>
            <a href="/errors.php">Error cards</a>
        </nav>
        <div class="hm-nav-cta">
            <?php if ($loggedIn): ?>
                <a class="hm-btn hm-btn-gold hm-btn-sm" href="/member/">Members area →</a>
            <?php else: ?>
                <a class="hm-btn hm-btn-ghost hm-btn-sm" href="/login.php">Log in</a>
                <a class="hm-btn hm-btn-gold hm-btn-sm" href="/signup.php">Join free</a>
            <?php endif; ?>
        </div>
    </div>
</header>

<?php foreach ($flashes as $f): ?>
    <div class="hm-wrap"><div class="hm-flash hm-flash-<?= e((string) $f['type']) ?>"><?= e((string) $f['msg']) ?></div></div>
<?php endforeach; ?>

<!-- ===================== HERO ===================== -->
<section class="hm-hero">
    <div class="hm-wrap hm-hero-grid">
        <div>
            <span class="hm-pill"><span class="dot"></span> Scanning eBay every <b>30 minutes</b></span>

            <h1><?= $marked($heroTitle, '<span class="hl">', '</span>') ?></h1>

            <p class="hm-hero-sub"><?= $marked($heroSub, '<strong>', '</strong>') ?></p>

            <div class="hm-capture">
                <form method="get" action="/signup.php">
                    <input type="email" name="email" placeholder="you@email.com" aria-label="Your email address" required>
                    <button class="hm-btn hm-btn-gold" type="submit">Get the daily email — free</button>
                </form>
                <p class="hm-capture-note">
                    <span>Free forever tier</span>
                    <span>No credit card</span>
                    <span>One email a morning</span>
                </p>
            </div>
        </div>

        <!-- product mock -->
        <div class="hm-mock">
            <div class="hm-mock-shell">
                <div class="hm-mock-bar">
                    <span class="lights"><i></i><i></i><i></i></span>
                    <span class="t">sportcard101.com / <b>daily plan</b></span>
                    <span class="tag">Sample</span>
                </div>

                <div class="hm-mock-head">
                    <h4><span class="dot"></span> Morning Playbook</h4>
                    <p class="hm-mock-stats">
                        <span><b>1,482</b> listings scanned</span>
                        <span><b>9</b> flagged</span>
                        <span><b>3</b> cleared to buy</span>
                    </p>
                </div>

                <div class="hm-mock-rows">
                    <div class="hm-row">
                        <span class="rank">1</span>
                        <span class="who">
                            <span class="nm">2018 Prizm Luka Dončić RC #280</span>
                            <span class="mt"><span class="g">PSA 10</span> <span>median $578 · 11 comps</span></span>
                        </span>
                        <span class="max"><span class="lbl">Max bid</span><span class="v">$412</span></span>
                        <span class="pl">+$89</span>
                    </div>
                    <div class="hm-row">
                        <span class="rank">2</span>
                        <span class="who">
                            <span class="nm">2000 Bowman Chrome Brady RC #236</span>
                            <span class="mt"><span class="g">BGS 9</span> <span>median $1,240 · 7 comps</span></span>
                        </span>
                        <span class="max"><span class="lbl">Max bid</span><span class="v">$905</span></span>
                        <span class="pl">+$170</span>
                    </div>
                    <div class="hm-row">
                        <span class="rank">3</span>
                        <span class="who">
                            <span class="nm">1989 Upper Deck Ken Griffey Jr. RC #1</span>
                            <span class="mt"><span class="g">PSA 9</span> <span>median $96 · 24 comps</span></span>
                        </span>
                        <span class="max"><span class="lbl">Max bid</span><span class="v">$61</span></span>
                        <span class="pl">+$17</span>
                    </div>
                </div>

                <div class="hm-mock-foot">
                    <span>Fees and shipping already taken off the top</span>
                    <span class="pb">Sent 7:00am</span>
                </div>
            </div>

            <div class="hm-float">
                <span class="ico">📧</span>
                <span class="tx">
                    <span class="a">Your daily email landed</span>
                    <span class="b">3 buys · 8 to watch · 2 new error cards</span>
                </span>
            </div>
        </div>
    </div>
</section>

<!-- ===================== PROOF STRIP ===================== -->
<section class="hm-proof">
    <div class="hm-wrap hm-proof-in">
        <span class="lbl">What it reads</span>
        <ul>
            <li>Live eBay auction data</li>
            <li>Real closed sale prices</li>
            <li>PSA · BGS · SGC grades</li>
            <li>AI listing analysis</li>
            <li>Bidder-demand history</li>
        </ul>
    </div>
</section>

<!-- ===================== HOW IT WORKS ===================== -->
<section class="hm-sec on-paper" id="how">
    <div class="hm-wrap">
        <div class="hm-sec-head">
            <p class="hm-eyebrow">How it works</p>
            <h2 class="hm-h2">Find it. Price it. Flip it.</h2>
            <p class="hm-lede">
                Three steps, and the third is the one almost everybody skips. Flipping cards
                is not about taste — it is about refusing to pay more than the math allows.
            </p>
        </div>

        <div class="hm-steps-grid">
            <article class="hm-step">
                <span class="n">1</span>
                <h3><em>Find</em> what's underpriced</h3>
                <p>
                    The engine pulls live graded auctions around the clock and runs every listing
                    through AI that reads the player, year, set, parallel, rookie status and grade
                    out of titles humans wrote badly on purpose.
                </p>
                <div class="uses"><i>Auction Board</i><i>Snap Shot</i><i>Bulk Lots</i></div>
            </article>

            <article class="hm-step">
                <span class="n">2</span>
                <h3><em>Price</em> it against reality</h3>
                <p>
                    Every card gets matched to the prices it actually closed at — by grade, not
                    by wishful thinking. You see the median, the floor and the ceiling, and how
                    many sales that number rests on.
                </p>
                <div class="uses"><i>Sold Comps</i><i>High Bids</i><i>Demand Profiles</i></div>
            </article>

            <article class="hm-step">
                <span class="n">3</span>
                <h3><em>Flip</em> it with a hard ceiling</h3>
                <p>
                    Selling fees and shipping come off the top, your target margin comes out next,
                    and what is left is your max bid. Bid it or walk away. That discipline
                    <em>is</em> the business.
                </p>
                <div class="uses"><i>Morning Playbook</i><i>My Collection</i><i>Alerts</i></div>
            </article>
        </div>
    </div>
</section>

<!-- ===================== CALCULATOR ===================== -->
<section class="hm-sec on-paper" id="math" style="padding-top:0">
    <div class="hm-wrap">
        <div class="hm-sec-head center">
            <p class="hm-eyebrow" style="justify-content:center">The math</p>
            <h2 class="hm-h2">What a few flips a month actually pays</h2>
            <p class="hm-lede">
                Move the sliders. This runs the same fee model the Morning Playbook uses to set
                your max bid — <?= e(number_format($feeRate * 100, 2)) ?>% selling fees and
                <?= e(money($shipCost)) ?> shipping per card, taken off every sale.
            </p>
        </div>

        <div class="hm-calc-grid">
            <div class="hm-calc-in">
                <h3>Your numbers</h3>
                <p>Start conservative. A card a week is a real pace for a beginner.</p>

                <div class="hm-field">
                    <div class="top">
                        <label for="c-vol">Cards flipped per month</label>
                        <span class="out" id="o-vol">8</span>
                    </div>
                    <input class="hm-range" type="range" id="c-vol" min="1" max="40" step="1" value="8">
                    <div class="ticks"><span>1</span><span>40</span></div>
                </div>

                <div class="hm-field">
                    <div class="top">
                        <label for="c-buy">Average price you pay</label>
                        <span class="out" id="o-buy">$120</span>
                    </div>
                    <input class="hm-range" type="range" id="c-buy" min="10" max="800" step="5" value="120">
                    <div class="ticks"><span>$10</span><span>$800</span></div>
                </div>

                <div class="hm-field">
                    <div class="top">
                        <label for="c-sell">Average price it sells for</label>
                        <span class="out" id="o-sell">$210</span>
                    </div>
                    <input class="hm-range" type="range" id="c-sell" min="10" max="1600" step="5" value="210">
                    <div class="ticks"><span>$10</span><span>$1,600</span></div>
                </div>

                <p class="hm-calc-fine">
                    An estimate, not a promise. Card prices move, grades disappoint, and some
                    flips lose money — which is exactly why the tools put a ceiling on what you bid.
                </p>
            </div>

            <div class="hm-calc-out">
                <span class="k">Estimated net profit / month</span>
                <div class="hm-big" id="o-net">$0</div>
                <span class="per" id="o-per">—</span>

                <div class="hm-break">
                    <div><span>Gross sales</span><b id="b-gross">$0</b></div>
                    <div><span>Selling fees (<?= e(number_format($feeRate * 100, 2)) ?>%)</span><b id="b-fees">$0</b></div>
                    <div><span>Shipping (<?= e(money($shipCost)) ?> × cards)</span><b id="b-ship">$0</b></div>
                    <div><span>What you paid for the cards</span><b id="b-cost">$0</b></div>
                    <div class="tot"><span>Net profit</span><b id="b-net">$0</b></div>
                </div>

                <div class="hm-calc-warn" id="o-warn" hidden>
                    At that sell price you lose money on every card. The Playbook would never
                    clear this bid — that is the point of a hard max.
                </div>

                <div class="hm-calc-cta">
                    <a class="hm-btn hm-btn-gold hm-btn-block" href="#pricing">Get the tools that find these →</a>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- ===================== TOOLS ===================== -->
<section class="hm-sec hm-tools" id="tools">
    <div class="hm-wrap">
        <div class="hm-sec-head center">
            <p class="hm-eyebrow" style="justify-content:center">Inside the members area</p>
            <h2 class="hm-h2">Nine tools. One price: <?= e($paidPrice) ?> a <?= e($paidCycle) ?>.</h2>
            <p class="hm-lede">
                No tiers inside the tier, no credits, nothing held back for an upsell.
                Membership opens all of it.
            </p>
        </div>

        <div class="hm-tool-grid">
            <?php foreach ($tools as [$icon, $name, $inFree, $copy]): ?>
                <article class="hm-tool">
                    <div class="ic"><?= e($icon) ?></div>
                    <h3><?= e($name) ?><?php if ($inFree): ?> <span class="free">Free too</span><?php endif; ?></h3>
                    <p><?= e($copy) ?></p>
                </article>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<!-- ===================== PRICING ===================== -->
<section class="hm-sec on-paper" id="pricing">
    <div class="hm-wrap">
        <div class="hm-sec-head center">
            <p class="hm-eyebrow" style="justify-content:center">Pricing</p>
            <h2 class="hm-h2">Free to join. <?= e($paidPrice) ?> to go all in.</h2>
            <p class="hm-lede">
                Start on the free email and watch the calls for a few weeks. Upgrade when you
                want to stop reading about the deals and start working the board yourself.
            </p>
        </div>

        <div class="hm-plans">
            <div class="hm-plan">
                <h3><?= e(($free['name'] ?? '') ?: 'Free') ?></h3>
                <p class="blurb"><?= e(($free['blurb'] ?? '') ?: 'The daily email, so you learn the market before you spend a dollar.') ?></p>
                <div class="hm-price"><span class="amt">$0</span><span class="cyc">forever</span></div>
                <p class="fine">No card, no trial countdown.</p>
                <ul>
                    <?php foreach ($freeFeatures as $f): ?><li><?= e($f) ?></li><?php endforeach; ?>
                </ul>
                <div class="cta">
                    <a class="hm-btn hm-btn-outline" href="/signup.php?plan=<?= e($freeSlug) ?>">Join free</a>
                </div>
            </div>

            <div class="hm-plan feat">
                <span class="flag">Most popular</span>
                <h3><?= e($paidName) ?></h3>
                <p class="blurb"><?= e(($paid['blurb'] ?? '') ?: 'Every tool in the members area, with nothing locked.') ?></p>
                <div class="hm-price"><span class="amt"><?= e($paidPrice) ?></span><span class="cyc">/ <?= e($paidCycle) ?></span></div>
                <p class="fine" style="color:#7d8ca3">Cancel whenever — it is a month, not a contract.</p>
                <ul>
                    <?php foreach ($paidFeatures as $f): ?><li><?= e($f) ?></li><?php endforeach; ?>
                </ul>
                <div class="cta">
                    <a class="hm-btn hm-btn-gold" href="/signup.php?plan=<?= e($paidSlug) ?>">Get all access</a>
                </div>
            </div>
        </div>

        <p class="hm-price-foot">
            One good flip a month covers the membership several times over. That is the whole pitch.
        </p>
    </div>
</section>

<!-- ===================== FINAL CTA ===================== -->
<section class="hm-cta">
    <div class="hm-wrap">
        <div class="hm-cta-box">
            <h2>Tomorrow morning, you could already know what to bid.</h2>
            <p>
                Join free and the next playbook lands in your inbox — the cards worth chasing,
                what they really sell for, and the number to stop at.
            </p>
            <div class="hm-cta-row">
                <a class="hm-btn hm-btn-dark hm-btn-lg" href="/signup.php">Get the daily email — free</a>
                <a class="hm-btn hm-btn-clear hm-btn-lg" href="#pricing">See all-access</a>
            </div>
            <p class="hm-cta-fine">Free tier forever · <?= e($paidPrice) ?>/<?= e($paidCycle) ?> for every tool · Cancel anytime</p>
        </div>
    </div>
</section>

<!-- ===================== FOOTER ===================== -->
<footer class="hm-foot">
    <div class="hm-wrap">
        <div class="hm-foot-grid">
            <div class="hm-foot-brand">
                <a class="hm-brand" href="/"><span class="chip">🃏</span> Sport<b>Card101</b></a>
                <p>Learn the hobby, find the deals, and keep a ledger that tells you the truth about your flips.</p>
            </div>
            <div>
                <h4>Product</h4>
                <ul>
                    <li><a href="#how">How it works</a></li>
                    <li><a href="#math">Profit math</a></li>
                    <li><a href="#tools">Tools</a></li>
                    <li><a href="#pricing">Pricing</a></li>
                </ul>
            </div>
            <div>
                <h4>Free to read</h4>
                <ul>
                    <li><a href="/errors.php">Error card library</a></li>
                    <li><a href="/signup.php">Daily email</a></li>
                </ul>
            </div>
            <div>
                <h4>Account</h4>
                <ul>
                    <li><a href="/signup.php">Join free</a></li>
                    <li><a href="/login.php">Log in</a></li>
                    <li><a href="/member/">Members area</a></li>
                </ul>
            </div>
        </div>
        <div class="hm-foot-bar">
            <span>© <?= date('Y') ?> SportCard101. Not affiliated with eBay, PSA, BGS or SGC.</span>
            <span>Card values move. Nothing here is investment advice.</span>
        </div>
    </div>
</footer>

<script>
(function () {
    var FEE  = <?= json_encode($feeRate) ?>;
    var SHIP = <?= json_encode($shipCost) ?>;

    var vol  = document.getElementById('c-vol');
    var buy  = document.getElementById('c-buy');
    var sell = document.getElementById('c-sell');
    if (!vol || !buy || !sell) { return; }

    function usd(n) {
        var neg = n < 0;
        var s = '$' + Math.abs(Math.round(n)).toLocaleString('en-US');
        return neg ? '−' + s : s;
    }
    function set(id, txt) { var el = document.getElementById(id); if (el) { el.textContent = txt; } }

    function render() {
        var n = +vol.value, b = +buy.value, s = +sell.value;

        var gross = s * n;
        var fees  = gross * FEE;
        var ship  = SHIP * n;
        var cost  = b * n;
        var net   = gross - fees - ship - cost;

        set('o-vol', String(n));
        set('o-buy', usd(b));
        set('o-sell', usd(s));

        set('b-gross', usd(gross));
        set('b-fees', '−' + usd(fees).replace('−', ''));
        set('b-ship', '−' + usd(ship).replace('−', ''));
        set('b-cost', '−' + usd(cost).replace('−', ''));
        set('b-net', usd(net));
        set('o-net', usd(net));

        var perCard = n > 0 ? net / n : 0;
        var roi = cost > 0 ? (net / cost) * 100 : 0;
        set('o-per', usd(perCard) + ' per card · ' + usd(net * 12) + ' a year · '
            + (roi >= 0 ? '' : '−') + Math.abs(Math.round(roi)) + '% return on cash');

        var big = document.getElementById('o-net');
        var tot = document.getElementById('b-net');
        if (big) { big.classList.toggle('neg', net < 0); }
        if (tot) { tot.classList.toggle('neg', net < 0); }

        var warn = document.getElementById('o-warn');
        if (warn) { warn.hidden = perCard >= 0; }
    }

    [vol, buy, sell].forEach(function (el) { el.addEventListener('input', render); });
    render();
})();
</script>
</body>
</html>
