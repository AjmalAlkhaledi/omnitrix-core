<?php

declare(strict_types=1);

namespace Omnitrix\Core\HttpClient\Exception;

use RuntimeException;

/** Base class of every outbound HTTP failure. */
class HttpClientException extends RuntimeException {}
