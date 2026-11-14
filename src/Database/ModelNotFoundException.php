<?php

declare(strict_types=1);

namespace Omnitrix\Database;

use Omnitrix\Core\Http\Status;
use Omnitrix\Http\Exception\HttpException;

use function array_map;
use function implode;
use function strrchr;
use function substr;

/** No row matched `findOrFail()` / `firstOrFail()`. Answered as `404` when it reaches the kernel. */
final class ModelNotFoundException extends HttpException
{
    /**
     * @param class-string             $model
     * @param list<int|string|null>    $ids
     */
    public function __construct(public readonly string $model, public readonly array $ids = [])
    {
        $base = strrchr($model, '\\');
        $name = $base === false ? $model : substr($base, 1);
        parent::__construct(Status::NOT_FOUND, $name . ' not found', [], $ids === [] ? [] : ['id' => implode(', ', array_map(static fn(int|string|null $id): string => (string) $id, $ids))]);
    }
}
