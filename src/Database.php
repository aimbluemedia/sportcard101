<?php
declare(strict_types=1);

namespace Sportscard101;

use PDO;
use PDOException;

/**
 * Thin PDO wrapper. Holds a single shared connection for the request.
 */
final class Database
{
    private static ?PDO $pdo = null;

    public static function connect(array $cfg): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }

        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $cfg['host'],
            $cfg['port'],
            $cfg['name'],
            $cfg['charset']
        );

        try {
            self::$pdo = new PDO($dsn, $cfg['user'], $cfg['password'], [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
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

    public static function pdo(): PDO
    {
        if (!self::$pdo instanceof PDO) {
            throw new \RuntimeException('Database not connected. Call Database::connect() first.');
        }
        return self::$pdo;
    }
}
