<?php

declare(strict_types=1);

namespace Omnitrix\Core\Database\Connection;

use Omnitrix\Core\Database\Contracts\ConnectionInterface;
use Omnitrix\Core\Database\Exception\ConnectionException;
use Omnitrix\Core\Database\Exception\DatabaseException;
use Omnitrix\Core\Database\Exception\ErrorClassifier;
use Omnitrix\Core\Database\Exception\TransactionException;
use OpenSwoole\Coroutine;
use PDO;
use PDOException;
use PDOStatement;
use Throwable;

use function array_key_first;
use function count;
use function is_bool;
use function is_int;
use function is_string;
use function microtime;
use function sprintf;
use function str_starts_with;

/**
 * {@see ConnectionInterface} over PDO + mysqlnd. Under OpenSwoole runtime hooks every network
 * wait suspends only the current coroutine. Native prepared statements
 * are used (`ATTR_EMULATE_PREPARES=false`), so values never touch the SQL text.
 *
 * Any PDOException classified as a connection error, and any failure while the coroutine was
 * being cancelled, marks the connection broken so the pool discards it.
 */
final class PdoConnection implements ConnectionInterface
{
    private bool $broken = false;

    private readonly float $createdAt;

    private float $lastUsedAt;

    private float $statementTimeout = -1.0;

    private int $transactionDepth = 0;

    /** @var array<string, PDOStatement> prepared statements by SQL, most recently used last */
    private array $statements = [];

    /**
     * @param callable(PDO, float): void $applyStatementTimeout dialect-specific session setting
     * @param int $statementCacheSize prepared statements kept per connection (0 disables the cache)
     */
    public function __construct(
        private ?PDO $pdo,
        private readonly string $id,
        private $applyStatementTimeout,
        private readonly int $statementCacheSize = 64,
    ) {
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
        /** @var list<array<string, mixed>> $rows */
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        $statement->closeCursor();

        return $rows;
    }

    public function execute(string $sql, array $bindings = []): int
    {
        $statement = $this->run($sql, $bindings);
        $count = $statement->rowCount();
        $statement->closeCursor();

        return $count;
    }

    public function insert(string $sql, array $bindings = []): int
    {
        $statement = $this->run($sql, $bindings);
        if ($statement->columnCount() > 0) {
            // INSERT … RETURNING (PostgreSQL): the statement itself yields the key.
            $value = $statement->fetchColumn();
            $statement->closeCursor();

            return is_int($value) ? $value : (is_string($value) && $value !== '' ? (int) $value : 0);
        }
        $statement->closeCursor();
        $id = $this->pdo()->lastInsertId();

        return is_string($id) && $id !== '' ? (int) $id : 0;
    }

    public function beginTransaction(): void
    {
        $this->guard(static function (PDO $pdo): void {
            if (!$pdo->beginTransaction()) {
                throw new TransactionException('beginTransaction() failed');
            }
        }, 'BEGIN');
        $this->transactionDepth = 1;
    }

    public function commit(): void
    {
        if ($this->transactionDepth === 0) {
            throw new TransactionException('commit() without an active transaction');
        }
        $this->guard(static function (PDO $pdo): void {
            if (!$pdo->commit()) {
                throw new TransactionException('commit() failed');
            }
        }, 'COMMIT');
        $this->transactionDepth = 0;
    }

    public function rollBack(): void
    {
        if ($this->transactionDepth === 0) {
            throw new TransactionException('rollBack() without an active transaction');
        }
        $this->transactionDepth = 0;
        $this->guard(static function (PDO $pdo): void {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
        }, 'ROLLBACK');
    }

    public function inTransaction(): bool
    {
        return $this->transactionDepth > 0 || ($this->pdo !== null && $this->pdo->inTransaction());
    }

    public function setStatementTimeout(float $seconds): void
    {
        if ($seconds === $this->statementTimeout) {
            return;
        }
        $this->guard(function (PDO $pdo) use ($seconds): void {
            ($this->applyStatementTimeout)($pdo, $seconds);
        }, 'SET statement timeout');
        $this->statementTimeout = $seconds;
    }

    public function ping(): bool
    {
        if ($this->broken || $this->pdo === null) {
            return false;
        }
        try {
            $statement = $this->pdo->query('SELECT 1');
            if ($statement === false) {
                $this->broken = true;

                return false;
            }
            $statement->closeCursor();

            return true;
        } catch (Throwable) {
            $this->broken = true;

            return false;
        }
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
        $this->pdo = null; // PDO closes the socket when the object is released
    }

    /** Number of cached prepared statements (diagnostics). */
    public function cachedStatements(): int
    {
        return count($this->statements);
    }

    /**
     * Server-side prepared statements are reused per connection (LRU): a repeated query costs
     * one EXECUTE round trip instead of PREPARE + EXECUTE + CLOSE.
     */
    private function prepare(PDO $pdo, string $sql): PDOStatement
    {
        if ($this->statementCacheSize <= 0) {
            return $pdo->prepare($sql);
        }
        $cached = $this->statements[$sql] ?? null;
        if ($cached !== null) {
            unset($this->statements[$sql]);
            $this->statements[$sql] = $cached; // mark most recently used

            return $cached;
        }
        $statement = $pdo->prepare($sql);
        if (count($this->statements) >= $this->statementCacheSize) {
            unset($this->statements[array_key_first($this->statements)]); // evict least recently used (PDO closes it)
        }
        $this->statements[$sql] = $statement;

        return $statement;
    }

    /** @param list<mixed>|array<string, mixed> $bindings */
    private function run(string $sql, array $bindings): PDOStatement
    {
        $pdo = $this->pdo();
        try {
            $statement = $this->prepare($pdo, $sql);
            $i = 1;
            foreach ($bindings as $key => $value) {
                $parameter = is_int($key) ? $i++ : (str_starts_with($key, ':') ? $key : ':' . $key);
                $statement->bindValue($parameter, $value, self::type($value));
            }
            $statement->execute();
            $this->lastUsedAt = microtime(true);

            return $statement;
        } catch (PDOException $e) {
            throw $this->translate($e, $sql, $bindings);
        }
    }

    /** @param callable(PDO): void $operation */
    private function guard(callable $operation, string $label): void
    {
        try {
            $operation($this->pdo());
            $this->lastUsedAt = microtime(true);
        } catch (PDOException $e) {
            throw $this->translate($e, $label, []);
        }
    }

    /** @param array<mixed> $bindings */
    private function translate(PDOException $e, string $sql, array $bindings): DatabaseException
    {
        $exception = ErrorClassifier::classify($e, $sql, $bindings);
        // A query interrupted by Coroutine::cancel() leaves the protocol stream undefined.
        if ($exception instanceof ConnectionException || Coroutine::isCanceled()) {
            $this->broken = true;
            $this->statements = [];
            if (!$exception instanceof ConnectionException) {
                $exception = new ConnectionException('Connection discarded after an interrupted operation: ' . $exception->getMessage(), $exception->getCode(), $exception);
            }
        }

        return $exception;
    }

    private function pdo(): PDO
    {
        if ($this->pdo === null || $this->broken) {
            throw new ConnectionException(sprintf('Connection %s is closed', $this->id));
        }

        return $this->pdo;
    }

    private static function type(mixed $value): int
    {
        return match (true) {
            $value === null => PDO::PARAM_NULL,
            is_int($value) => PDO::PARAM_INT,
            is_bool($value) => PDO::PARAM_BOOL,
            default => PDO::PARAM_STR,
        };
    }
}
