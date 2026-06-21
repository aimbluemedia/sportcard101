<?php
declare(strict_types=1);

/**
 * One-time setup:
 *   - imports schema.sql
 *   - creates the SUPERADMIN account
 *   - seeds default plans, settings, and a sample lesson module
 *
 * Usage:
 *   php bin/install.php <admin_username> <admin_password> [admin_email]
 */

require __DIR__ . '/../src/bootstrap.php';

use SportCard101\Auth;

if ($argc < 3) {
    fwrite(STDERR, "Usage: php bin/install.php <admin_username> <admin_password> [admin_email]\n");
    exit(1);
}
[$_, $username, $password] = $argv;
$email = $argv[3] ?? null;

$schema = file_get_contents(APP_ROOT . '/schema.sql');
if ($schema === false) {
    fwrite(STDERR, "Could not read schema.sql\n");
    exit(1);
}
$pdo->exec($schema);
echo "Schema imported.\n";

$adminId = Auth::ensureUser($pdo, $username, $password, $email, 'superadmin');
echo "Superadmin '{$username}' ready (id {$adminId}). Log in at /superadmin/login.php\n";

// Seed plans (only if none exist).
if ((int)$pdo->query('SELECT COUNT(*) FROM plans')->fetchColumn() === 0) {
    $plan = $pdo->prepare(
        'INSERT INTO plans (name, slug, price_cents, bill_interval, blurb, features, is_active, sort) VALUES (?,?,?,?,?,?,1,?)'
    );
    $plan->execute(['Free', 'free', 0, 'month', 'Start learning the hobby.',
        "Top 3 daily AI deals\nFree starter lessons\nMember community", 0]);
    $plan->execute(['Pro', 'pro', 1900, 'month', 'The full deal engine + all lessons.',
        "Full AI deal board\nFlip-margin math\nHidden-gem alerts\nAll lessons & courses\nAffiliate program", 1]);
    echo "Seeded Free + Pro plans.\n";
}

// Seed settings.
$set = $pdo->prepare('INSERT IGNORE INTO settings (skey, sval) VALUES (?, ?)');
foreach ([
    'site_url'   => 'https://sportcard101.com',
    'hero_title' => 'Learn to find and flip sports cards — with AI.',
    'hero_subtitle' => 'SportCard101 teaches you the hobby and hands you an AI engine that spots underpriced PSA 10 deals on eBay before everyone else.',
    'skool_url'  => '',
    'enable_member_searches' => '0',
] as $k => $v) {
    $set->execute([$k, $v]);
}
echo "Seeded settings.\n";

// Seed a sample module + free lesson so the school isn't empty.
if ((int)$pdo->query('SELECT COUNT(*) FROM content_modules')->fetchColumn() === 0) {
    $pdo->prepare('INSERT INTO content_modules (title, slug, summary, sort, is_published) VALUES (?,?,?,0,1)')
        ->execute(['Getting Started', 'getting-started', 'The absolute basics of sports card collecting and flipping.']);
    $mid = (int)$pdo->lastInsertId();
    $pdo->prepare('INSERT INTO content_lessons (module_id, title, body, is_free, is_published, sort) VALUES (?,?,?,1,1,0)')
        ->execute([$mid, 'What is a PSA 10 and why it matters',
            "PSA 10 means a card graded Gem Mint by PSA. Graded cards sell for predictable prices, which is exactly what makes deal-hunting possible. In this lesson we cover grading basics and how to read a comp."]);
    echo "Seeded a sample lesson.\n";
}

echo "Done. Visit / (homepage), /signup.php (members), /superadmin/login.php (admin).\n";
