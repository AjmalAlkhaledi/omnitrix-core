<?php

declare(strict_types=1);

namespace Omnitrix\Auth\Access;

use Omnitrix\Http\Exception\HttpException;

/** A denied ability: answered 403 (or 404 for denyAsNotFound()). */
final class AuthorizationException extends HttpException
{
    public static function from(AuthorizationResponse $response): self
    {
        return new self($response->status, $response->message ?? ($response->status === 404 ? 'Not Found' : 'This action is unauthorized'));
    }
}
