<?php

declare(strict_types=1);

namespace Omnitrix\Tests\Support;

use Omnitrix\Core\Database\Contracts\ConnectionInterface;
use Omnitrix\Core\Runtime\Coroutine\Sleep;

use function microtime;

/** In-memory connection for pool tests: records calls, can simulate latency and breakage. */
final class FakeConnection implements ConnectionInterface
{
    public static int $created = 0;

    public static int $closed = 0;

    private bool $broken = false;

    private bool $inTransaction = false;

    private readonly float $createdAt;

    private float $lastUsedAt;

    /** @var list<string> */
    public array $statements = [];

    public int $pings = 0;

    public bool $pingResult = true;

    public function __construct(public readonly string $id, public float $latency = 0.0)
    {
        self::$created++;
        $this->createdAt = microtime(true);
        $this->lastUsedAt = $this->createdAt;
    }

    public function id(): string
    {
        return $this->id;
    }

    public function select(string $sql, array $bindings = []): array
    {
        $this->use($sql);

        return [['sql' => $sql, 'connection' => $this->id]];
    }

    public function execute(string $sql, array $bindings = []): int
    {
        $this->use($sql);

        return 1;
    }

    public function insert(string $sql, array $bindings = []): int
    {
        $this->use($sql);

        return 42;
    }

    public function beginTransaction(): void
    {
        $this->inTransaction = true;
        $this->statements[] = 'BEGIN';
    }

    public function commit(): void
    {
        $this->inTransaction = false;
        $this->statements[] = 'COMMIT';
    }

    public function rollBack(): void
    {
        $this->inTransaction = false;
        $this->statements[] = 'ROLLBACK';
    }

    public function inTransaction(): bool
    {
        return $this->inTransaction;
    }

    public function setStatementTimeout(float $seconds): void
    {
        $this->statements[] = 'TIMEOUT ' . $seconds;
    }

    public function ping(): bool
    {
        $this->pings++;

        return $this->pingResult && !$this->broken;
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
        self::$closed++;
        $this->broken = true;
    }

    /** Pretends the connection has been idle for `$seconds`. */
    public function ageIdle(float $seconds): void
    {
        $this->lastUsedAt = microtime(true) - $seconds;
    }

    private function use(string $sql): void
    {
        $this->statements[] = $sql;
        if ($this->latency > 0.0) {
            Sleep::for($this->latency);
        }
        $this->lastUsedAt = microtime(true);
    }
}
