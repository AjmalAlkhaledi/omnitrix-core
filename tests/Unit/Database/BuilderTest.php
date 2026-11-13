<?php

declare(strict_types=1);

namespace Omnitrix\Core\Tests\Unit\Database;

use Closure;
use InvalidArgumentException;
use Omnitrix\Core\Database\Driver\MySQL\MySqlGrammar;
use Omnitrix\Core\Database\Query\Builder;
use Omnitrix\Core\Database\Query\Expression;
use Omnitrix\Core\Database\Query\QueryExecutorInterface;
use Omnitrix\Core\Database\Query\WhereClause;
use Omnitrix\Core\Tests\Support\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

use function array_values;
use function end;

#[CoversClass(Builder::class)]
#[CoversClass(MySqlGrammar::class)]
#[CoversClass(Expression::class)]
#[CoversClass(WhereClause::class)]
final class BuilderTest extends TestCase
{
    /** @var list<array{string, list<mixed>}> */
    private array $executed = [];

    private function builder(?string $table = 'users'): Builder
    {
        $record = function (string $sql, array $bindings): void {
            $this->executed[] = [$sql, array_values($bindings)];
        };
        $executor = new class ($record) implements QueryExecutorInterface {
            /** @param Closure(string, array<mixed>): void $record */
            public function __construct(private readonly Closure $record) {}

            public function select(string $sql, array $bindings = []): array
            {
                ($this->record)($sql, $bindings);

                return [['id' => 7, 'aggregate' => 3, 'exists' => 1, 'name' => 'n']];
            }

            public function execute(string $sql, array $bindings = []): int
            {
                ($this->record)($sql, $bindings);

                return 2;
            }

            public function insert(string $sql, array $bindings = []): int
            {
                ($this->record)($sql, $bindings);

                return 99;
            }
        };

        return new Builder(new MySqlGrammar(), $executor, $table);
    }

    /** @return array{string, list<mixed>} */
    private function last(): array
    {
        $last = end($this->executed);
        self::assertNotFalse($last);

        return $last;
    }

    public function testSelectWhereOrderLimitCompilesWithPositionalBindings(): void
    {
        $b = $this->builder()->select('id', 'u.name as n', 'users.*')
            ->where('status', 'active')
            ->where('age', '>=', 18)
            ->whereIn('role', ['admin', 'editor'])
            ->whereNull('deleted_at')
            ->whereBetween('score', 1, 10)
            ->orWhere(static function (Builder $q): void {
                $q->where('vip', true)->whereNotNull('verified_at');
            })
            ->orderBy('created_at', 'desc')->orderBy('id')
            ->limit(10)->offset(20);

        self::assertSame(
            'select `id`, `u`.`name` as `n`, `users`.* from `users` where `status` = ? and `age` >= ? and `role` in (?, ?) and `deleted_at` is null and `score` between ? and ? or (`vip` = ? and `verified_at` is not null) order by `created_at` desc, `id` asc limit 10 offset 20',
            $b->toSql(),
        );
        self::assertSame(['active', 18, 'admin', 'editor', 1, 10, true], $b->getBindings());
    }

    public function testJoinsGroupHavingDistinctAndRaw(): void
    {
        $b = $this->builder('orders as o')->distinct()
            ->select('o.user_id', Builder::raw('count(*) as total'))
            ->join('users as u', 'u.id', '=', 'o.user_id')
            ->leftJoin('coupons', 'coupons.order_id', '=', 'o.id')
            ->whereRaw('o.created_at > now() - interval ? day', [30])
            ->whereColumn('o.updated_at', '>', 'o.created_at')
            ->groupBy('o.user_id')
            ->having('total', '>', 5)
            ->lockForUpdate();

        self::assertSame(
            'select distinct `o`.`user_id`, count(*) as total from `orders` as `o` inner join `users` as `u` on `u`.`id` = `o`.`user_id` left join `coupons` on `coupons`.`order_id` = `o`.`id` where (o.created_at > now() - interval ? day) and `o`.`updated_at` > `o`.`created_at` group by `o`.`user_id` having `total` > ? for update',
            $b->toSql(),
        );
        self::assertSame([30, 5], $b->getBindings());
    }

