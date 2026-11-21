<?php

declare(strict_types=1);

namespace Omnitrix\Core\Database\Driver\MySQL;

use Omnitrix\Core\Database\Contracts\GrammarInterface;

use function count;
use function explode;
use function implode;
use function preg_split;
use function str_replace;
use function stripos;
use function trim;

/** MySQL/MariaDB identifier quoting and dialect fragments. */
final class MySqlGrammar implements GrammarInterface
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
        if ($limit === null && $offset === null) {
            return '';
        }
        // MySQL requires LIMIT when OFFSET is present.
        $sql = ' limit ' . ($limit === null ? '18446744073709551615' : (string) $limit);
        if ($offset !== null && $offset > 0) {
            $sql .= ' offset ' . $offset;
        }

        return $sql;
    }

    public function supportsReturning(): bool
    {
        return false;
    }

    public function dialect(): string
    {
        return 'mysql';
    }

    public function supportsLimitedWrites(): bool
    {
        return true;
    }

    public function compileInsertGetId(string $insert, string $key): string
    {
        return $insert; // the key is read with LAST_INSERT_ID()
    }

    private function wrapSegment(string $segment): string
    {
        $segment = trim($segment);

        return '`' . str_replace('`', '``', $segment) . '`';
    }
}
