<?php

declare(strict_types=1);

namespace Omnitrix\Core\HttpClient\Transport;

use Omnitrix\Core\HttpClient\Exception\HttpClientException;

/** The coroutine performing the request was cancelled (timeout group, request abort). */
final class CancelledOutboundException extends HttpClientException {}
