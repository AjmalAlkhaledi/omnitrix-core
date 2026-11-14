<?php

declare(strict_types=1);

namespace Omnitrix\Database;

use JsonSerializable;

use function ceil;
use function max;

/**
 * One page of models with totals. Serialises to
 * `{"data": [...], "meta": {"total", "per_page", "current_page", "last_page"}}`.
 *
 * @template TModel of Model
 */
final readonly class Paginator implements JsonSerializable
{
    public int $lastPage;

    /** @param Collection<TModel> $items */
    public function __construct(public Collection $items, public int $total, public int $perPage, public int $currentPage)
    {
        $this->lastPage = max(1, (int) ceil($total / max(1, $perPage)));
    }

    public function hasMorePages(): bool
    {
        return $this->currentPage < $this->lastPage;
    }

    /** @return array{data: list<array<string, mixed>>, meta: array{total: int, per_page: int, current_page: int, last_page: int}} */
    public function toArray(): array
    {
        return [
            'data' => $this->items->toArray(),
            'meta' => ['total' => $this->total, 'per_page' => $this->perPage, 'current_page' => $this->currentPage, 'last_page' => $this->lastPage],
        ];
    }

    /** @return array{data: list<array<string, mixed>>, meta: array{total: int, per_page: int, current_page: int, last_page: int}} */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
