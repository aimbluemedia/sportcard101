<?php
declare(strict_types=1);

require __DIR__ . '/src/bootstrap.php';
require __DIR__ . '/src/layout.php';

use SportCard101\Auth;

if (Auth::check()) {
    redirect('/member/');
}

$ref  = isset($_GET['ref']) ? preg_replace('/[^A-Z0-9]/', '', strtoupper((string)$_GET['ref'])) : null;
$plan = isset($_GET['plan']) ? (string)$_GET['plan'] : null;
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $username = trim((string)($_POST['username'] ?? ''));
    $email    = trim((string)($_POST['email'] ?? ''));
    $password = (string)($_POST['password'] ?? '');
    $ref      = ($_POST['ref'] ?? '') !== '' ? preg_replace('/[^A-Z0-9]/', '', strtoupper((string)$_POST['ref'])) : null;

    if ($username === '' || $email === '' || strlen($password) < 8) {
        $error = 'Pick a username, a valid email, and a password of at least 8 characters.';
    } else {
        // Validate referral code (must belong to a real member).
        $referredBy = null;
        if ($ref) {
            $s = $pdo->prepare('SELECT affiliate_code FROM users WHERE affiliate_code = ? LIMIT 1');
            $s->execute([$ref]);
            $referredBy = $s->fetchColumn() ?: null;
        }

        [$id, $err] = Auth::create($pdo, $username, $email, $password, 'member', $referredBy ?: null);
        if ($err) {
            $error = $err;
        } else {
            // Record the referral for the affiliate.
            if ($referredBy) {
                $aff = $pdo->prepare('SELECT id FROM users WHERE affiliate_code = ?');
                $aff->execute([$referredBy]);
                if ($affId = $aff->fetchColumn()) {
                    $pdo->prepare('INSERT INTO referrals (affiliate_user_id, referred_user_id) VALUES (?, ?)')
                        ->execute([(int)$affId, $id]);
                }
            }
            Auth::attempt($pdo, $username, $password);
            flash('success', 'Welcome to SportCard101! Your free account is ready.');
            redirect('/member/');
        }
    }
}

layout_header('Join', 'public');
?>
<div class="auth-wrap">
    <h1>Create your free account</h1>
    <p class="sub">Start learning and see today's AI-scored deals.</p>
    <div class="card">
        <?php if ($error): ?><div class="flash flash-error"><?= e($error) ?></div><?php endif; ?>
        <form method="post" class="stack">
            <?= csrf_field() ?>
            <input type="hidden" name="ref" value="<?= e($ref ?? '') ?>">
            <label for="username">Username</label>
            <input id="username" name="username" autocomplete="username" required>
            <label for="email">Email</label>
            <input id="email" name="email" type="email" autocomplete="email" required>
            <label for="password">Password <small>(8+ characters)</small></label>
            <input id="password" name="password" type="password" autocomplete="new-password" required>
            <?php if ($ref): ?><p class="sub" style="margin-top:10px">Referred by <strong><?= e($ref) ?></strong> 🎉</p><?php endif; ?>
            <button class="btn btn-primary" style="width:100%;margin-top:18px" type="submit">Create account</button>
        </form>
        <p class="sub" style="text-align:center;margin-top:16px">Already a member? <a href="/login.php">Log in</a></p>
    </div>
</div>
<?php
layout_footer();
