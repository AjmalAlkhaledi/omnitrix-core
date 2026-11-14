<?php

declare(strict_types=1);

namespace Omnitrix\Database;

use ArrayAccess;
use ArrayIterator;
use Countable;
use IteratorAggregate;
use JsonSerializable;
use LogicException;
use Traversable;

use function array_filter;
use function array_map;
use function array_values;
use function count;
use function is_int;
use function is_string;

/**
 * An ordered list of models returned by queries. Serialises to a JSON array.
 *
 * @template TModel of Model
 *
 * @implements IteratorAggregate<int, TModel>
 * @implements ArrayAccess<int, TModel>
 */
final class Collection implements ArrayAccess, Countable, IteratorAggregate, JsonSerializable
{
    /** @var list<TModel> */
    private array $items;

    /** @param iterable<TModel> $items */
    public function __construct(iterable $items = [])
    {
        $list = [];
        foreach ($items as $item) {
            $list[] = $item;
        }
        $this->items = $list;
    }

    /** @return list<TModel> */
    public function all(): array
    {
        return $this->items;
    }

    /** @return TModel|null */
    public function first(): ?Model
    {
        return $this->items[0] ?? null;
    }

    /** @return TModel|null */
    public function last(): ?Model
    {
        return $this->items === [] ? null : $this->items[count($this->items) - 1];
    }

    public function isEmpty(): bool
    {
        return $this->items === [];
    }

    public function isNotEmpty(): bool
    {
        return $this->items !== [];
    }

    public function count(): int
    {
        return count($this->items);
    }

    /**
     * @template TResult
     *
     * @param callable(TModel): TResult $callback
     *
     * @return list<TResult>
     */
    public function map(callable $callback): array
    {
        return array_map($callback, $this->items);
    }

    /**
     * @param callable(TModel): bool $callback
     *
     * @return self<TModel>
     */
    public function filter(callable $callback): self
    {
        return new self(array_values(array_filter($this->items, $callback)));
    }

    /** @param callable(TModel): mixed $callback */
    public function each(callable $callback): void
    {
        foreach ($this->items as $item) {
            $callback($item);
        }
    }

    /** @return list<mixed> */
    public function pluck(string $attribute): array
    {
        return array_map(static fn(Model $m): mixed => $m->getAttribute($attribute), $this->items);
    }

    /** @return array<int|string, TModel> */
    public function keyBy(string $attribute): array
    {
        $keyed = [];
        foreach ($this->items as $item) {
            $key = $item->getAttribute($attribute);
            if (is_int($key) || is_string($key)) {
                $keyed[$key] = $item;
            }
        }

        return $keyed;
    }

    /** @return list<int|string> primary keys */
    public function modelKeys(): array
    {
        $keys = [];
        foreach ($this->items as $item) {
            $key = $item->getKey();
            if ($key !== null) {
                $keys[] = $key;
            }
        }

        return $keys;
    }

    /**
     * Eager loads relations for every model in the collection with one query per relation.
     *
     * @param string|array<int|string, string|callable(ModelQuery<covariant Model>): mixed> ...$relations
     *
     * @return self<TModel>
     */
    public function load(string|array ...$relations): self
    {
        if ($this->items !== []) {
            EagerLoader::load($this, EagerLoader::parse($relations));
        }

        return $this;
    }

    /** @return list<array<string, mixed>> */
    public function toArray(): array
    {
        return array_map(static fn(Model $m): array => $m->toArray(), $this->items);
    }

    /** @return list<array<string, mixed>> */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /** @return Traversable<int, TModel> */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->items);
    }

    public function offsetExists(mixed $offset): bool
    {
        return is_int($offset) && isset($this->items[$offset]);
    }

    /** @return TModel|null */
    public function offsetGet(mixed $offset): ?Model
    {
        return is_int($offset) ? ($this->items[$offset] ?? null) : null;
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        throw new LogicException('Collections are read-only');
    }

    public function offsetUnset(mixed $offset): void
    {
        throw new LogicException('Collections are read-only');
    }
}
