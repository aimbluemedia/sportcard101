<?php
declare(strict_types=1);

/**
 * App bootstrap: load config, register a tiny autoloader, start the session,
 * connect to the database, and expose shared helpers.
 *
 * Every entry point (public/*.php, bin/*.php) requires this file first.
 */

define('VIPSVAULT_ROOT', dirname(__DIR__));

// --- Config ---
// config.php lives in the admin/ folder. (A project-root config.php is also
// accepted as a fallback for older layouts.)
$configCandidates = [
    VIPSVAULT_ROOT . '/admin/config.php',
    VIPSVAULT_ROOT . '/config.php',
];
$configFile = null;
foreach ($configCandidates as $candidate) {
    if (is_file($candidate)) {
        $configFile = $candidate;
        break;
    }
}
if ($configFile === null) {
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, "Missing admin/config.php. Run: cp admin/config.sample.php admin/config.php\n");
        exit(1);
    }
    http_response_code(500);
    exit('Missing admin/config.php — copy admin/config.sample.php to admin/config.php and set your values.');
}
$config = require $configFile;

date_default_timezone_set($config['app']['timezone'] ?? 'UTC');

// --- Autoloader for Vipsvault\* classes ---
spl_autoload_register(function (string $class): void {
    $prefix = 'Vipsvault\\';
    if (str_starts_with($class, $prefix)) {
        $file = __DIR__ . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($file)) {
            require $file;
        }
    }
});

require __DIR__ . '/helpers.php';

// --- Database ---
$pdo = \Vipsvault\Database::connect($config['db']);

// --- Session (web only) ---
if (PHP_SAPI !== 'cli' && session_status() !== PHP_SESSION_ACTIVE) {
    session_set_cookie_params([
        'httponly' => true,
        'samesite' => 'Lax',
        'secure'   => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
    ]);
    session_name('vipsvault');
    session_start();
}
