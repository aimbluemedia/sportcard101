<?php
declare(strict_types=1);

/**
 * Shared HTML layout for all three areas: public site, /member, /superadmin.
 * Uses root-absolute URLs so it works from any folder depth.
 */

function nav_links(string $area): array
{
    return match ($area) {
        'member' => [
            ['/member/', 'Dashboard'],
            ['/member/deals.php', 'AI Deals'],
            ['/member/learn.php', 'Learn'],
            ['/member/account.php', 'Account'],
        ],
        'admin' => [
            ['/superadmin/', 'Dashboard'],
            ['/superadmin/dailyplan.php', 'Daily Plan'],
            ['/superadmin/snapshot.php', 'Snap Shot'],
            ['/superadmin/lots.php', 'Lots'],
            ['/superadmin/members.php', 'Members'],
            ['/superadmin/pricing.php', 'Pricing'],
            ['/superadmin/content.php', 'Content'],
            ['/superadmin/searches.php', 'AI App'],
            ['/superadmin/auctions.php', 'Auctions'],
            ['/superadmin/highbids.php', 'High Bids'],
            ['/superadmin/comps.php', 'Comps'],
            ['/superadmin/alerts.php', 'Alerts'],
            ['/superadmin/settings.php', 'Settings'],
        ],
        default => [
            ['/', 'Home'],
            ['/#features', 'Features'],
            ['/#pricing', 'Pricing'],
            ['/login.php', 'Log in'],
        ],
    };
}

function layout_header(string $title, string $area = 'public'): void
{
    $home = $area === 'admin' ? '/superadmin/' : ($area === 'member' ? '/member/' : '/');
    // Logged-in areas use a left sidebar; the public homepage keeps the top bar.
    $sidebar = in_array($area, ['admin', 'member'], true);
    $GLOBALS['__layout_sidebar'] = $sidebar;
    $path = strtok((string)($_SERVER['REQUEST_URI'] ?? '/'), '?');
    ?><!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($title) ?> · SportCard101</title>
    <link rel="stylesheet" href="/assets/style.css">
</head>
<body class="area-<?= e($area) ?><?= $sidebar ? ' has-sidebar' : '' ?>">
<?php if ($sidebar): ?>
<div class="shell">
    <aside class="sidebar">
        <a class="brand" href="<?= e($home) ?>">🃏 Sport<span>Card101</span><?php if ($area === 'admin'): ?> <small>admin</small><?php endif; ?></a>
        <nav class="side-nav">
            <?php foreach (nav_links($area) as [$href, $label]):
                $isActive = ($path === $href)
                    || (in_array($href, ['/superadmin/', '/member/'], true) && in_array($path, [$href, $href . 'index.php'], true)); ?>
                <a class="<?= $isActive ? 'active' : '' ?>" href="<?= e($href) ?>"><?= e($label) ?></a>
            <?php endforeach; ?>
        </nav>
        <div class="side-foot">
            <span class="user"><?= e(\SportCard101\Auth::username()) ?></span>
            <a class="logout" href="/logout.php">Log out</a>
        </div>
    </aside>
    <div class="content">
    <main class="container">
<?php else: ?>
<header class="topbar">
    <a class="brand" href="<?= e($home) ?>">🃏 Sport<span>Card101</span></a>
    <nav>
        <?php foreach (nav_links($area) as [$href, $label]): ?>
            <a href="<?= e($href) ?>"><?= e($label) ?></a>
        <?php endforeach; ?>
        <a class="btn btn-primary btn-sm" href="/signup.php">Join free</a>
    </nav>
</header>
<main class="container">
<?php endif; ?>
<?php
    if (!empty($_SESSION['flash'])) {
        foreach ($_SESSION['flash'] as $f) {
            echo '<div class="flash flash-' . e($f['type']) . '">' . e($f['msg']) . '</div>';
        }
        unset($_SESSION['flash']);
    }
}

function layout_footer(): void
{
    $sidebar = $GLOBALS['__layout_sidebar'] ?? false;
    ?>
</main>
<footer class="foot">SportCard101 — learn the hobby, find the deals · <a href="/">sportcard101.com</a></footer>
<?php if ($sidebar): ?>
    </div><!-- .content -->
</div><!-- .shell -->
<?php endif; ?>
</body>
</html>
<?php
}

function flash(string $type, string $msg): void
{
    $_SESSION['flash'][] = ['type' => $type, 'msg' => $msg];
}
