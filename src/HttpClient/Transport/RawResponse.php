<?php

declare(strict_types=1);

namespace Omnitrix\Core\HttpClient\Transport;

/** What a transport returns for one exchange. */
final readonly class RawResponse
{
    /**
     * @param array<string, string> $headers lower-case names
     * @param list<string>          $setCookies
     */
    public function __construct(
        public int $status,
        public array $headers,
        public string $body,
        public array $setCookies = [],
        public string $protocol = '1.1',
    ) {}
}
