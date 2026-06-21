<?php
declare(strict_types=1);

namespace Sportscard101;

use PDO;

/**
 * Session-based single/multi user authentication.
 */
final class Auth
{
    public static function attempt(PDO $pdo, string $username, string $password): bool
    {
        $stmt = $pdo->prepare('SELECT id, password_hash FROM users WHERE username = ? LIMIT 1');
        $stmt->execute([$username]);
        $user = $stmt->fetch();

        if (!$user || !password_verify($password, $user['password_hash'])) {
            return false;
        }

        // Prevent session fixation.
        session_regenerate_id(true);
        $_SESSION['uid']      = (int)$user['id'];
        $_SESSION['username'] = $username;
        return true;
    }

    public static function check(): bool
    {
        return !empty($_SESSION['uid']);
    }

    public static function userId(): int
    {
        return (int)($_SESSION['uid'] ?? 0);
    }

    public static function username(): string
    {
        return (string)($_SESSION['username'] ?? '');
    }

    /** Guard a page: redirect to login if not authenticated. */
    public static function require(): void
    {
        if (!self::check()) {
            redirect('login.php');
        }
    }

    public static function logout(): void
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
        }
        session_destroy();
    }

    /** Create the user if it does not exist. Returns the user id. */
    public static function ensureUser(PDO $pdo, string $username, string $password, ?string $email = null): int
    {
        $stmt = $pdo->prepare('SELECT id FROM users WHERE username = ? LIMIT 1');
        $stmt->execute([$username]);
        $id = $stmt->fetchColumn();
        if ($id) {
            return (int)$id;
        }
        $stmt = $pdo->prepare('INSERT INTO users (username, password_hash, email) VALUES (?, ?, ?)');
        $stmt->execute([$username, password_hash($password, PASSWORD_DEFAULT), $email]);
        return (int)$pdo->lastInsertId();
    }
}
