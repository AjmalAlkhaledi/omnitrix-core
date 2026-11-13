<?php

declare(strict_types=1);

namespace Omnitrix\Core\HttpClient\Exception;

/** Could not connect, or the connection was reset/closed before a response arrived (refused, reset, server closed). */
class ConnectionException extends HttpClientException {}
