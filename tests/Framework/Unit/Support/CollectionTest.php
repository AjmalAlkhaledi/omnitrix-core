<?php

declare(strict_types=1);

namespace Omnitrix\Tests\Unit\Support;

use LogicException;
use Omnitrix\Support\Collection;
use Omnitrix\Tests\Support\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use stdClass;

#[CoversClass(Collection::class)]
final class CollectionTest extends TestCase
{
    /** @return Collection<int, array{name: string, team: string, score: int}> */
    private function people(): Collection
    {
        return Collection::make([
            ['name' => 'Ann', 'team' => 'red', 'score' => 30],
            ['name' => 'Bob', 'team' => 'blue', 'score' => 10],
            ['name' => 'Cy', 'team' => 'red', 'score' => 20],
        ]);
    }

    public function testTransformationsReturnNewCollections(): void
    {
        $people = $this->people();
        self::assertSame(['Bob', 'Cy', 'Ann'], $people->sortBy('score')->pluck('name')->all());
        self::assertSame(['Ann', 'Cy', 'Bob'], $people->sortByDesc('score')->pluck('name')->all());
        self::assertSame(['Ann', 'Cy'], $people->where('team', 'red')->pluck('name')->all());
        self::assertSame(['Ann'], $people->where('score', '>', 20)->pluck('name')->all());
        self::assertSame(['red' => 2, 'blue' => 1], $people->countBy('team')->all());
        self::assertSame(['Ann', 'Cy'], $people->groupBy('team')->get('red')?->pluck('name')->all());
        self::assertSame(['Ann' => 30, 'Bob' => 10, 'Cy' => 20], $people->pluck('score', 'name')->all());
        self::assertSame([60, 20, 10, 30, 20], [$people->sum('score'), $people->avg('score'), $people->min('score'), $people->max('score'), $people->median('score')]);
        self::assertSame('Ann, Bob, Cy', $people->implode(', ', 'name'));
        self::assertCount(3, $people, 'the original is unchanged');

        [$high, $low] = $people->partition(static fn(array $p): bool => $p['score'] >= 20);
        self::assertSame(['Ann', 'Cy'], $high->pluck('name')->all());
        self::assertSame(['Bob'], $low->pluck('name')->all());
    }

    public function testListOperations(): void
    {
        $numbers = Collection::range(1, 6);
        self::assertSame([2, 4, 6], $numbers->filter(static fn(int $n): bool => $n % 2 === 0)->values()->all());
        self::assertSame([[1, 2], [3, 4], [5, 6]], $numbers->chunk(2)->map(static fn(Collection $c): array => $c->values()->all())->all());
        self::assertSame([3, 4], $numbers->forPage(2, 2)->values()->all());
        self::assertSame([1, 2, 3, 4], Collection::make([[1, [2]], [3], 4])->flatten()->all());
        self::assertSame([1, [2], 3, 4], Collection::make([[1, [2]], [3], 4])->flatten(1)->all());
        self::assertSame(['1', 2], Collection::make(['1', 2, 2, '1'])->unique()->values()->all(), 'strict: "1" and 1 differ');
        self::assertSame([1, 3], Collection::make([1, '2', 3])->diff(['2'])->values()->all());
        self::assertSame(['2'], Collection::make([1, '2', 3])->intersect(['2', 3.0])->values()->all(), 'strict comparison');
        self::assertSame(['a' => 1, 'b' => 2], Collection::make(['a', 'b'])->combine([1, 2])->all());
        self::assertSame([[1, 'x'], [2, null]], Collection::make([1, 2])->zip(['x'])->all());
        self::assertSame([2, 4], Collection::times(2, static fn(int $i): int => $i * 2)->all());
        self::assertSame(6, $numbers->reduce(static fn(int $carry, int $n): int => $n > 3 ? $carry : $carry + $n, 0));
        self::assertSame([1, 2], Collection::make([0, 1, '', null, 2, false, '0', []])->filter()->values()->all());
        self::assertTrue($numbers->contains(3));
        self::assertFalse($numbers->search('3'), 'strict: the string "3" is not the integer 3');
        self::assertTrue($numbers->contains(static fn(int $n): bool => $n > 5));
        self::assertTrue($numbers->every(static fn(int $n): bool => $n > 0));
        self::assertSame(4, $numbers->first(static fn(int $n): bool => $n > 3));
        self::assertSame(6, $numbers->last());
    }

    public function testObjectsJsonAndImmutability(): void
    {
        $o = new stdClass();
        $o->team = new stdClass();
        $o->team->name = 'red';
        self::assertSame(['red'], Collection::make([$o])->pluck('team.name')->all(), 'dot notation over objects');
        self::assertSame('[{"a":1},[2]]', json_encode(Collection::make([Collection::make(['a' => 1]), [2]])));

        $c = Collection::make([1]);
        self::assertSame([1, 2], $c->push(2)->all());
        self::assertSame([1], $c->all());
        $this->expectException(LogicException::class);
        $c[] = 3;
    }
}
