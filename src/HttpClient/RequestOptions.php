<?php

declare(strict_types=1);

namespace Omnitrix\Core\HttpClient;

/** Per-request overrides of {@see HttpClientConfig} (null = use the configured default). */
final readonly class RequestOptions
{
    public function __construct(
        public ?float $timeout = null,
        public ?float $connectTimeout = null,
        public ?int $maxRedirects = null,
        public ?int $maxResponseSize = null,
        public ?bool $verifyTls = null,
        public ?string $caFile = null,
        public ?int $retries = null,
        public ?bool $retryOnTimeout = null,
        public ?bool $http2 = null,
        public ?string $downloadTo = null,
    ) {}
}
