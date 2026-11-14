<?php

declare(strict_types=1);

namespace Omnitrix\Tests\Support\Models;

use Omnitrix\Database\Seeding\Seeder;

final class DemoUserSeeder extends Seeder
{
    public function run(): void
    {
        $user = User::create(['name' => 'Seeded', 'email' => 'seeded@example.com', 'password' => 'x']);
        $user->posts()->create(['title' => 'Seeded post', 'published' => true]);
    }
}
