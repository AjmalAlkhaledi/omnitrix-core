<?php

declare(strict_types=1);

namespace Omnitrix\Core\HttpClient\Exception;

use Omnitrix\Core\HttpClient\Response;

/** A 4xx/5xx response, raised by {@see Response::throw()}; the response is attached. */
final class RequestException extends HttpClientException
{
    public function __construct(public readonly Response $response)
    {
        parent::__construct("HTTP {$response->status()} from {$response->url()}", $response->status());
    }
}
