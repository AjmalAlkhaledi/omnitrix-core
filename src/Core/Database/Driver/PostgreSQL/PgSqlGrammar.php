<?php

declare(strict_types=1);

namespace Omnitrix\Core\Database\Driver\PostgreSQL;

use Omnitrix\Core\Database\Contracts\GrammarInterface;

use function count;
use function explode;
use function implode;
use function preg_split;
use function str_replace;
use function stripos;
use function trim;

/** PostgreSQL identifier quoting and dialect fragments. */
final class PgSqlGrammar implements GrammarInterface
{
    public function wrap(string $identifier): string
    {
        $identifier = trim($identifier);
        if ($identifier === '*') {
            return '*';
        }
        if (stripos($identifier, ' as ') !== false) {
            $parts = preg_split('/\s+as\s+/i', $identifier, 2);
            if ($parts !== false && count($parts) === 2) {
                return $this->wrap($parts[0]) . ' as ' . $this->wrapSegment($parts[1]);
            }
        }
        $segments = explode('.', $identifier);
        foreach ($segments as $i => $segment) {
            $segments[$i] = $segment === '*' ? '*' : $this->wrapSegment($segment);
        }

        return implode('.', $segments);
    }

    public function wrapTable(string $table): string
    {
        return $this->wrap($table);
    }

    public function parameter(): string
    {
        return '?';
    }

    public function compileLimitOffset(?int $limit, ?int $offset): string
    {
        $sql = $limit !== null ? ' limit ' . $limit : '';
        if ($offset !== null && $offset > 0) {
            $sql .= ' offset ' . $offset;
        }

        return $sql;
    }

    public function supportsReturning(): bool
    {
        return true;
    }

    public function dialect(): string
    {
        return 'pgsql';
    }

    public function supportsLimitedWrites(): bool
    {
        return false;
    }

    public function compileInsertGetId(string $insert, string $key): string
    {
        return $insert . ' returning ' . $this->wrap($key);
    }

    private function wrapSegment(string $segment): string
    {
        return '"' . str_replace('"', '""', trim($segment)) . '"';
    }
}
