<?php

declare(strict_types=1);

namespace Omnitrix\Tests\Unit\Database;

use Omnitrix\Database\Factories\Faker;
use Omnitrix\Tests\Support\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

use function array_unique;
use function count;
use function strlen;
use function time;

#[CoversClass(Faker::class)]
final class FakerTest extends TestCase
{
    public function testSeededGeneratorsRepeatAndUniqueNeverRepeats(): void
    {
        $a = new Faker(7);
        $b = new Faker(7);
        self::assertSame([$a->name(), $a->email(), $a->numberBetween(1, 100), $a->uuid()], [$b->name(), $b->email(), $b->numberBetween(1, 100), $b->uuid()], 'the same seed gives the same data');

        $faker = new Faker(1);
        $words = [];
        for ($i = 0; $i < 50; $i++) {
            $words[] = $faker->unique()->word();
        }
        self::assertCount(50, array_unique($words), 'unique() remembers values across calls');

        self::assertMatchesRegularExpression('/^[a-z]+\.[a-z]+\d+@example\.(com|org|net)$/', $faker->email());
        self::assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $faker->uuid());
        self::assertMatchesRegularExpression('/^#[0-9a-f]{6}$/', $faker->hexColor());
        self::assertMatchesRegularExpression('/^\+1-555-01\d\d$/', $faker->phoneNumber());
        self::assertLessThanOrEqual(80, strlen($faker->text(80)));
        $n = $faker->numberBetween(5, 6);
        self::assertTrue($n === 5 || $n === 6);
        self::assertContains($faker->randomElement(['x', 'y']), ['x', 'y']);
        self::assertGreaterThan(0, count($faker->words(3)));
        $date = $faker->dateTimeBetween('-1 week', 'now');
        self::assertLessThanOrEqual(time(), $date->getTimestamp());
    }
}
