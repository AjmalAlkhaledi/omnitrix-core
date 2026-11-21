<?php

declare(strict_types=1);

namespace Omnitrix\Support;

use ArrayAccess;
use ArrayIterator;
use BackedEnum;
use Countable;
use InvalidArgumentException;
use IteratorAggregate;
use JsonSerializable;
use LogicException;
use Stringable;
use Traversable;

use function array_chunk;
use function array_combine;
use function array_diff_key;
use function array_fill_keys;
use function array_filter;
use function array_flip;
use function array_intersect_key;
use function array_is_list;
use function array_key_exists;
use function array_key_first;
use function array_key_last;
use function array_keys;
use function array_map;
use function array_merge;
use function array_reverse;
use function array_search;
use function array_slice;
use function array_sum;
use function array_values;
use function count;
use function explode;
use function get_debug_type;
use function get_object_vars;
use function implode;
use function in_array;
use function intdiv;
use function is_array;
use function is_bool;
use function is_callable;
use function is_float;
use function is_int;
use function is_numeric;
use function is_object;
use function is_scalar;
use function is_string;
use function iterator_to_array;
use function max;
use function method_exists;
use function min;
use function serialize;
use function shuffle;
use function sort;
use function spl_object_id;
use function uasort;
use function usort;

use const ARRAY_FILTER_USE_BOTH;

/**
 * A fluent wrapper over an array: transformations return new collections, the original is never
 * changed. Keys are kept unless a method says otherwise (`values()`, `map()` keeps keys,
 * `sortBy()` keeps keys, `pluck()` and `flatten()` re-index).
 *
 * Item access by name (`pluck('email')`, `sortBy('name')`, `groupBy('team.id')`) reads array
 * keys, public properties and `__get` attributes (models), with dot notation for nesting.
 *
 * @template TKey of array-key
 * @template TValue
 *
 * @implements ArrayAccess<TKey, TValue>
 * @implements IteratorAggregate<TKey, TValue>
 */
class Collection implements ArrayAccess, Countable, IteratorAggregate, JsonSerializable
{
    /** @var array<TKey, TValue> */
    protected array $items;

    /** @param iterable<TKey, TValue> $items */
    final public function __construct(iterable $items = [])
    {
        $this->items = is_array($items) ? $items : iterator_to_array($items);
    }

    /**
     * @template TMakeKey of array-key
     * @template TMakeValue
     *
     * @param iterable<TMakeKey, TMakeValue> $items
     *
     * @return static<TMakeKey, TMakeValue>
     */
    public static function make(iterable $items = []): static
    {
        return new static($items);
    }

    /** @return static<int, int> */
    public static function range(int $from, int $to, int $step = 1): static
    {
        if ($step < 1) {
            throw new InvalidArgumentException('range() needs a positive step');
        }
        $items = [];
        if ($from <= $to) {
            for ($i = $from; $i <= $to; $i += $step) {
                $items[] = $i;
            }
        } else {
            for ($i = $from; $i >= $to; $i -= $step) {
                $items[] = $i;
            }
        }

        return new static($items);
    }

    /**
     * @template TTimesValue
     *
     * @param callable(int): TTimesValue $callback receives 1…$times
     *
     * @return static<int, TTimesValue>
     */
    public static function times(int $times, callable $callback): static
    {
        $items = [];
        for ($i = 1; $i <= $times; $i++) {
            $items[] = $callback($i);
        }

        return new static($items);
    }

    /** @return array<TKey, TValue> */
    public function all(): array
    {
        return $this->items;
    }

    // --- inspection ---------------------------------------------------------------------------

    public function count(): int
    {
        return count($this->items);
    }

    public function isEmpty(): bool
    {
        return $this->items === [];
    }

    public function isNotEmpty(): bool
    {
        return $this->items !== [];
    }

    /** @param (callable(TValue, TKey): bool)|TValue $value a value (strict) or a test */
    public function contains(mixed $value): bool
    {
        if (is_callable($value) && !is_string($value)) {
            foreach ($this->items as $key => $item) {
                if ($value($item, $key) === true) {
                    return true;
                }
            }

            return false;
        }

        return in_array($value, $this->items, true);
    }

    /** @param callable(TValue, TKey): bool $callback */
    public function every(callable $callback): bool
    {
        foreach ($this->items as $key => $item) {
            if (!$callback($item, $key)) {
                return false;
            }
        }

        return true;
    }

    /** @param TKey $key */
    public function has(int|string $key): bool
    {
        return array_key_exists($key, $this->items);
    }

