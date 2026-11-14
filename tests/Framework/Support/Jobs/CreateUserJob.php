<?php

declare(strict_types=1);

namespace Omnitrix\Tests\Support\Jobs;

use Omnitrix\Core\Queue\Job;
use Omnitrix\Tests\Support\Models\User;

use function is_string;

/** Queue job used by the console tests: writes through a model, i.e. through the DB facade. */
final class CreateUserJob
{
    /** @param array<string, mixed> $payload */
    public function handle(array $payload, Job $job): void
    {
        $name = is_string($payload['name'] ?? null) ? $payload['name'] : 'unknown';
        User::create(['name' => $name, 'email' => $name . '@example.com', 'password' => 'x']);
    }
}
