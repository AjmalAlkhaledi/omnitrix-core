<?php

declare(strict_types=1);

namespace Omnitrix\Core\HttpClient\Transport;

use Omnitrix\Core\HttpClient\Uri;

/** One request as handed to a transport (after defaults, auth, query and body were applied). */
final readonly class Exchange
{
    /** @param array<string, string> $headers canonical-case names */
    public function __construct(
        public string $method,
        public Uri $uri,
        public array $headers,
        public string $body,
        public float $connectTimeout,
        public float $timeout,
        public bool $verifyTls,
        public ?string $caFile,
        public bool $http2,
        /** When set, the body is streamed into this file and RawResponse::$body stays empty. */
        public ?string $downloadTo = null,
    ) {}
}
