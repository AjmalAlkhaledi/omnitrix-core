<?php

declare(strict_types=1);

namespace Omnitrix\Database;

use BadMethodCallException;
use Closure;
use Omnitrix\Core\Database\Query\Builder;
use Omnitrix\Core\Database\Query\Expression;

use function array_map;
use function func_num_args;
use function is_callable;
use function max;
use function method_exists;

/**
 * Query builder that returns models. Every filter, ordering and aggregate of the database
 * query builder is available; results come back as {@see Collection}s of models.
 *
 * ```php
 * $posts = Post::query()
 *     ->with('author', 'tags')
 *     ->where('published', true)
 *     ->latest()
 *     ->paginate(perPage: 20, page: (int) ($request->queryParam('page') ?? 1));
 * ```
 *
 * @template TModel of Model
 *
 * @method int                 count(string $column = '*')
 * @method bool                exists()
 * @method int|float           sum(string $column)
 * @method mixed               max(string $column)
 * @method mixed               min(string $column)
 * @method float|null          avg(string $column)
 * @method mixed               value(string $column)
 * @method list<mixed>         pluck(string $column)
 * @method string              toSql()
 * @method list<mixed>         getBindings()
 * @method $this               orWhere(string|Expression|Closure|array<string, mixed> $column, mixed $operator = null, mixed $value = null)
 * @method $this               whereNotIn(string $column, list<mixed> $values)
 * @method $this               whereNotNull(string $column)
 * @method $this               whereBetween(string $column, mixed $from, mixed $to)
 * @method $this               whereColumn(string $first, string $operator, string $second)
 * @method $this               whereRaw(string $sql, list<mixed> $bindings = [])
 * @method $this               join(string $table, string $first, string $operator, string $second, string $type = 'inner')
 * @method $this               leftJoin(string $table, string $first, string $operator, string $second)
 * @method $this               groupBy(string ...$columns)
 * @method $this               having(string|Expression $column, string $operator, mixed $value)
 * @method $this               distinct()
 * @method $this               lockForUpdate()
 * @method $this               addSelect(string|Expression ...$columns)
 */
final class ModelQuery
{
    /** @var array<string, mixed> */
    private array $eager = [];

    /** @param class-string<TModel> $model */
    public function __construct(private readonly string $model, private readonly Builder $builder) {}

    /**
     * Eager loads relations with the results: `with('author')`, nested `with('posts.comments')`,
     * constrained `with(['posts' => fn (ModelQuery $q) => $q->where('published', true)])`.
     *
     * @param string|array<int|string, string|callable(ModelQuery<covariant Model>): mixed> ...$relations
     *
     * @return $this
     */
    public function with(string|array ...$relations): self
    {
        foreach (EagerLoader::parse($relations) as $name => $spec) {
            $this->eager[$name] = $spec;
        }

        return $this;
    }

    /**
     * @param string|Expression|(Closure(Builder): void)|array<string, mixed> $column
     *
     * @return $this
     */
    public function where(string|Expression|Closure|array $column, mixed $operator = null, mixed $value = null): self
    {
        $this->builder->where($column, ...(func_num_args() === 3 ? [$operator, $value] : (func_num_args() === 2 ? [$operator] : [])));

        return $this;
    }

    /**
     * @param list<mixed> $values
     *
     * @return $this
     */
    public function whereIn(string $column, array $values): self
    {
        $this->builder->whereIn($column, $values);

        return $this;
    }

    /** @return $this */
    public function whereNull(string $column): self
    {
        $this->builder->whereNull($column);

        return $this;
    }

    /** @return $this */
    public function select(string|Expression ...$columns): self
    {
        $this->builder->select(...$columns);

        return $this;
    }

    /** @return $this */
    public function orderBy(string $column, string $direction = 'asc'): self
    {
        $this->builder->orderBy($column, $direction);

        return $this;
    }

