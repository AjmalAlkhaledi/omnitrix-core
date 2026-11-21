<?php

declare(strict_types=1);

namespace Omnitrix\Core\Database\Driver\PostgreSQL;

use Omnitrix\Core\Database\Config\DatabaseConfig;
use Omnitrix\Core\Database\Connection\PdoConnection;
use Omnitrix\Core\Database\Contracts\ConnectionInterface;
use Omnitrix\Core\Database\Contracts\DriverInterface;
use Omnitrix\Core\Database\Contracts\GrammarInterface;
use Omnitrix\Core\Database\Exception\ConnectionException;
use Omnitrix\Core\Database\Exception\ErrorClassifier;
use Omnitrix\Core\Support\Id;
use PDO;
use PDOException;

use function max;
use function round;
use function sprintf;
use function str_replace;

/**
 * PostgreSQL driver on pdo_pgsql.
 *
 * Every connection works in the configured schema (`search_path`) and time zone; statement
 * timeouts use the session's `statement_timeout` (milliseconds, every statement), which the
 * server enforces and reports as SQLSTATE 57014.
 */
final class PgSqlDriver implements DriverInterface
{
    private readonly PgSqlGrammar $grammar;

    public function __construct()
    {
        $this->grammar = new PgSqlGrammar();
    }

    public function name(): string
    {
        return 'pgsql';
    }

    public function grammar(): GrammarInterface
    {
        return $this->grammar;
    }

    public function connect(DatabaseConfig $config): ConnectionInterface
    {
        // A unix socket is the directory that holds it, passed as the host.
        $host = $config->unixSocket ?? $config->host;
        $dsn = sprintf("pgsql:host=%s;port=%d;dbname=%s;connect_timeout=%d;options='--client_encoding=%s'", $host, $config->port, $config->database, max(1, (int) round($config->connectTimeout)), $config->charset);
        $options = $config->options + [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_STRINGIFY_FETCHES => false,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ];

        try {
            $pdo = new PDO($dsn, $config->username, $config->password, $options);
            $pdo->exec('SET search_path TO ' . self::identifier($config->schema));
            if ($config->timezone !== null) {
                $pdo->exec("SET TIME ZONE '" . str_replace("'", "''", $config->timezone) . "'");
            }
        } catch (PDOException $e) {
            $classified = ErrorClassifier::classify($e);
            throw $classified instanceof ConnectionException ? $classified : new ConnectionException($classified->getMessage(), $classified->getCode(), $e);
        }

        $apply = static function (PDO $pdo, float $seconds): void {
            $pdo->exec('SET statement_timeout = ' . (int) round(max(0.0, $seconds) * 1000));
        };
        $connection = new PdoConnection($pdo, $config->name . ':' . Id::generate(), $apply);
        if ($config->statementTimeout > 0.0) {
            $connection->setStatementTimeout($config->statementTimeout);
        }

        return $connection;
    }

    private static function identifier(string $name): string
    {
        return '"' . str_replace('"', '""', $name) . '"';
    }
}
