<?php

declare(strict_types=1);

namespace Omnitrix\Database\Relations;

use Omnitrix\Database\Collection;
use Omnitrix\Database\Model;
use Omnitrix\Database\ModelQuery;

use function array_filter;
use function array_map;
use function array_merge;
use function array_values;
use function in_array;
use function is_array;
use function is_int;
use function is_string;

/**
 * Many-to-many through a pivot table: `posts` ↔ `post_tag(post_id, tag_id)` ↔ `tags`.
 *
 * ```php
 * $post->tags()->attach([1, 2]);
 * $post->tags()->sync([2, 3]);      // detaches 1, attaches 3
 * $post->tags()->detach(2);
 * ```
 *
 * @template TRelated of Model
 *
 * @extends Relation<TRelated>
 */
final class BelongsToMany extends Relation
{
    /** Alias of the pivot's parent key in the result rows; removed before models are returned. */
    private const string PIVOT_KEY = 'omnitrix_pivot_parent';

    /** @param class-string<TRelated> $related */
    public function __construct(
        Model $parent,
        string $related,
        private readonly string $pivotTable,
        private readonly string $foreignPivotKey,
        private readonly string $relatedPivotKey,
        private readonly string $parentKey,
        private readonly string $relatedKey,
    ) {
        parent::__construct($parent, $related);
    }

    /** @return Collection<TRelated> */
    public function getResults(): Collection
    {
        return $this->parentValue() === null ? new Collection() : $this->get();
    }

    /** @return Collection<TRelated> */
    public function get(): Collection
    {
        $results = $this->query()->get();
        foreach ($results as $model) {
            $model->forgetAttribute(self::PIVOT_KEY);
        }

        return $results;
    }

    /**
     * Adds pivot rows linking the parent to the given models or ids.
     *
     * @param Model|int|string|list<Model|int|string> $ids
     * @param array<string, mixed>                    $attributes extra pivot columns
     */
    public function attach(Model|int|string|array $ids, array $attributes = []): void
    {
        $rows = [];
        foreach ($this->normalise($ids) as $id) {
            $rows[] = array_merge([$this->foreignPivotKey => $this->parentValue(), $this->relatedPivotKey => $id], $attributes);
        }
        if ($rows !== []) {
            $this->pivot()->insert($rows);
        }
    }

    /**
     * Removes pivot rows for the given models or ids, or all of the parent's rows.
     *
     * @param Model|int|string|list<Model|int|string>|null $ids
     */
    public function detach(Model|int|string|array|null $ids = null): int
    {
        $query = $this->pivot()->where($this->foreignPivotKey, $this->parentValue());
        if ($ids !== null) {
            $ids = $this->normalise($ids);
            if ($ids === []) {
                return 0;
            }
            $query->whereIn($this->relatedPivotKey, $ids);
        }

        return $query->delete();
    }

    /**
     * Makes the given ids the complete set of related rows.
     *
     * @param list<Model|int|string> $ids
     *
     * @return array{attached: list<int|string>, detached: list<int|string>}
     */
    public function sync(array $ids): array
    {
        $wanted = $this->normalise($ids);
        $current = [];
        foreach ($this->pivot()->where($this->foreignPivotKey, $this->parentValue())->pluck($this->relatedPivotKey) as $value) {
            if (is_int($value) || is_string($value)) {
                $current[] = $value;
            }
        }
        $key = static fn(int|string $v): string => (string) $v;
        $currentKeys = array_map($key, $current);
        $wantedKeys = array_map($key, $wanted);
        $detach = array_values(array_filter($current, static fn(int|string $v): bool => !in_array((string) $v, $wantedKeys, true)));
        $attach = array_values(array_filter($wanted, static fn(int|string $v): bool => !in_array((string) $v, $currentKeys, true)));
        if ($detach !== []) {
            $this->detach($detach);
        }
        if ($attach !== []) {
            $this->attach($attach);
        }

        return ['attached' => $attach, 'detached' => $detach];
    }

    protected function addConstraints(ModelQuery $query): void
    {
        $this->joinPivot($query);
        $query->where($this->pivotTable . '.' . $this->foreignPivotKey, $this->parentValue());
    }

    protected function addEagerConstraints(ModelQuery $query, Collection $parents): void
    {
        $this->joinPivot($query);
        $query->whereIn($this->pivotTable . '.' . $this->foreignPivotKey, self::keys($parents, $this->parentKey));
    }

    protected function match(Collection $parents, Collection $results, string $name): void
    {
        $grouped = [];
        foreach ($results as $result) {
            $key = self::keyString($result->getAttributes()[self::PIVOT_KEY] ?? null);
            $result->forgetAttribute(self::PIVOT_KEY);
            if ($key !== null) {
                $grouped[$key][] = $result;
            }
        }
        foreach ($parents as $parent) {
            $key = self::keyString($parent->getAttributes()[$this->parentKey] ?? null);
            $parent->setRelation($name, new Collection($key === null ? [] : ($grouped[$key] ?? [])));
        }
    }

    /** @param ModelQuery<TRelated> $query */
    private function joinPivot(ModelQuery $query): void
    {
        $class = $this->related;
        $table = $class::table();
        $query->select($table . '.*', $this->pivotTable . '.' . $this->foreignPivotKey . ' as ' . self::PIVOT_KEY)
            ->join($this->pivotTable, $this->pivotTable . '.' . $this->relatedPivotKey, '=', $table . '.' . $this->relatedKey);
    }

    private function pivot(): \Omnitrix\Core\Database\Query\Builder
    {
        $class = $this->related;

        return $class::connection()->table($this->pivotTable);
    }

    private function parentValue(): mixed
    {
        return $this->parent->getAttributes()[$this->parentKey] ?? null;
    }

    /**
     * @param Model|int|string|list<Model|int|string> $ids
     *
     * @return list<int|string>
     */
    private function normalise(Model|int|string|array $ids): array
    {
        $list = [];
        foreach (is_array($ids) ? $ids : [$ids] as $id) {
            $value = $id instanceof Model ? ($id->getAttributes()[$this->relatedKey] ?? null) : $id;
            if (is_int($value) || is_string($value)) {
                $list[] = $value;
            }
        }

        return $list;
    }
}
