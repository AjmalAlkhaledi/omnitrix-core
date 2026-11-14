<?php

declare(strict_types=1);

namespace Omnitrix\Tests\Unit\Support;

use Omnitrix\Support\Str;
use Omnitrix\Tests\Support\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

#[CoversClass(Str::class)]
final class StrTest extends TestCase
{
    /** @return iterable<array{string, string}> */
    public static function plurals(): iterable
    {
        yield ['post', 'posts'];
        yield ['category', 'categories'];
        yield ['day', 'days'];
        yield ['box', 'boxes'];
        yield ['address', 'addresses'];
        yield ['person', 'people'];
        yield ['user_profile', 'user_profiles'];
        yield ['order_item', 'order_items'];
        yield ['news', 'news'];
    }

    #[DataProvider('plurals')]
    public function testPluralAndSingularAreInverse(string $singular, string $plural): void
    {
        self::assertSame($plural, Str::plural($singular));
        self::assertSame($singular, Str::singular($plural));
    }

    public function testCaseConversions(): void
    {
        self::assertSame('user_profile', Str::snake('UserProfile'));
        self::assertSame('api_key', Str::snake('apiKey'));
        self::assertSame('UserProfile', Str::studly('user_profile'));
        self::assertSame('userProfile', Str::camel('user-profile'));
        self::assertSame('BlogPosts', Str::plural('BlogPost'));
    }
}
