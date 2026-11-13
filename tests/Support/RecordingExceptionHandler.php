<?php

declare(strict_types=1);

namespace Omnitrix\Core\Tests\Support;

use Omnitrix\Core\Runtime\Exception\ExceptionHandlerInterface;
use Throwable;

use function count;

/** Collects handled exceptions for assertions. */
final class RecordingExceptionHandler implements ExceptionHandlerInterface
{
    /** @var list<array{exception: Throwable, context: array<string, mixed>}> */
    public array $records = [];

    public function handle(Throwable $exception, array $context = []): void
    {
        $this->records[] = ['exception' => $exception, 'context' => $context];
    }

    /** @return array{exception: Throwable, context: array<string, mixed>}|null */
    public function first(): ?array
    {
        return $this->records[0] ?? null;
    }

    public function count(): int
    {
        return count($this->records);
    }
}
