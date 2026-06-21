<?php
declare(strict_types=1);

/**
 * One-time WEB INSTALLER (for hosts without SSH).
 *
 *   1. Make sure config.php exists with your DB credentials.
 *   2. Visit  https://sportcard101.com/setup.php  in your browser.
 *   3. Create your superadmin account.
 *   4. DELETE this file afterwards (it disables itself once an admin exists).
 */

require __DIR__ . '/src/bootstrap.php';
require __DIR__ . '/src/layout.php';

use SportCard101\Auth;

/** Does a superadmin already exist? (Tables may not exist yet → false.) */
function admin_exists(\PDO $pdo): bool
{
    try {
        return (bool)$pdo->query("SELECT 1 FROM users WHERE role='superadmin' LIMIT 1")->fetchColumn();
    } catch (\Throwable $e) {
        return false;
    }
}

$done  = false;
$error = null;
$alreadySetUp = admin_exists($pdo);

if (!$alreadySetUp && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $username = trim((string)($_POST['username'] ?? ''));
    $email    = trim((string)($_POST['email'] ?? ''));
    $password = (string)($_POST['password'] ?? '');

    if ($username === '' || $email === '' || strlen($password) < 8) {
        $error = 'Enter a username, valid email, and a password of at least 8 characters.';
    } else {
        try {
            // 1) Import the schema (idempotent — CREATE TABLE IF NOT EXISTS).
            $schema = file_get_contents(APP_ROOT . '/schema.sql');
            if ($schema !== false) {
                $pdo->exec($schema);
            }

            // 2) Create the superadmin.
            [$id, $err] = Auth::create($pdo, $username, $email, $password, 'superadmin');
            if ($err) {
                $error = $err;
            } else {
                // 3) Seed plans / settings / a sample lesson (only if empty).
                if ((int)$pdo->query('SELECT COUNT(*) FROM plans')->fetchColumn() === 0) {
                    $plan = $pdo->prepare('INSERT INTO plans (name, slug, price_cents, bill_interval, blurb, features, is_active, sort) VALUES (?,?,?,?,?,?,1,?)');
                    $plan->execute(['Free', 'free', 0, 'month', 'Start learning the hobby.', "Top 3 daily AI deals\nFree starter lessons\nMember community", 0]);
                    $plan->execute(['Pro', 'pro', 1900, 'month', 'The full deal engine + all lessons.', "Full AI deal board\nFlip-margin math\nHidden-gem alerts\nAll lessons & courses\nAffiliate program", 1]);
                }
                $set = $pdo->prepare('INSERT IGNORE INTO settings (skey, sval) VALUES (?, ?)');
                foreach ([
                    'site_url' => 'https://sportcard101.com',
                    'hero_title' => 'Learn to find and flip sports cards — with AI.',
                    'hero_subtitle' => 'SportCard101 teaches you the hobby and hands you an AI engine that spots underpriced PSA 10 deals on eBay before everyone else.',
                    'skool_url' => '',
                    'enable_member_searches' => '0',
                ] as $k => $v) {
                    $set->execute([$k, $v]);
                }
                if ((int)$pdo->query('SELECT COUNT(*) FROM content_modules')->fetchColumn() === 0) {
                    $pdo->prepare('INSERT INTO content_modules (title, slug, summary, sort, is_published) VALUES (?,?,?,0,1)')
                        ->execute(['Getting Started', 'getting-started', 'The basics of sports card collecting and flipping.']);
                    $mid = (int)$pdo->lastInsertId();
                    $pdo->prepare('INSERT INTO content_lessons (module_id, title, body, is_free, is_published, sort) VALUES (?,?,?,1,1,0)')
                        ->execute([$mid, 'What is a PSA 10 and why it matters', "PSA 10 means Gem Mint. Graded cards sell at predictable prices, which is what makes deal-hunting possible."]);
                }
                $done = true;
            }
        } catch (\Throwable $e) {
            $error = 'Setup error: ' . $e->getMessage();
        }
    }
}

layout_header('Setup', 'public');
?>
<div class="auth-wrap">
    <h1>🃏 SportCard101 setup</h1>

    <?php if ($alreadySetUp): ?>
        <div class="card">
            <div class="flash flash-info">This site is already set up.</div>
            <p>For security, <strong>delete <code>setup.php</code></strong> from your server now.</p>
            <p><a class="btn btn-primary" href="/superadmin/login.php">Go to admin login →</a></p>
        </div>
    <?php elseif ($done): ?>
        <div class="card">
            <div class="flash flash-success">All set! Your superadmin account is ready.</div>
            <p>⚠️ <strong>Delete <code>setup.php</code></strong> from your server now so no one else can use it.</p>
            <p><a class="btn btn-primary" href="/superadmin/login.php">Log in to admin →</a></p>
        </div>
    <?php else: ?>
        <p class="sub">Create your superadmin account. This imports the database tables and seeds default plans + content.</p>
        <div class="card">
            <?php if ($error): ?><div class="flash flash-error"><?= e($error) ?></div><?php endif; ?>
            <form method="post" class="stack">
                <?= csrf_field() ?>
                <label for="username">Admin username</label>
                <input id="username" name="username" autofocus required>
                <label for="email">Admin email</label>
                <input id="email" name="email" type="email" required>
                <label for="password">Password <small>(8+ characters)</small></label>
                <input id="password" name="password" type="password" required>
                <button class="btn btn-primary" style="width:100%;margin-top:18px" type="submit">Create superadmin</button>
            </form>
        </div>
        <p class="sub" style="text-align:center;margin-top:12px">Admin login lives at <code>/superadmin/login.php</code></p>
    <?php endif; ?>
</div>
<?php
layout_footer();
