<?php
declare(strict_types=1);

namespace FveMonitor\Lib;

use PDO;
use PDOException;
use RuntimeException;

/**
 * PDO singleton.
 * Použití: Database::pdo()->query(...)
 */
class Database
{
    private static ?PDO $instance = null;

    public static function pdo(): PDO
    {
        if (self::$instance === null) {
            $config = require __DIR__ . '/../config/config.php';
            $db = $config['db'];

            // DSN — podpora TCP i Unix socketu
            // Pokud je v configu 'unix_socket', použije se socket (rychlejší, žádný TCP overhead).
            // Jinak fallback na host:port.
            if (!empty($db['unix_socket'])) {
                $dsn = sprintf(
                    'mysql:unix_socket=%s;dbname=%s;charset=%s',
                    $db['unix_socket'], $db['name'], $db['charset']
                );
            } else {
                $dsn = sprintf(
                    'mysql:host=%s;port=%d;dbname=%s;charset=%s',
                    $db['host'], $db['port'], $db['name'], $db['charset']
                );
            }

            try {
                self::$instance = new PDO($dsn, $db['user'], $db['pass'], [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES   => false,
                    PDO::MYSQL_ATTR_INIT_COMMAND => "SET time_zone = '+00:00'",
                ]);
            } catch (PDOException $e) {
                throw new RuntimeException('DB connection failed: ' . $e->getMessage());
            }
        }
        return self::$instance;
    }

    /** Krátký helper pro fetchAll. */
    public static function all(string $sql, array $params = []): array
    {
        $stmt = self::pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /** Krátký helper pro fetch jednoho řádku. */
    public static function one(string $sql, array $params = []): ?array
    {
        $stmt = self::pdo()->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }
}
