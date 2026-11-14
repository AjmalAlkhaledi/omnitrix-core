<?php

declare(strict_types=1);

namespace Omnitrix\Core\HttpClient\Exception;

/** The redirect chain exceeded max_redirects. */
class TooManyRedirectsException extends HttpClientException {}
