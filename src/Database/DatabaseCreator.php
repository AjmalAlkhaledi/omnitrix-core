<?php

declare(strict_types=1);

namespace Omnitrix\Database;

use Omnitrix\Core\Database\Config\DatabaseConfig;
use Omnitrix\Core\Runtime\Config\InvalidConfigurationException;
use PDO;
use PDOException;
use Throwable;

use function array_key_first;
use function is_array;
use function sprintf;
use function str_contains;
use function str_replace;

/**
 * Creates the database named in the `database` configuration when it does not exist yet. Used by
 * `php omnitrix migrate` after asking the developer.
 */
final class DatabaseCreator
{
    private const int UNKNOWN_DATABASE = 1049;

    private readonly DatabaseConfig $config;

    /**
     * @param array<string, mixed> $section the `database` configuration (one connection or named connections)
     */
    public function __construct(array $section, ?string $connection = null)
    {
        $this->config = self::resolve($section, $connection);
    }

    /** True when `$error` (or one of its previous exceptions) is MySQL's "Unknown database". */
    public static function isUnknownDatabase(Throwable $error): bool
    {
        for ($e = $error; $e !== null; $e = $e->getPrevious()) {
            if ($e instanceof PDOException && (($e->errorInfo[1] ?? null) === self::UNKNOWN_DATABASE || str_contains($e->getMessage(), '[' . self::UNKNOWN_DATABASE . ']'))) {
                return true;
            }
        }

        return false;
    }

    public function name(): string
    {
        return $this->config->database;
    }

    /** Creates the database (if it does not exist) with the configured charset and collation. */
    public function create(): void
    {
        $c = $this->config;
        $dsn = $c->unixSocket !== null
            ? sprintf('mysql:unix_socket=%s;charset=%s', $c->unixSocket, $c->charset)
            : sprintf('mysql:host=%s;port=%d;charset=%s', $c->host, $c->port, $c->charset);
        $pdo = new PDO($dsn, $c->username, $c->password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5]);
        $sql = 'CREATE DATABASE IF NOT EXISTS `' . str_replace('`', '``', $c->database) . '` CHARACTER SET ' . $c->charset
            . ' COLLATE ' . ($c->collation ?? $c->charset . '_unicode_ci');
        $pdo->exec($sql);
    }

    /** @param array<string, mixed> $section */
    private static function resolve(array $section, ?string $connection): DatabaseConfig
    {
        $named = !isset($section['driver']) && !isset($section['database']) && $section !== [] && is_array($section[array_key_first($section) ?? ''] ?? null);
        if (!$named) {
            return DatabaseConfig::fromArray($section, 'default');
        }
        $name = $connection ?? (isset($section['default']) ? 'default' : (string) array_key_first($section));
        $config = $section[$name] ?? null;
        if (!is_array($config)) {
            throw new InvalidConfigurationException("Database connection '" . ($connection ?? 'default') . "' is not configured");
        }

        /** @var array<string, mixed> $config */
        return DatabaseConfig::fromArray($config, $name);
    }
}