    /**
     * @template TDefault
     *
     * @param TKey     $key
     * @param TDefault $default
     *
     * @return TValue|TDefault
     */
    public function get(int|string $key, mixed $default = null): mixed
    {
        return array_key_exists($key, $this->items) ? $this->items[$key] : $default;
    }

    /**
     * @param (callable(TValue, TKey): bool)|null $callback first item passing the test
     *
     * @return TValue|null
     */
    public function first(?callable $callback = null, mixed $default = null): mixed
    {
        if ($callback === null) {
            $key = array_key_first($this->items);

            return $key === null ? $default : $this->items[$key];
        }
        foreach ($this->items as $key => $item) {
            if ($callback($item, $key)) {
                return $item;
            }
        }

        return $default;
    }

    /**
     * @param (callable(TValue, TKey): bool)|null $callback last item passing the test
     *
     * @return TValue|null
     */
    public function last(?callable $callback = null, mixed $default = null): mixed
    {
        if ($callback === null) {
            $key = array_key_last($this->items);

            return $key === null ? $default : $this->items[$key];
        }

        return $this->reverse()->first($callback, $default);
    }

    /** @return TKey|false */
    public function search(mixed $value): int|string|false
    {
        return array_search($value, $this->items, true);
    }

    // --- transformations ----------------------------------------------------------------------

    /**
     * @template TMapValue
     *
     * @param callable(TValue, TKey): TMapValue $callback
     *
     * @return static<TKey, TMapValue>
     */
    public function map(callable $callback): static
    {
        $keys = array_keys($this->items);
        $mapped = array_map($callback, $this->items, $keys);

        return new static(array_combine($keys, $mapped));
    }

    /**
     * Maps each item to `[key => value]` pairs merged into one collection.
     *
     * @template TMapWithKeysKey of array-key
     * @template TMapWithKeysValue
     *
     * @param callable(TValue, TKey): array<TMapWithKeysKey, TMapWithKeysValue> $callback
     *
     * @return static<TMapWithKeysKey, TMapWithKeysValue>
     */
    public function mapWithKeys(callable $callback): static
    {
        $result = [];
        foreach ($this->items as $key => $item) {
            foreach ($callback($item, $key) as $k => $v) {
                $result[$k] = $v;
            }
        }

        return new static($result);
    }

    /**
     * @template TFlatMapValue
     *
     * @param callable(TValue, TKey): iterable<TFlatMapValue> $callback
     *
     * @return static<int, TFlatMapValue>
     */
    public function flatMap(callable $callback): static
    {
        $result = [];
        foreach ($this->items as $key => $item) {
            foreach ($callback($item, $key) as $value) {
                $result[] = $value;
            }
        }

        return new static($result);
    }

    /**
     * @param (callable(TValue, TKey): bool)|null $callback null drops falsy values
     *
     * @return static<TKey, TValue>
     */
    public function filter(?callable $callback = null): static
    {
        $callback ??= static fn(mixed $item): bool => !in_array($item, [false, null, 0, 0.0, '', '0', []], true);

        return new static(array_filter($this->items, $callback, ARRAY_FILTER_USE_BOTH));
    }

    /**
     * @param callable(TValue, TKey): bool $callback
     *
     * @return static<TKey, TValue>
     */
    public function reject(callable $callback): static
    {
        return $this->filter(static fn(mixed $item, int|string $key): bool => !$callback($item, $key));
    }

    /**
     * Items whose attribute compares to `$value` (`where('status', 'active')`,
     * `where('age', '>=', 18)`). Equality is strict.
     *
     * @return static<TKey, TValue>
     */
    public function where(string $key, mixed $operator, mixed $value = null): static
    {
        if ($value === null && !in_array($operator, ['=', '==', '===', '!=', '!==', '<>', '<', '<=', '>', '>='], true)) {
            [$value, $operator] = [$operator, '==='];
        }

        return $this->filter(static function (mixed $item) use ($key, $operator, $value): bool {
            $actual = self::dataGet($item, $key);

            return match ($operator) {
                '=', '==', '===' => $actual === $value,
                '!=', '!==', '<>' => $actual !== $value,
                '<' => $actual < $value,
                '<=' => $actual <= $value,
                '>' => $actual > $value,
                '>=' => $actual >= $value,
                default => throw new InvalidArgumentException("Unsupported operator '" . (is_string($operator) ? $operator : get_debug_type($operator)) . "'"),
            };
        });
    }

