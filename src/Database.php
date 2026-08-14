<?php
declare(strict_types=1);

namespace SportCard101;

use PDO;
use PDOException;

/**
 * Thin PDO wrapper. Holds a single shared connection for the request.
 */
final class Database
{
    private static ?PDO $pdo = null;
    /** Kept so a dropped connection can be re-established mid-run. */
    private static array $cfg = [];

    /**
     * How long MySQL should hold this connection open while idle. The scanner
     * makes eBay and Claude calls that can each take tens of seconds between
     * queries; the server's default wait_timeout can be shorter than that,
     * which surfaces later as "MySQL server has gone away".
     */
    private const SESSION_WAIT_TIMEOUT = 900; // 15 minutes

    public static function connect(array $cfg): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }
        self::$cfg = $cfg;

        try {
            self::$pdo = self::open($cfg);
        } catch (PDOException $e) {
            http_response_code(500);
            exit(
                "Database connection failed: " . $e->getMessage() . "\n\n" .
                "Check your config.php database settings and make sure MySQL is running\n" .
                "and the schema has been imported (mysql -u USER DBNAME < schema.sql).\n"
            );
        }

        return self::$pdo;
    }

    /** Open a connection and stretch its idle timeout. */
    private static function open(array $cfg): PDO
    {
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $cfg['host'],
            $cfg['port'],
            $cfg['name'],
            $cfg['charset']
        );

        $pdo = new PDO($dsn, $cfg['user'], $cfg['password'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);

        // Session-scoped, so it needs no special privileges. Some managed hosts
        // refuse it — harmless, alive() still recovers dropped connections.
        try {
            $pdo->exec('SET SESSION wait_timeout = ' . self::SESSION_WAIT_TIMEOUT
                . ', interactive_timeout = ' . self::SESSION_WAIT_TIMEOUT);
        } catch (\Throwable $e) {
        }

        return $pdo;
    }

    /** Force a brand-new connection, replacing the shared one. */
    public static function reconnect(): PDO
    {
        if (!self::$cfg) {
            throw new \RuntimeException('Cannot reconnect: no stored database config.');
        }
        self::$pdo = self::open(self::$cfg);
        return self::$pdo;
    }

    /**
     * Return a connection known to be usable. Cheap round-trip; if the server
     * has dropped the link (error 2006 "gone away" after a long API call), it
     * transparently reconnects.
     *
     * Callers must use the RETURNED handle — a reconnect yields a new object.
     */
    public static function alive(?PDO $pdo = null): PDO
    {
        $pdo = $pdo ?? self::pdo();
        try {
            $pdo->query('SELECT 1');
            return $pdo;
        } catch (\Throwable $e) {
            Diag::log('DB connection lost — reconnecting (' . mb_substr($e->getMessage(), 0, 120) . ')');
            return self::reconnect();
        }
    }

    /** True when an exception is a dropped-connection error worth retrying. */
    public static function isGoneAway(\Throwable $e): bool
    {
        $m = $e->getMessage();
        return str_contains($m, 'server has gone away')
            || str_contains($m, 'Lost connection')
            || str_contains($m, '2006')
            || str_contains($m, '2013');
    }

    public static function pdo(): PDO
    {
        if (!self::$pdo instanceof PDO) {
            throw new \RuntimeException('Database not connected. Call Database::connect() first.');
        }
        return self::$pdo;
    }
}
