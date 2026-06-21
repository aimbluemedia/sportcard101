<?php
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

use SportCard101\Auth;

if (Auth::isAdmin()) {
    redirect('/superadmin/');
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
        Auth::logout(); // a member tried the admin door
        $error = 'That account is not an administrator.';
    } else {
        $error = 'Invalid login or password.';
    }
    usleep(400000);
}
?><!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Admin · SportCard101</title>
    <link rel="stylesheet" href="/assets/style.css">
</head>
<body class="area-admin">
<main class="container">
    <div class="auth-wrap">
        <h1 style="text-align:center">🃏 SportCard101 <small style="color:var(--muted)">admin</small></h1>
        <div class="card">
            <?php if ($error): ?><div class="flash flash-error"><?= e($error) ?></div><?php endif; ?>
            <form method="post" class="stack">
                <?= csrf_field() ?>
                <label for="login">Admin username or email</label>
                <input id="login" name="login" autocomplete="username" autofocus required>
                <label for="password">Password</label>
                <input id="password" name="password" type="password" autocomplete="current-password" required>
                <button class="btn btn-primary" style="width:100%;margin-top:18px" type="submit">Log in</button>
            </form>
        </div>
    </div>
</main>
</body>
</html>
