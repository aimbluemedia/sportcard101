<?php
declare(strict_types=1);

/**
 * One-time setup helper:
 *   - imports schema.sql into the configured database
 *   - creates an admin user
 *
 * Usage:
 *   php bin/install.php <username> <password> [email]
 */

require __DIR__ . '/../src/bootstrap.php';

use Vipsvault\Auth;

if ($argc < 3) {
    fwrite(STDERR, "Usage: php bin/install.php <username> <password> [email]\n");
    exit(1);
}

[$_, $username, $password] = $argv;
$email = $argv[3] ?? null;

// Import schema (idempotent — uses CREATE TABLE IF NOT EXISTS).
$schema = file_get_contents(VIPSVAULT_ROOT . '/schema.sql');
if ($schema === false) {
    fwrite(STDERR, "Could not read schema.sql\n");
    exit(1);
}
$pdo->exec($schema);
echo "Schema imported.\n";

$id = Auth::ensureUser($pdo, $username, $password, $email);
echo "Admin user '{$username}' ready (id {$id}).\n";
echo "You can now log in at /login.php\n";
