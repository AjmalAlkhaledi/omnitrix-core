<?php

declare(strict_types=1);

namespace Omnitrix\Core\Tests\Support;

use Closure;
use Omnitrix\Core\Concurrency\CancellationToken;
use Omnitrix\Core\HttpClient\Transport\Exchange;
use Omnitrix\Core\HttpClient\Transport\RawResponse;
use Omnitrix\Core\HttpClient\Transport\TransportInterface;
use Throwable;

use function count;

/** Scripted transport: each exchange pops the next scripted response or throws the scripted exception. */
final class FakeTransport implements TransportInterface
{
    /** @var list<Exchange> */
    public array $exchanges = [];

    /** @var list<RawResponse|Throwable|Closure(Exchange): RawResponse> */
    private array $script;

    public bool $closed = false;

    /** @param list<RawResponse|Throwable|Closure(Exchange): RawResponse> $script */
    public function __construct(array $script)
    {
        $this->script = $script;
    }

    public function exchange(Exchange $exchange, ?CancellationToken $token = null): RawResponse
    {
        $this->exchanges[] = $exchange;
        $next = array_shift($this->script) ?? new RawResponse(200, [], 'default');
        if ($next instanceof Throwable) {
            throw $next;
        }
        if ($next instanceof Closure) {
            return $next($exchange);
        }

        return $next;
    }

    public function stats(): array
    {
        return ['exchanges' => count($this->exchanges)];
    }

    public function close(): void
    {
        $this->closed = true;
    }
}
