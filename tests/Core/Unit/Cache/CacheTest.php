<?php

declare(strict_types=1);

namespace Omnitrix\Core\Tests\Unit\Cache;

use InvalidArgumentException;
use Omnitrix\Core\Cache\Cache;
use Omnitrix\Core\Tests\Support\MemoryStore;
use Omnitrix\Core\Tests\Support\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(Cache::class)]
final class CacheTest extends TestCase
{
    public function testRoundTripsJsonValuesWithPrefixAndTtl(): void
    {
        $store = new MemoryStore();
        $cache = new Cache($store, 'app:', 60);
        self::assertTrue($cache->set('user:1', ['id' => 1, 'name' => 'Ünicode', 'score' => 1.0, 'tags' => ['a']]));
        self::assertSame(['id' => 1, 'name' => 'Ünicode', 'score' => 1.0, 'tags' => ['a']], $cache->get('user:1'));
        self::assertSame('{"id":1,"name":"Ünicode","score":1.0,"tags":["a"]}', $store->get('app:user:1'), 'stored as JSON under the prefix');
        self::assertTrue($cache->has('user:1'));
        self::assertEqualsWithDelta(60, $store->ttl('app:user:1'), 1);
        self::assertSame('fallback', $cache->get('missing', 'fallback'));
        self::assertTrue($cache->set('n', null));
        self::assertNull($cache->get('n', 'default'), 'a cached null is a value, not a miss');
        self::assertSame(1, $cache->delete('user:1', 'n', 'nope') >= 1 ? 1 : 0);
        self::assertFalse($cache->has('user:1'));
    }

    public function testAddRememberIncrementAndForever(): void
    {
        $cache = new Cache($store = new MemoryStore(), 'c:', 10);
        self::assertTrue($cache->add('k', 'first'));
        self::assertFalse($cache->add('k', 'second'));
        self::assertSame('first', $cache->get('k'));

        $calls = 0;
        $compute = static function () use (&$calls): array {
            $calls++;

            return ['computed' => true];
        };
        $first = $cache->remember('r', 5, $compute);
        $second = $cache->remember('r', 5, $compute);
        self::assertSame(['computed' => true], $first);
        self::assertSame($first, $second);
        self::assertSame(1, $calls, 'computed once');

        self::assertSame(1, $cache->increment('hits'));
        self::assertSame(3, $cache->increment('hits', 2));
        self::assertSame(2, $cache->decrement('hits'));
        self::assertSame(2, $cache->get('hits'), 'counters read back as integers');

        $cache->forever('f', 'v');
        self::assertSame(-1, $cache->ttl('f'));
        self::assertSame(['k' => 'first', 'f' => 'v'], $cache->getMany(['k', 'f', 'absent']));
        $store->set('foreign', 'not json');
        self::assertSame('not json', (new Cache($store, '', 1))->get('foreign'), 'values written by other systems are returned raw');
    }

    public function testRejectsInvalidKeysAndUnencodableValues(): void
    {
        $cache = new Cache(new MemoryStore());
        try {
            $cache->get('has space');
            self::fail('key with whitespace accepted');
        } catch (InvalidArgumentException) {
        }
        try {
            $cache->set('', 1);
            self::fail('empty key accepted');
        } catch (InvalidArgumentException) {
        }
        $this->expectException(InvalidArgumentException::class);
        $cache->set('bad', "\xB1\x31");
    }
}