    /** @return $this */
    public function orderByDesc(string $column): self
    {
        $this->builder->orderByDesc($column);

        return $this;
    }

    /**
     * Newest first by `created_at` (or another column).
     *
     * @return $this
     */
    public function latest(string $column = 'created_at'): self
    {
        return $this->orderByDesc($column);
    }

    /**
     * Oldest first by `created_at` (or another column).
     *
     * @return $this
     */
    public function oldest(string $column = 'created_at'): self
    {
        return $this->orderBy($column);
    }

    /** @return $this */
    public function limit(int $limit): self
    {
        $this->builder->limit($limit);

        return $this;
    }

    /** @return $this */
    public function offset(int $offset): self
    {
        $this->builder->offset($offset);

        return $this;
    }

    /** @return Collection<TModel> */
    public function get(): Collection
    {
        $class = $this->model;
        $models = new Collection(array_map(static fn(array $row): Model => $class::fromRow($row), $this->builder->get()));
        if ($this->eager !== [] && $models->isNotEmpty()) {
            EagerLoader::load($models, $this->eager);
        }

        /** @var Collection<TModel> $models */
        return $models;
    }

    /** @return TModel|null */
    public function first(): ?Model
    {
        $this->builder->limit(1);

        return $this->get()->first();
    }

    /** @return TModel */
    public function firstOrFail(): Model
    {
        return $this->first() ?? throw new ModelNotFoundException($this->model);
    }

    /** @return TModel|null */
    public function find(int|string $id): ?Model
    {
        $class = $this->model;

        return $this->where($this->qualify($class::keyName()), $id)->first();
    }

    /** @return TModel */
    public function findOrFail(int|string $id): Model
    {
        return $this->find($id) ?? throw new ModelNotFoundException($this->model, [$id]);
    }

    /**
     * @param list<int|string> $ids
     *
     * @return Collection<TModel>
     */
    public function findMany(array $ids): Collection
    {
        if ($ids === []) {
            return new Collection();
        }
        $class = $this->model;

        return $this->whereIn($this->qualify($class::keyName()), $ids)->get();
    }

    /**
     * Counts the matching rows, then loads one page (pages start at 1).
     *
     * @return Paginator<TModel>
     */
    public function paginate(int $perPage = 15, int $page = 1): Paginator
    {
        $perPage = max(1, $perPage);
        $page = max(1, $page);
        $total = (clone $this->builder)->count();
        $this->builder->limit($perPage)->offset(($page - 1) * $perPage);

        return new Paginator($this->get(), $total, $perPage, $page);
    }

    /**
     * Creates and saves a model.
     *
     * @param array<string, mixed> $attributes fillable attributes
     *
     * @return TModel
     */
    public function create(array $attributes): Model
    {
        $class = $this->model;

        return $class::create($attributes);
    }

    /**
     * Updates every matching row without loading models.
     *
     * @param array<string, mixed> $values
     */
    public function update(array $values): int
    {
        return $this->builder->update($values);
    }

    /** Deletes every matching row without loading models. */
    public function delete(): int
    {
        return $this->builder->delete();
    }

    /** The underlying database query builder. */
    public function toBase(): Builder
    {
        return $this->builder;
    }

    /** @return class-string<TModel> */
    public function modelClass(): string
    {
        return $this->model;
    }

    public function qualify(string $column): string
    {
        $class = $this->model;

        return $class::table() . '.' . $column;
    }

    /** @param list<mixed> $arguments */
    public function __call(string $method, array $arguments): mixed
    {
        if (!method_exists($this->builder, $method)) {
            throw new BadMethodCallException('Call to undefined method ' . self::class . "::{$method}()");
        }
        $callable = [$this->builder, $method];
        if (!is_callable($callable)) {
            throw new BadMethodCallException('Call to undefined method ' . self::class . "::{$method}()");
        }
        $result = $callable(...$arguments);

        return $result === $this->builder ? $this : $result;
    }
}
