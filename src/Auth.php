<?php
declare(strict_types=1);

namespace SportCard101;

use PDO;

/**
 * Role-based authentication.
 *
 * One users table, two roles:
 *   - superadmin → manages everything at /superadmin (separate login)
 *   - member     → subscription user at /member
 *
 * Premium member features are gated by subscription status (isPro()), not just
 * by being logged in.
 */
final class Auth
{
    /** Authenticate by username OR email. Loads role + subscription into session. */
    public static function attempt(PDO $pdo, string $login, string $password): bool
    {
        $stmt = $pdo->prepare(
            'SELECT id, username, email, password_hash, role, status, sub_status
             FROM users WHERE username = ? OR email = ? LIMIT 1'
        );
        $stmt->execute([$login, $login]);
        $u = $stmt->fetch();

        if (!$u || $u['status'] !== 'active' || !password_verify($password, $u['password_hash'])) {
            return false;
        }

        session_regenerate_id(true);
        $_SESSION['uid']        = (int)$u['id'];
        $_SESSION['username']   = $u['username'];
        $_SESSION['email']      = $u['email'];
        $_SESSION['role']       = $u['role'];
        $_SESSION['sub_status'] = $u['sub_status'];
        return true;
    }

    public static function check(): bool      { return !empty($_SESSION['uid']); }
    public static function userId(): int      { return (int)($_SESSION['uid'] ?? 0); }
    public static function username(): string { return (string)($_SESSION['username'] ?? ''); }
    public static function email(): string    { return (string)($_SESSION['email'] ?? ''); }
    public static function role(): string     { return (string)($_SESSION['role'] ?? ''); }
    public static function subStatus(): string { return (string)($_SESSION['sub_status'] ?? 'none'); }

    public static function isAdmin(): bool { return self::role() === 'superadmin'; }

    /** Paid access: admins always; members with a live subscription. */
    public static function isPro(): bool
    {
        return self::isAdmin() || in_array(self::subStatus(), ['trialing', 'active'], true);
    }

    /** Refresh subscription status from DB into the session (after billing changes). */
    public static function refresh(PDO $pdo): void
    {
        if (!self::check()) {
            return;
        }
        $stmt = $pdo->prepare('SELECT role, sub_status FROM users WHERE id = ?');
        $stmt->execute([self::userId()]);
        if ($row = $stmt->fetch()) {
            $_SESSION['role']       = $row['role'];
            $_SESSION['sub_status'] = $row['sub_status'];
        }
    }

    /** Guard the member area — any logged-in user (admins may preview). */
    public static function requireMember(): void
    {
        if (!self::check()) {
            redirect('/login.php?next=' . rawurlencode($_SERVER['REQUEST_URI'] ?? '/member/'));
        }
    }

    /** Guard the superadmin area — superadmins only, separate login. */
    public static function requireAdmin(): void
    {
        if (!self::isAdmin()) {
            redirect('/superadmin/login.php');
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

    /** A short, unique-ish affiliate code. */
    public static function newAffiliateCode(): string
    {
        return strtoupper(substr(bin2hex(random_bytes(4)), 0, 7));
    }

    /**
     * Create a user if the username/email is free. Returns [id, error].
     * @return array{0:?int,1:?string}
     */
    public static function create(
        PDO $pdo,
        string $username,
        string $email,
        string $password,
        string $role = 'member',
        ?string $referredBy = null
    ): array {
        $stmt = $pdo->prepare('SELECT 1 FROM users WHERE username = ? OR email = ? LIMIT 1');
        $stmt->execute([$username, $email]);
        if ($stmt->fetchColumn()) {
            return [null, 'That username or email is already taken.'];
        }

        $stmt = $pdo->prepare(
            'INSERT INTO users (username, email, password_hash, role, affiliate_code, referred_by)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $username,
            $email,
            password_hash($password, PASSWORD_DEFAULT),
            $role,
            self::newAffiliateCode(),
            $referredBy,
        ]);
        return [(int)$pdo->lastInsertId(), null];
    }

    /** Idempotent helper used by the installer to seed the superadmin. */
    public static function ensureUser(PDO $pdo, string $username, string $password, ?string $email, string $role = 'member'): int
    {
        $stmt = $pdo->prepare('SELECT id FROM users WHERE username = ? LIMIT 1');
        $stmt->execute([$username]);
        if ($id = $stmt->fetchColumn()) {
            return (int)$id;
        }
        [$id] = self::create($pdo, $username, $email ?: ($username . '@example.com'), $password, $role);
        return (int)$id;
    }
}