    /**
     * @param list<mixed> $values
     *
     * @return static<TKey, TValue>
     */
    public function whereIn(string $key, array $values): static
    {
        return $this->filter(static fn(mixed $item): bool => in_array(self::dataGet($item, $key), $values, true));
    }

    /** @return static<array-key, mixed> values of one attribute (a list); `$keyBy` keys them by another */
    public function pluck(string $value, ?string $keyBy = null): static
    {
        $result = [];
        foreach ($this->items as $item) {
            if ($keyBy === null) {
                $result[] = self::dataGet($item, $value);
            } else {
                $key = self::dataGet($item, $keyBy);
                $result[is_int($key) || is_string($key) ? $key : serialize($key)] = self::dataGet($item, $value);
            }
        }

        return new static($result);
    }

    /**
     * @param string|callable(TValue, TKey): array-key $key
     *
     * @return static<array-key, TValue>
     */
    public function keyBy(string|callable $key): static
    {
        $result = [];
        foreach ($this->items as $k => $item) {
            $result[self::arrayKey(is_string($key) ? self::dataGet($item, $key) : $key($item, $k))] = $item;
        }

        return new static($result);
    }

    /**
     * @param string|callable(TValue, TKey): array-key $key
     *
     * @return static<array-key, static<int, TValue>>
     */
    public function groupBy(string|callable $key): static
    {
        $groups = [];
        foreach ($this->items as $k => $item) {
            $groups[self::arrayKey(is_string($key) ? self::dataGet($item, $key) : $key($item, $k))][] = $item;
        }

        return new static(array_map(static fn(array $group): static => new static($group), $groups));
    }

    /**
     * Splits into items that pass the test and items that do not.
     *
     * @param callable(TValue, TKey): bool $callback
     *
     * @return array{static<TKey, TValue>, static<TKey, TValue>}
     */
    public function partition(callable $callback): array
    {
        $pass = [];
        $fail = [];
        foreach ($this->items as $key => $item) {
            if ($callback($item, $key)) {
                $pass[$key] = $item;
            } else {
                $fail[$key] = $item;
            }
        }

        return [new static($pass), new static($fail)];
    }

    /** @return static<int, TValue> */
    public function values(): static
    {
        return new static(array_values($this->items));
    }

    /** @return static<int, TKey> */
    public function keys(): static
    {
        return new static(array_keys($this->items));
    }

    /** @return static<int, mixed> nested arrays and collections flattened (`$depth` levels, 0 = all) */
    public function flatten(int $depth = 0): static
    {
        return new static(self::flattenArray($this->items, $depth));
    }

    /** @return static<array-key, TKey> */
    public function flip(): static
    {
        /** @var array<array-key, TKey> $flipped */
        $flipped = array_flip(array_map(static fn(mixed $v): int|string => self::arrayKey($v), $this->items));

        return new static($flipped);
    }

    /** @return static<int, static<TKey, TValue>> */
    public function chunk(int $size): static
    {
        if ($size < 1) {
            throw new InvalidArgumentException('chunk() needs a positive size');
        }

        return new static(array_map(static fn(array $chunk): static => new static($chunk), array_chunk($this->items, $size, true)));
    }

    /**
     * @param string|(callable(TValue): mixed)|null $by attribute or callback; null compares values
     *
     * @return static<TKey, TValue>
     */
    public function unique(string|callable|null $by = null): static
    {
        $seen = [];
        $result = [];
        foreach ($this->items as $key => $item) {
            $value = $by === null ? $item : (is_string($by) ? self::dataGet($item, $by) : $by($item));
            $id = is_object($value) ? 'o' . spl_object_id($value) : serialize($value);
            if (!isset($seen[$id])) {
                $seen[$id] = true;
                $result[$key] = $item;
            }
        }

        return new static($result);
    }

    /**
     * Sorted by value, attribute or callback (`$callback(item)` returns the sort key). Keys are kept.
     *
     * @param string|(callable(TValue): mixed)|null $by
     *
     * @return static<TKey, TValue>
     */
    public function sortBy(string|callable|null $by = null, bool $descending = false): static
    {
        $items = $this->items;
        uasort($items, static function (mixed $a, mixed $b) use ($by, $descending): int {
            $x = $by === null ? $a : (is_string($by) ? self::dataGet($a, $by) : $by($a));
            $y = $by === null ? $b : (is_string($by) ? self::dataGet($b, $by) : $by($b));

            return $descending ? $y <=> $x : $x <=> $y;
        });

        return new static($items);
    }

