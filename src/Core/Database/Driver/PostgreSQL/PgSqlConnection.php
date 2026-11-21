<?php

declare(strict_types=1);

namespace Omnitrix\Core\Database\Driver\PostgreSQL;

use BackedEnum;
use InvalidArgumentException;
use Omnitrix\Core\Database\Contracts\ConnectionInterface;
use Omnitrix\Core\Database\Exception\ConnectionException;
use Omnitrix\Core\Database\Exception\DatabaseException;
use Omnitrix\Core\Database\Exception\ErrorClassifier;
use Omnitrix\Core\Database\Exception\TransactionException;
use OpenSwoole\Coroutine;
use OpenSwoole\Coroutine\PostgreSQL;
use OpenSwoole\Coroutine\PostgreSQLStatement;
use Stringable;

use function array_key_first;
use function count;
use function get_debug_type;
use function is_array;
use function is_bool;
use function is_float;
use function is_int;
use function is_string;
use function max;
use function microtime;
use function preg_match;
use function round;
use function sprintf;
use function strlen;
use function strpos;
use function substr;

use const OPENSWOOLE_PGSQL_ASSOC;

/**
 * A PostgreSQL connection on OpenSwoole's coroutine client: queries suspend only the calling
 * coroutine (pdo_pgsql would block the whole worker, it has no runtime hook).
 *
 * Statements are prepared once per connection (LRU cache; the client deallocates evicted ones).
 * `?` placeholders are rewritten to `$1, $2, …` outside string literals, quoted identifiers,
 * dollar-quoted bodies and comments; jsonb's `?` operators therefore need their function forms
 * (`jsonb_exists()`, …) in raw SQL. The client returns booleans, integers, floats and bytea as
 * PHP values; numeric and other types as strings.
 */
final class PgSqlConnection implements ConnectionInterface
{
    private bool $broken = false;

    private readonly float $createdAt;

    private float $lastUsedAt;

    private float $statementTimeout = -1.0;

    private int $transactionDepth = 0;

    /** @var array<string, PostgreSQLStatement> by original SQL, most recently used last */
    private array $statements = [];

    public function __construct(private ?PostgreSQL $client, private readonly string $id, private readonly int $statementCacheSize = 64)
    {
        $this->createdAt = microtime(true);
        $this->lastUsedAt = $this->createdAt;
    }

    public function id(): string
    {
        return $this->id;
    }

    public function select(string $sql, array $bindings = []): array
    {
        $statement = $this->run($sql, $bindings);
        $rows = $statement->fetchAll(OPENSWOOLE_PGSQL_ASSOC);
        if (!is_array($rows)) {
            return []; // statements without a result set (or no rows)
        }
        $list = [];
        foreach ($rows as $row) {
            if (is_array($row)) {
                /** @var array<string, mixed> $row */
                $list[] = $row;
            }
        }

        return $list;
    }

    public function execute(string $sql, array $bindings = []): int
    {
        $affected = $this->run($sql, $bindings)->affectedRows();

        return is_int($affected) ? $affected : 0;
    }

    /** The key comes from `RETURNING` (the builder adds it); without it there is none (0). */
    public function insert(string $sql, array $bindings = []): int
    {
        $statement = $this->run($sql, $bindings);
        $fields = $statement->fieldCount();
        if (!is_int($fields) || $fields < 1) {
            return 0;
        }
        $row = $statement->fetchRow(0, OPENSWOOLE_PGSQL_ASSOC);
        $value = is_array($row) ? ($row[array_key_first($row)] ?? null) : null;

        return is_int($value) ? $value : (is_string($value) && $value !== '' ? (int) $value : 0);
    }

    public function beginTransaction(): void
    {
        $this->command('BEGIN');
        $this->transactionDepth = 1;
    }

    public function commit(): void
    {
        if ($this->transactionDepth === 0) {
            throw new TransactionException('commit() without an active transaction');
        }
        $this->command('COMMIT');
        $this->transactionDepth = 0;
    }

    public function rollBack(): void
    {
        if ($this->transactionDepth === 0) {
            throw new TransactionException('rollBack() without an active transaction');
        }
        $this->transactionDepth = 0;
        $this->command('ROLLBACK');
    }

    public function inTransaction(): bool
    {
        return $this->transactionDepth > 0;
    }

    public function setStatementTimeout(float $seconds): void
    {
        if ($seconds === $this->statementTimeout) {
            return;
        }
        $this->command('SET statement_timeout = ' . (int) round(max(0.0, $seconds) * 1000));
        $this->statementTimeout = $seconds;
    }

    public function ping(): bool
    {
        if ($this->broken || $this->client === null) {
            return false;
        }
        $result = $this->client->query('SELECT 1');
        if ($result === false) {
            $this->broken = true;

            return false;
        }

        return true;
    }

    public function isBroken(): bool
    {
        return $this->broken;
    }

    public function markBroken(): void
    {
        $this->broken = true;
    }

    public function createdAt(): float
    {
        return $this->createdAt;
    }

    public function lastUsedAt(): float
    {
        return $this->lastUsedAt;
    }

    public function touch(): void
    {
        $this->lastUsedAt = microtime(true);
    }

    public function close(): void
    {
        $this->broken = true;
        $this->statements = [];
        $this->client = null; // the client closes the socket when released
    }

