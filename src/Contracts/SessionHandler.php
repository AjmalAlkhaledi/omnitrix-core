<?php

declare(strict_types=1);

namespace Omnitrix\Contracts;

/** Persists session data by id. */
interface SessionHandler
{
    /** @return array<string, mixed>|null null when the session does not exist (or expired) */
    public function read(string $id): ?array;

    /** @param array<string, mixed> $data */
    public function write(string $id, array $data, int $lifetimeSeconds): void;

    public function destroy(string $id): void;
}
