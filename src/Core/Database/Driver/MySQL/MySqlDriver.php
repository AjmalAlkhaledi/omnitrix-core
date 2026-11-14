<?php

declare(strict_types=1);

namespace Omnitrix\Core\Database\Driver\MySQL;

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

use function is_string;
use function max;
use function round;
use function sprintf;
use function stripos;

/**
 * MySQL / MariaDB driver on pdo_mysql (mysqlnd).
 *
 * Statement timeouts: MariaDB `max_statement_time` (seconds, all statements) or MySQL
 * `max_execution_time` (milliseconds, SELECT only) — both applied as session variables, set
 * lazily per connection only when the requested value changes. Server-side limits are the
 * reliable mechanism: `PDO::ATTR_TIMEOUT` bounds connects but not reads under runtime hooks.
 */
final class MySqlDriver implements DriverInterface
{
    private readonly MySqlGrammar $grammar;

    public function __construct()
    {
        $this->grammar = new MySqlGrammar();
    }

    public function name(): string
    {
        return 'mysql';
    }

    public function grammar(): GrammarInterface
    {
        return $this->grammar;
    }

    public function connect(DatabaseConfig $config): ConnectionInterface
    {
        $dsn = $config->unixSocket !== null
            ? sprintf('mysql:unix_socket=%s;dbname=%s;charset=%s', $config->unixSocket, $config->database, $config->charset)
            : sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $config->host, $config->port, $config->database, $config->charset);

        $options = $config->options + [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_STRINGIFY_FETCHES => false,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_TIMEOUT => max(1, (int) round($config->connectTimeout)),
        ];

        try {
            $pdo = new PDO($dsn, $config->username, $config->password, $options);
            // Session setup runs as plain statements: PDO::MYSQL_ATTR_INIT_COMMAND is deprecated on
            // PHP 8.5 and the extra round trip happens once per pooled connection only.
            foreach ($this->sessionStatements($config) as $statement) {
                $pdo->exec($statement);
            }
            $version = $pdo->getAttribute(PDO::ATTR_SERVER_VERSION);
        } catch (PDOException $e) {
            $classified = ErrorClassifier::classify($e);
            throw $classified instanceof ConnectionException ? $classified : new ConnectionException($classified->getMessage(), $classified->getCode(), $e);
        }

        $isMariaDb = is_string($version) && stripos($version, 'mariadb') !== false;
        $apply = $isMariaDb
            ? static function (PDO $pdo, float $seconds): void {
                $pdo->exec('SET SESSION max_statement_time = ' . sprintf('%.3F', max(0.0, $seconds)));
            }
        : static function (PDO $pdo, float $seconds): void {
            $pdo->exec('SET SESSION max_execution_time = ' . (int) round(max(0.0, $seconds) * 1000));
        };

        $connection = new PdoConnection($pdo, $config->name . ':' . Id::generate(), $apply);
        if ($config->statementTimeout > 0.0) {
            $connection->setStatementTimeout($config->statementTimeout);
        }

        return $connection;
    }

    /** @return list<string> */
    private function sessionStatements(DatabaseConfig $config): array
    {
        $statements = [];
        if ($config->strict) {
            $statements[] = "SET SESSION sql_mode = 'STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'";
        }
        if ($config->collation !== null) {
            $statements[] = sprintf("SET NAMES '%s' COLLATE '%s'", $config->charset, $config->collation);
        }
        if ($config->timezone !== null) {
            $statements[] = sprintf("SET time_zone = '%s'", $config->timezone);
        }

        return $statements;
    }
}
