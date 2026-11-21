<?php

declare(strict_types=1);

namespace Omnitrix\Database\Schema;

/** Compiles {@see Blueprint}s and schema questions for one SQL dialect. */
interface SchemaGrammarInterface
{
    /** @return list<string> statements in execution order */
    public function compile(Blueprint $blueprint): array;

    public function compileDrop(string $table, bool $ifExists): string;

    public function compileRename(string $from, string $to): string;

    public function wrap(string $identifier): string;

    /** SQL expression naming the schema the connection works in (for information_schema queries). */
    public function currentSchema(): string;

    /**
     * Statements dropping every listed table regardless of foreign keys between them.
     *
     * @param list<string> $tables
     *
     * @return list<string>
     */
    public function compileDropAll(array $tables): array;
}
