<?php

declare(strict_types=1);

namespace Omnitrix\Tests\Support\Models;

use Omnitrix\Database\Seeding\Seeder;

final class DemoSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(DemoUserSeeder::class);
    }
}
