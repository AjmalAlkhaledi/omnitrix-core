<?php

declare(strict_types=1);

namespace Omnitrix\Core\Tests\Unit\Database;

use Closure;
use Omnitrix\Core\Database\Driver\PostgreSQL\PgSqlGrammar;
use Omnitrix\Core\Database\Query\Builder;
use Omnitrix\Core\Database\Query\QueryExecutorInterface;
use Omnitrix\Core\Tests\Support\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

use function array_values;
use function end;

/** SQL the query builder produces for PostgreSQL. */
#[CoversClass(PgSqlGrammar::class)]
#[CoversClass(Builder::class)]
final class PgSqlGrammarTest extends TestCase
{
    /** @var list<array{string, list<mixed>}> */
    private array $executed = [];

    public function testIdentifiersAreDoubleQuotedAndPagingUsesLimitOffset(): void
    {
        $b = $this->builder()->select('id', 'u.name as n')->where('status', 'active')->orderBy('id')->limit(10)->offset(20);
        self::assertSame('select "id", "u"."name" as "n" from "users" where "status" = ? order by "id" asc limit 10 offset 20', $b->toSql());
        self::assertSame('select * from "users" offset 5', $this->builder()->offset(5)->toSql(), 'no artificial LIMIT for an offset alone');
        self::assertSame('"a""b"', (new PgSqlGrammar())->wrap('a"b'), 'quotes inside identifiers are doubled');
    }

    public function testAggregatesInsertGetIdAndLimitedWrites(): void
    {
        $this->builder()->where('status', 'active')->count();
        self::assertSame('select count(*) as "aggregate" from "users" where "status" = ?', $this->last()[0]);

        self::assertSame(99, $this->builder()->insertGetId(['name' => 'Ann']));
        self::assertSame(['insert into "users" ("name") values (?) returning "id"', ['Ann']], $this->last());
        $this->builder('memberships')->insertGetId(['role' => 'x'], 'member_id');
        self::assertStringEndsWith('returning "member_id"', $this->last()[0]);

        $this->builder()->where('status', 'idle')->orderBy('id', 'desc')->limit(3)->update(['status' => 'gone']);
        self::assertSame(['update "users" set "status" = ? where ctid in (select ctid from "users" where "status" = ? order by "id" desc limit 3)', ['gone', 'idle']], $this->last());

        $this->builder()->where('status', 'gone')->limit(2)->delete();
        self::assertSame(['delete from "users" where ctid in (select ctid from "users" where "status" = ? limit 2)', ['gone']], $this->last());

        $this->builder()->where('id', 1)->update(['status' => 'x']);
        self::assertSame('update "users" set "status" = ? where "id" = ?', $this->last()[0], 'unlimited writes are unchanged');
    }

    private function builder(string $table = 'users'): Builder
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

                return [['aggregate' => 3]];
            }

            public function execute(string $sql, array $bindings = []): int
            {
                ($this->record)($sql, $bindings);

                return 1;
            }

            public function insert(string $sql, array $bindings = []): int
            {
                ($this->record)($sql, $bindings);

                return 99;
            }
        };

        return new Builder(new PgSqlGrammar(), $executor, $table);
    }

    /** @return array{string, list<mixed>} */
    private function last(): array
    {
        $last = end($this->executed);
        self::assertNotFalse($last);

        return $last;
    }
}
