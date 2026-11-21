<?php

declare(strict_types=1);

namespace Omnitrix\Core\Database\Driver\PostgreSQL;

use Omnitrix\Core\Database\Config\DatabaseConfig;
use Omnitrix\Core\Database\Contracts\ConnectionInterface;
use Omnitrix\Core\Database\Contracts\DriverInterface;
use Omnitrix\Core\Database\Contracts\GrammarInterface;
use Omnitrix\Core\Database\Exception\ConnectionException;
use Omnitrix\Core\Support\Id;
use OpenSwoole\Coroutine\PostgreSQL;

use function class_exists;
use function implode;
use function is_string;
use function max;
use function preg_match;
use function round;
use function str_replace;
use function trim;

/**
 * PostgreSQL driver on OpenSwoole's coroutine client (OpenSwoole built `--with-postgres`):
 * queries suspend only the calling coroutine. pdo_pgsql is not used because OpenSwoole has no
 * runtime hook for it — every query would block the whole worker.
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
        if (!class_exists(PostgreSQL::class)) {
            throw new ConnectionException('The pgsql driver needs OpenSwoole built with PostgreSQL support (./configure --with-postgres; requires libpq)');
        }
        // A unix socket is the directory that holds it, passed as the host.
        $options = '-c search_path=' . self::option($config->schema) . ' -c client_encoding=' . self::option($config->charset);
        if ($config->timezone !== null) {
            $options .= ' -c TimeZone=' . self::option($config->timezone);
        }
        $conninfo = implode(' ', [
            'host=' . self::value($config->unixSocket ?? $config->host),
            'port=' . $config->port,
            'dbname=' . self::value($config->database),
            'user=' . self::value($config->username),
            'password=' . self::value($config->password),
            'connect_timeout=' . max(1, (int) round($config->connectTimeout)),
            'options=' . self::value($options),
            'application_name=omnitrix',
        ]);
        $client = new PostgreSQL();
        if (!$client->connect($conninfo, $config->connectTimeout)) {
            $error = is_string($client->error) && $client->error !== '' ? trim($client->error) : 'connection failed';
            $missing = preg_match('/database "[^"]+" does not exist/', $error) === 1;
            throw new ConnectionException(($missing ? 'SQLSTATE[3D000] ' : '') . "Could not connect to PostgreSQL at {$config->host}:{$config->port}: {$error}");
        }
        $connection = new PgSqlConnection($client, $config->name . ':' . Id::generate());
        if ($config->statementTimeout > 0.0) {
            $connection->setStatementTimeout($config->statementTimeout);
        }

        return $connection;
    }

    /** A libpq conninfo value: quoted, with quotes and backslashes escaped. */
    private static function value(string $value): string
    {
        return "'" . str_replace(['\\', "'"], ['\\\\', "\\'"], $value) . "'";
    }

    /** A value inside `options` (`-c name=value`): spaces and backslashes escaped. */
    private static function option(string $value): string
    {
        return str_replace(['\\', ' '], ['\\\\', '\\ '], $value);
    }
}