    /**
     * @param string|(callable(TValue): mixed)|null $by
     *
     * @return static<TKey, TValue>
     */
    public function sortByDesc(string|callable|null $by = null): static
    {
        return $this->sortBy($by, descending: true);
    }

    /**
     * Sorted with a comparison callback (re-indexed).
     *
     * @param callable(TValue, TValue): int $comparator
     *
     * @return static<int, TValue>
     */
    public function sort(callable $comparator): static
    {
        $items = array_values($this->items);
        usort($items, $comparator);

        return new static($items);
    }

    /** @return static<TKey, TValue> */
    public function reverse(): static
    {
        return new static(array_reverse($this->items, true));
    }

    /** @return static<int, TValue> */
    public function shuffle(): static
    {
        $items = array_values($this->items);
        shuffle($items);

        return new static($items);
    }

    /** @return static<TKey, TValue> */
    public function slice(int $offset, ?int $length = null): static
    {
        return new static(array_slice($this->items, $offset, $length, true));
    }

    /** @return static<TKey, TValue> the first `$limit` items (the last ones for a negative limit) */
    public function take(int $limit): static
    {
        return $limit < 0 ? $this->slice($limit) : $this->slice(0, $limit);
    }

    /** @return static<TKey, TValue> */
    public function skip(int $count): static
    {
        return $this->slice($count);
    }

    /**
     * Page `$page` (1-based) of `$perPage` items.
     *
     * @return static<TKey, TValue>
     */
    public function forPage(int $page, int $perPage): static
    {
        return $this->slice(max(0, ($page - 1) * $perPage), $perPage);
    }

    /**
     * @param iterable<array-key, TValue> $items
     *
     * @return static<array-key, TValue> list items appended; string keys overwritten
     */
    public function merge(iterable $items): static
    {
        return new static(array_merge($this->items, self::toArrayOf($items)));
    }

    /**
     * @param iterable<array-key, TValue> $items
     *
     * @return static<int, TValue>
     */
    public function concat(iterable $items): static
    {
        $result = array_values($this->items);
        foreach ($items as $item) {
            $result[] = $item;
        }

        return new static($result);
    }

    /**
     * Pairs keys with these values (`collect(['a', 'b'])->combine([1, 2])` → `['a' => 1, 'b' => 2]`).
     *
     * @template TCombineValue
     *
     * @param iterable<array-key, TCombineValue> $values
     *
     * @return static<array-key, TCombineValue>
     */
    public function combine(iterable $values): static
    {
        $keys = array_map(static fn(mixed $v): int|string => self::arrayKey($v), array_values($this->items));
        $values = array_values(self::toArrayOf($values));
        if (count($keys) !== count($values)) {
            throw new InvalidArgumentException('combine() needs as many values as items');
        }

        return new static(array_combine($keys, $values));
    }

    /**
     * @param iterable<array-key, mixed> $items
     *
     * @return static<TKey, TValue> items not present in `$items` (strict comparison)
     */
    public function diff(iterable $items): static
    {
        $other = array_values(self::toArrayOf($items));

        return $this->reject(static fn(mixed $item): bool => in_array($item, $other, true));
    }

    /**
     * @param iterable<array-key, mixed> $items
     *
     * @return static<TKey, TValue> items also present in `$items` (strict comparison)
     */
    public function intersect(iterable $items): static
    {
        $other = array_values(self::toArrayOf($items));

        return $this->filter(static fn(mixed $item): bool => in_array($item, $other, true));
    }

    /**
     * @param list<TKey> $keys
     *
     * @return static<TKey, TValue>
     */
    public function only(array $keys): static
    {
        return new static(array_intersect_key($this->items, array_fill_keys($keys, true)));
    }

    /**
     * @param list<TKey> $keys
     *
     * @return static<TKey, TValue>
     */
    public function except(array $keys): static
    {
        return new static(array_diff_key($this->items, array_fill_keys($keys, true)));
    }

    /**
     * @template TZipValue
     *
     * @param iterable<array-key, TZipValue> $items
     *
     * @return static<int, array{TValue, TZipValue|null}>
     */
    public function zip(iterable $items): static
    {
        $other = array_values(self::toArrayOf($items));
        $result = [];
        foreach (array_values($this->items) as $i => $item) {
            $result[] = [$item, $other[$i] ?? null];
        }

        return new static($result);
    }

