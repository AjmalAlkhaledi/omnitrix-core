<?php

declare(strict_types=1);

namespace Omnitrix\Validation;

use Omnitrix\Http\Exception\HttpException;

/** 422 with field errors: `{"error": {"status": 422, "message": "...", "errors": {field: [messages]}}}`. */
final class ValidationException extends HttpException
{
    /** @param array<string, list<string>> $errors */
    public function __construct(public readonly array $errors)
    {
        parent::__construct(422, 'The given data was invalid', [], ['errors' => $errors]);
    }
}
