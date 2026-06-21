<?php
declare(strict_types=1);

require __DIR__ . '/src/bootstrap.php';
require __DIR__ . '/src/layout.php';

use SportCard101\Auth;

$next = isset($_GET['next']) ? (string)$_GET['next'] : '/member/';
if (Auth::check()) {
    redirect(Auth::isAdmin() ? '/superadmin/' : '/member/');
}

$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $login = trim((string)($_POST['login'] ?? ''));
    $pass  = (string)($_POST['password'] ?? '');
    if (Auth::attempt($pdo, $login, $pass)) {
        if (Auth::isAdmin()) {
            redirect('/superadmin/');
        }
        // Only allow same-site relative redirects.
        redirect(str_starts_with($next, '/') ? $next : '/member/');
    }
    $error = 'Invalid login or password.';
    usleep(400000);
}

layout_header('Log in', 'public');
?>
<div class="auth-wrap">
    <h1>Member log in</h1>
    <div class="card">
        <?php if ($error): ?><div class="flash flash-error"><?= e($error) ?></div><?php endif; ?>
        <form method="post" class="stack">
            <?= csrf_field() ?>
            <label for="login">Username or email</label>
            <input id="login" name="login" autocomplete="username" autofocus required>
            <label for="password">Password</label>
            <input id="password" name="password" type="password" autocomplete="current-password" required>
            <button class="btn btn-primary" style="width:100%;margin-top:18px" type="submit">Log in</button>
        </form>
        <p class="sub" style="text-align:center;margin-top:16px">New here? <a href="/signup.php">Create a free account</a></p>
    </div>
</div>
<?php
layout_footer();