    /**
     * @return static<array-key, mixed> the collection with the values appended
     */
    public function push(mixed ...$values): static
    {
        $items = $this->items;
        foreach ($values as $value) {
            $items[] = $value;
        }

        return new static($items);
    }

    /**
     * @param TKey   $key
     * @param TValue $value
     *
     * @return static<TKey, TValue>
     */
    public function put(int|string $key, mixed $value): static
    {
        $items = $this->items;
        $items[$key] = $value;

        return new static($items);
    }

    /**
     * @param TKey $key
     *
     * @return static<TKey, TValue>
     */
    public function forget(int|string $key): static
    {
        $items = $this->items;
        unset($items[$key]);

        return new static($items);
    }

    // --- aggregates ---------------------------------------------------------------------------

    /**
     * @template TCarry
     *
     * @param callable(TCarry, TValue, TKey): TCarry $callback
     * @param TCarry                                  $initial
     *
     * @return TCarry
     */
    public function reduce(callable $callback, mixed $initial = null): mixed
    {
        $carry = $initial;
        foreach ($this->items as $key => $item) {
            $carry = $callback($carry, $item, $key);
        }

        return $carry;
    }

    /** @param string|(callable(TValue): (int|float))|null $by */
    public function sum(string|callable|null $by = null): int|float
    {
        return array_sum($this->numbers($by));
    }

    /** @param string|(callable(TValue): (int|float))|null $by */
    public function avg(string|callable|null $by = null): int|float|null
    {
        $numbers = $this->numbers($by);

        return $numbers === [] ? null : array_sum($numbers) / count($numbers);
    }

    /** @param string|(callable(TValue): mixed)|null $by */
    public function min(string|callable|null $by = null): mixed
    {
        $values = $this->extract($by);

        return $values === [] ? null : min($values);
    }

    /** @param string|(callable(TValue): mixed)|null $by */
    public function max(string|callable|null $by = null): mixed
    {
        $values = $this->extract($by);

        return $values === [] ? null : max($values);
    }

    /** @param string|(callable(TValue): (int|float))|null $by */
    public function median(string|callable|null $by = null): int|float|null
    {
        $numbers = $this->numbers($by);
        if ($numbers === []) {
            return null;
        }
        sort($numbers);
        $middle = intdiv(count($numbers), 2);

        return count($numbers) % 2 === 1 ? $numbers[$middle] : ($numbers[$middle - 1] + $numbers[$middle]) / 2;
    }

    /**
     * Number of items per value (or per attribute/callback result).
     *
     * @param string|(callable(TValue): array-key)|null $by
     *
     * @return static<array-key, positive-int>
     */
    public function countBy(string|callable|null $by = null): static
    {
        $counts = [];
        foreach ($this->items as $item) {
            $key = self::arrayKey($by === null ? $item : (is_string($by) ? self::dataGet($item, $by) : $by($item)));
            $counts[$key] = ($counts[$key] ?? 0) + 1;
        }

        return new static($counts);
    }

    public function implode(string $glue, ?string $attribute = null): string
    {
        $values = $attribute === null ? $this->items : $this->pluck($attribute)->all();

        return implode($glue, array_map(static fn(mixed $v): string => is_scalar($v) || $v instanceof Stringable ? (string) $v : '', $values));
    }

    // --- side effects and conversion ----------------------------------------------------------

    /**
     * Calls `$callback` for each item; returning false stops the loop.
     *
     * @param callable(TValue, TKey): mixed $callback
     *
     * @return $this
     */
    public function each(callable $callback): static
    {
        foreach ($this->items as $key => $item) {
            if ($callback($item, $key) === false) {
                break;
            }
        }

        return $this;
    }

    /**
     * Passes the collection to `$callback` and returns the callback's result.
     *
     * @template TPipe
     *
     * @param callable(static<TKey, TValue>): TPipe $callback
     *
     * @return TPipe
     */
    public function pipe(callable $callback): mixed
    {
        return $callback(new static($this->items));
    }

    /**
     * Applies `$callback` when `$condition` holds (otherwise `$otherwise`, if given).
     *
     * @param callable(static<TKey, TValue>): static<TKey, TValue>      $callback
     * @param (callable(static<TKey, TValue>): static<TKey, TValue>)|null $otherwise
     *
     * @return static<TKey, TValue>
     */
    public function when(bool $condition, callable $callback, ?callable $otherwise = null): static
    {
        $self = new static($this->items);
        if ($condition) {
            return $callback($self);
        }

        return $otherwise !== null ? $otherwise($self) : $self;
    }