    public function testNullAndEmptyInSemantics(): void
    {
        self::assertSame('select * from `users` where `a` is null and `b` is not null', $this->builder()->where('a', null)->where('b', '!=', null)->toSql());
        self::assertSame('select * from `users` where (0 = 1)', $this->builder()->whereIn('id', [])->toSql());
        self::assertSame('select * from `users` where (1 = 1)', $this->builder()->whereNotIn('id', [])->toSql());
        self::assertSame('select * from `users` where `a` = ? and `b` = ?', $this->builder()->where(['a' => 1, 'b' => 2])->toSql());
    }

    public function testIdentifiersAreQuotedSoInjectionThroughNamesIsImpossible(): void
    {
        $grammar = new MySqlGrammar();
        self::assertSame('`users``; drop table x; --`', $grammar->wrap('users`; drop table x; --'));
        self::assertSame('`t`.*', $grammar->wrap('t.*'));
        self::assertSame('select * from `users` where `name` = ?', $this->builder()->where('name', "' OR '1'='1")->toSql());
        self::assertSame(["' OR '1'='1"], $this->builder()->where('name', "' OR '1'='1")->getBindings());
    }

    public function testExecutionHelpers(): void
    {
        $b = $this->builder();
        self::assertSame(['id' => 7, 'aggregate' => 3, 'exists' => 1, 'name' => 'n'], $b->where('id', 7)->first());
        self::assertSame('select * from `users` where `id` = ? limit 1', $this->last()[0]);

        self::assertSame(3, $this->builder()->where('x', 1)->count());
        self::assertSame('select count(*) as `aggregate` from `users` where `x` = ?', $this->last()[0]);

        self::assertTrue($this->builder()->where('x', 1)->exists());
        self::assertSame('select exists(select * from `users` where `x` = ?) as `exists`', $this->last()[0]);

        self::assertSame(2, $this->builder()->insert([['name' => 'a', 'age' => 1], ['name' => 'b', 'age' => 2]]));
        self::assertSame(['insert into `users` (`name`, `age`) values (?, ?), (?, ?)', ['a', 1, 'b', 2]], $this->last());

        self::assertSame(99, $this->builder()->insertGetId(['name' => 'c']));
        self::assertSame('insert into `users` (`name`) values (?)', $this->last()[0]);

        self::assertSame(2, $this->builder()->where('id', 5)->update(['name' => 'z', 'visits' => Builder::raw('`visits` + 1')]));
        self::assertSame(['update `users` set `name` = ?, `visits` = `visits` + 1 where `id` = ?', ['z', 5]], $this->last());

        self::assertSame(2, $this->builder()->where('id', 5)->increment('visits', 3, ['touched' => 1]));
        self::assertSame(['update `users` set `visits` = `visits` + ?, `touched` = ? where `id` = ?', [3, 1, 5]], $this->last());

        self::assertSame(2, $this->builder()->where('id', 5)->limit(1)->delete());
        self::assertSame(['delete from `users` where `id` = ? limit 1', [5]], $this->last());

        self::assertSame(7, self::assoc($this->builder()->find(7))['id']);
        self::assertSame([7], $this->builder()->pluck('id'));
    }

    public function testGuardsAgainstDangerousOrInvalidUsage(): void
    {
        foreach ([
            static fn(Builder $b) => $b->delete(),
            static fn(Builder $b) => $b->update([]),
            static fn(Builder $b) => $b->where('a', 'UNION', 1),
            static fn(Builder $b) => $b->orderBy('a', 'sideways'),
            static fn(Builder $b) => $b->insert([]),
            static fn(Builder $b) => $b->insert([['a' => 1], ['b' => 2]]),
            static fn(Builder $b) => $b->where('a', 'in', 'not-an-array'),
            static fn(Builder $b) => $b->limit(-1),
        ] as $i => $misuse) {
            try {
                $misuse($this->builder());
                self::fail("misuse #{$i} was accepted");
            } catch (InvalidArgumentException) {
            }
        }
        $this->expectException(InvalidArgumentException::class);
        $this->builder(null)->toSql();
    }
}
