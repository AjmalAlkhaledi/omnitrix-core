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
use function preg_match;
use function sprintf;
use function str_contains;
use function str_replace;
use function strtoupper;

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

    /** True when `$error` (or a previous exception) says the database does not exist (MySQL 1049, PostgreSQL 3D000). */
    public static function isUnknownDatabase(Throwable $error): bool
    {
        for ($e = $error; $e !== null; $e = $e->getPrevious()) {
            if (str_contains($e->getMessage(), 'SQLSTATE[3D000]')) {
                return true; // PostgreSQL coroutine client: "database … does not exist"
            }
            if (!$e instanceof PDOException) {
                continue;
            }
            if (($e->errorInfo[1] ?? null) === self::UNKNOWN_DATABASE || str_contains($e->getMessage(), '[' . self::UNKNOWN_DATABASE . ']')
                || ($e->errorInfo[0] ?? null) === '3D000'
                || (str_contains($e->getMessage(), 'SQLSTATE[08006]') && preg_match('/database "[^"]+" does not exist/', $e->getMessage()) === 1)) {
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
        if ($c->driver === 'pgsql') {
            // Connect to the maintenance database; CREATE DATABASE has no IF NOT EXISTS here.
            $pdo = new PDO(sprintf('pgsql:host=%s;port=%d;dbname=postgres;connect_timeout=5', $c->unixSocket ?? $c->host, $c->port), $c->username, $c->password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $exists = $pdo->prepare('select 1 from pg_database where datname = ?');
            $exists->execute([$c->database]);
            if ($exists->fetchColumn() === false) {
                $pdo->exec('CREATE DATABASE "' . str_replace('"', '""', $c->database) . '" ENCODING ' . $pdo->quote(strtoupper($c->charset)));
            }

            return;
        }
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
