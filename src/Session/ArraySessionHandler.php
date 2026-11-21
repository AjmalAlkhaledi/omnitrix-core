<?php

declare(strict_types=1);

namespace Omnitrix\Session;

use Omnitrix\Contracts\SessionHandler;

use function microtime;

/** Sessions in process memory (tests, a single worker): lost on restart and not shared between workers. */
final class ArraySessionHandler implements SessionHandler
{
    /** @var array<string, array{array<string, mixed>, float}> */
    private array $sessions = [];

    public function read(string $id): ?array
    {
        $entry = $this->sessions[$id] ?? null;
        if ($entry === null || $entry[1] < microtime(true)) {
            unset($this->sessions[$id]);

            return null;
        }

        return $entry[0];
    }

    public function write(string $id, array $data, int $lifetimeSeconds): void
    {
        $this->sessions[$id] = [$data, microtime(true) + $lifetimeSeconds];
    }

    public function destroy(string $id): void
    {
        unset($this->sessions[$id]);
    }
}