    /**
     * Rewrites `?` placeholders to `$n`, leaving literals, quoted identifiers, dollar-quoted
     * strings and comments alone.
     */
    public static function numberPlaceholders(string $sql): string
    {
        $out = '';
        $n = 0;
        $length = strlen($sql);
        for ($i = 0; $i < $length; $i++) {
            $char = $sql[$i];
            if ($char === "'" || $char === '"') {
                $end = $i + 1;
                while ($end < $length) {
                    if ($sql[$end] === $char) {
                        if ($end + 1 < $length && $sql[$end + 1] === $char) {
                            $end += 2; // doubled quote inside the literal
                            continue;
                        }
                        break;
                    }
                    $end++;
                }
                $out .= substr($sql, $i, $end - $i + 1);
                $i = $end;
            } elseif ($char === '-' && ($sql[$i + 1] ?? '') === '-') {
                $end = strpos($sql, "\n", $i);
                $end = $end === false ? $length - 1 : $end;
                $out .= substr($sql, $i, $end - $i + 1);
                $i = $end;
            } elseif ($char === '/' && ($sql[$i + 1] ?? '') === '*') {
                $end = strpos($sql, '*/', $i + 2);
                $end = $end === false ? $length - 1 : $end + 1;
                $out .= substr($sql, $i, $end - $i + 1);
                $i = $end;
            } elseif ($char === '$' && preg_match('/\G\$([A-Za-z_][A-Za-z0-9_]*)?\$/', $sql, $m, 0, $i) === 1) {
                $tag = $m[0];
                $end = strpos($sql, $tag, $i + strlen($tag));
                $end = $end === false ? $length - 1 : $end + strlen($tag) - 1;
                $out .= substr($sql, $i, $end - $i + 1);
                $i = $end;
            } elseif ($char === '?') {
                $out .= '$' . (++$n);
            } else {
                $out .= $char;
            }
        }

        return $out;
    }

    /** @param array<mixed> $bindings */
    private function run(string $sql, array $bindings): PostgreSQLStatement
    {
        $client = $this->client();
        $params = [];
        foreach ($bindings as $key => $value) {
            if (!is_int($key)) {
                throw new InvalidArgumentException('The PostgreSQL driver takes positional (?) bindings only');
            }
            $params[] = self::parameter($value);
        }
        $statement = $this->statements[$sql] ?? null;
        if ($statement !== null) {
            unset($this->statements[$sql]);
        } else {
            $statement = $client->prepare(self::numberPlaceholders($sql));
            if ($statement === false) {
                throw $this->failure($client, $sql, $bindings);
            }
        }
        if (!$statement->execute($params)) {
            $error = $this->failure($statement, $sql, $bindings);
            if (!$this->broken && $this->statementCacheSize > 0) {
                $this->remember($sql, $statement);
            }
            throw $error;
        }
        if ($this->statementCacheSize > 0) {
            $this->remember($sql, $statement);
        }
        $this->lastUsedAt = microtime(true);

        return $statement;
    }

    private function remember(string $sql, PostgreSQLStatement $statement): void
    {
        if (count($this->statements) >= $this->statementCacheSize) {
            unset($this->statements[array_key_first($this->statements)]); // the client deallocates it
        }
        $this->statements[$sql] = $statement;
    }

    private function command(string $sql): void
    {
        $client = $this->client();
        if ($client->query($sql) === false) {
            throw $this->failure($client, $sql, []);
        }
        $this->lastUsedAt = microtime(true);
    }

    /** @param array<mixed> $bindings */
    private function failure(PostgreSQL|PostgreSQLStatement $source, string $sql, array $bindings): DatabaseException
    {
        $diag = $source->resultDiag;
        $sqlState = is_array($diag) && is_string($diag['sqlstate'] ?? null) ? $diag['sqlstate'] : null;
        $message = is_array($diag) && is_string($diag['message_primary'] ?? null) ? 'ERROR:  ' . $diag['message_primary'] : (is_string($source->error) && $source->error !== '' ? $source->error : 'PostgreSQL query failed');
        $client = $this->client;
        $connectionLost = $sqlState === null && ($client === null || $client->status() !== PostgreSQL::PG_CONNECTION_OK);
        $exception = ErrorClassifier::fromParts($connectionLost ? '08006' : $sqlState, is_int($source->errCode) ? $source->errCode : 0, $message, $sql, $bindings);
        if ($exception instanceof ConnectionException || Coroutine::isCanceled()) {
            $this->broken = true;
            $this->statements = [];
            if (!$exception instanceof ConnectionException) {
                $exception = new ConnectionException('Connection discarded after an interrupted operation: ' . $exception->getMessage(), $exception->getCode(), $exception);
            }
        }

        return $exception;
    }

    private function client(): PostgreSQL
    {
        if ($this->client === null || $this->broken) {
            throw new ConnectionException(sprintf('Connection %s is closed', $this->id));
        }

        return $this->client;
    }

    private static function parameter(mixed $value): ?string
    {
        return match (true) {
            $value === null => null,
            is_bool($value) => $value ? 't' : 'f',
            is_int($value) => (string) $value,
            is_float($value) => sprintf('%.17G', $value),
            is_string($value) => $value,
            $value instanceof Stringable => (string) $value,
            $value instanceof BackedEnum => (string) $value->value,
            default => throw new InvalidArgumentException('Unsupported binding type ' . get_debug_type($value)),
        };
    }
}