    /**
     * Items converted for JSON: arrays of arrays, objects with `toArray()`/`jsonSerialize()`.
     *
     * @return array<TKey, mixed>
     */
    public function toArray(): array
    {
        return array_map(static function (mixed $item): mixed {
            if ($item instanceof self) {
                return $item->toArray();
            }
            if (is_object($item) && method_exists($item, 'toArray')) {
                return $item->toArray();
            }

            return $item instanceof JsonSerializable ? $item->jsonSerialize() : $item;
        }, $this->items);
    }

    /** @return array<TKey, mixed> */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    public function isList(): bool
    {
        return array_is_list($this->items);
    }

    /** @return Traversable<TKey, TValue> */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->items);
    }

    public function offsetExists(mixed $offset): bool
    {
        return isset($this->items[$offset]);
    }

    public function offsetGet(mixed $offset): mixed
    {
        return $this->items[$offset];
    }

    /** Collections are immutable: use put() / push(), which return a new collection. */
    public function offsetSet(mixed $offset, mixed $value): void
    {
        throw new LogicException('Collections are immutable; use put() or push()');
    }

    /** Collections are immutable: use forget(), which returns a new collection. */
    public function offsetUnset(mixed $offset): void
    {
        throw new LogicException('Collections are immutable; use forget()');
    }

    // --- helpers ------------------------------------------------------------------------------

    /**
     * Reads `$key` (dot notation) from arrays, collections, public properties and `__get`.
     */
    public static function dataGet(mixed $target, string $key, mixed $default = null): mixed
    {
        foreach (explode('.', $key) as $segment) {
            if ($target instanceof self) {
                $target = $target->items;
            }
            if (is_array($target)) {
                if (!array_key_exists($segment, $target)) {
                    return $default;
                }
                $target = $target[$segment];
            } elseif (is_object($target)) {
                $public = get_object_vars($target); // public properties only, from this scope
                if (array_key_exists($segment, $public)) {
                    $target = $public[$segment];
                } elseif (method_exists($target, '__get')) {
                    $target = $target->__get($segment); // model attributes
                } else {
                    return $default;
                }
            } else {
                return $default;
            }
        }

        return $target;
    }

    /**
     * @param string|(callable(TValue): mixed)|null $by
     *
     * @return list<mixed>
     */
    private function extract(string|callable|null $by): array
    {
        $values = [];
        foreach ($this->items as $item) {
            $value = $by === null ? $item : (is_string($by) ? self::dataGet($item, $by) : $by($item));
            if ($value !== null) {
                $values[] = $value;
            }
        }

        return $values;
    }

    /**
     * @param string|(callable(TValue): mixed)|null $by
     *
     * @return list<int|float>
     */
    private function numbers(string|callable|null $by): array
    {
        $numbers = [];
        foreach ($this->extract($by) as $value) {
            if (is_int($value) || is_float($value)) {
                $numbers[] = $value;
            } elseif (is_numeric($value)) {
                $numbers[] = $value + 0;
            }
        }

        return $numbers;
    }

    private static function arrayKey(mixed $value): int|string
    {
        return match (true) {
            is_int($value), is_string($value) => $value,
            is_bool($value) => (int) $value,
            $value === null => '',
            $value instanceof BackedEnum => $value->value,
            $value instanceof Stringable, is_float($value) => (string) $value,
            default => serialize($value),
        };
    }

    /**
     * @param array<array-key, mixed> $items
     *
     * @return list<mixed>
     */
    private static function flattenArray(array $items, int $depth): array
    {
        $result = [];
        foreach ($items as $item) {
            if ($item instanceof self) {
                $item = $item->all();
            }
            if (!is_array($item)) {
                $result[] = $item;
            } elseif ($depth === 1) {
                foreach ($item as $value) {
                    $result[] = $value;
                }
            } else {
                foreach (self::flattenArray($item, $depth > 0 ? $depth - 1 : 0) as $value) {
                    $result[] = $value;
                }
            }
        }

        return $result;
    }

    /**
     * @template TArrayKey of array-key
     * @template TArrayValue
     *
     * @param iterable<TArrayKey, TArrayValue> $items
     *
     * @return array<TArrayKey, TArrayValue>
     */
    private static function toArrayOf(iterable $items): array
    {
        if (is_array($items)) {
            return $items;
        }
        $out = [];
        foreach ($items as $key => $value) {
            $out[$key] = $value;
        }

        return $out;
    }
}
