<?php

declare(strict_types=1);

namespace Omnitrix\Core\HttpClient\Transport;

use Omnitrix\Core\Concurrency\CancellationToken;

/**
 * Performs exactly one HTTP exchange. Transports throw the HttpClient exception hierarchy for
 * connection, DNS, TLS and timeout failures and never follow redirects or retry themselves.
 */
interface TransportInterface
{
    public function exchange(Exchange $exchange, ?CancellationToken $token = null): RawResponse;

    /** @return array<string, mixed> diagnostics (pool sizes etc.) */
    public function stats(): array;

    public function close(): void;
}
