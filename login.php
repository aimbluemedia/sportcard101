<?php
declare(strict_types=1);

require __DIR__ . '/src/bootstrap.php';
require __DIR__ . '/src/layout.php';

use Sportscard101\Auth;

if (Auth::check()) {
    redirect('index.php');
}

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $username = trim((string)($_POST['username'] ?? ''));
    $password = (string)($_POST['password'] ?? '');

    if (Auth::attempt($pdo, $username, $password)) {
        redirect('index.php');
    }
    $error = 'Invalid username or password.';
    // Small delay to slow brute-force attempts.
    usleep(400000);
}

layout_header('Log in', showNav: false);
?>
<div class="login-wrap">
    <span class="brand">🃏 Sportscard<span style="color:var(--accent)">101</span></span>
    <p class="sub" style="text-align:center">Learn to spot and flip card deals with AI</p>
    <div class="card">
        <?php if ($error): ?><div class="flash flash-error"><?= e($error) ?></div><?php endif; ?>
        <form method="post" class="stack">
            <?= csrf_field() ?>
            <label for="username">Username</label>
            <input id="username" name="username" autocomplete="username" autofocus required>
            <label for="password">Password</label>
            <input id="password" name="password" type="password" autocomplete="current-password" required>
            <button class="btn btn-primary" style="width:100%;margin-top:20px" type="submit">Log in</button>
        </form>
    </div>
</div>
<?php
layout_footer();
