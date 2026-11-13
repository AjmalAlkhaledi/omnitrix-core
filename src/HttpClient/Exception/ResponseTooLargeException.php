<?php

declare(strict_types=1);

namespace Omnitrix\Core\HttpClient\Exception;

/** The response body exceeded max_response_size. */
class ResponseTooLargeException extends HttpClientException {}
