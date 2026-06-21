<?php
declare(strict_types=1);

/**
 * Shared HTML layout. Pages call layout_header($title) then layout_footer().
 */

function layout_header(string $title, bool $showNav = true): void
{
    $user = \Sportscard101\Auth::username();
    ?><!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($title) ?> · Sportscard101</title>
    <link rel="stylesheet" href="assets/style.css">
</head>
<body>
<?php if ($showNav): ?>
<header class="topbar">
    <a class="brand" href="index.php">🃏 Sportscard<span>101</span></a>
    <nav>
        <a href="index.php">Deals</a>
        <a href="searches.php">Searches</a>
        <form method="post" action="scan.php" class="inline">
            <?= csrf_field() ?>
            <button type="submit" class="btn btn-scan">⟳ Scan now</button>
        </form>
        <span class="user"><?= e($user) ?></span>
        <a class="logout" href="logout.php">Log out</a>
    </nav>
</header>
<?php endif; ?>
<main class="container">
<?php
    // Flash message support.
    if (!empty($_SESSION['flash'])) {
        foreach ($_SESSION['flash'] as $f) {
            echo '<div class="flash flash-' . e($f['type']) . '">' . e($f['msg']) . '</div>';
        }
        unset($_SESSION['flash']);
    }
}

function layout_footer(): void
{
    ?>
</main>
<footer class="foot">Sportscard101 — AI card deal engine</footer>
</body>
</html>
<?php
}

function flash(string $type, string $msg): void
{
    $_SESSION['flash'][] = ['type' => $type, 'msg' => $msg];
}
