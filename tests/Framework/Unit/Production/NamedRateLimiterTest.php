<?php

declare(strict_types=1);

namespace Omnitrix\Tests\Unit\Production;

use Omnitrix\Core\Container\Container;
use Omnitrix\Core\Http\Server\IncomingRequest;
use Omnitrix\Core\Runtime\Context\RequestContext;
use Omnitrix\Facades\Facade;
use Omnitrix\Http\Middleware\Pipeline;
use Omnitrix\Http\Request;
use Omnitrix\Http\Response;
use Omnitrix\RateLimit\Limit;
use Omnitrix\RateLimit\RateLimiter;
use Omnitrix\RateLimit\RateLimitResult;
use Omnitrix\RateLimit\TableRateLimiter;
use Omnitrix\RateLimit\Throttle;
use Omnitrix\Tests\Support\TestCase;
use OpenSwoole\Http\Request as SwooleRequest;
use PHPUnit\Framework\Attributes\CoversClass;

use function is_string;
use function uniqid;

#[CoversClass(RateLimiter::class)]
#[CoversClass(Limit::class)]
#[CoversClass(Throttle::class)]
#[CoversClass(TableRateLimiter::class)]
final class NamedRateLimiterTest extends TestCase
{
    protected function tearDown(): void
    {
        Facade::setContainer(null);
        parent::tearDown();
    }

    public function testDirectLimitingAttemptsAndClearing(): void
    {
        $limiter = new RateLimiter(new TableRateLimiter(64));
        $key = 'otp:' . uniqid();
        $sent = 0;
        for ($i = 0; $i < 4; $i++) {
            $limiter->attempt($key, 3, static function () use (&$sent): void {
                $sent++;
            }, 600);
        }
        self::assertSame(3, $sent, 'the fourth attempt does not run');
        self::assertFalse($limiter->attempt($key, 3, static fn(): string => 'no', 600));
        self::assertTrue($limiter->tooManyAttempts($key, 3, 600));
        self::assertSame(0, $limiter->remaining($key, 3, 600));
        self::assertGreaterThan(0, $limiter->availableIn($key, 600));
        $limiter->clear($key, 600);
        self::assertSame(0, $limiter->attempts($key, 600));
        self::assertSame('yes', $limiter->attempt($key, 3, static fn(): string => 'yes', 600));
    }

    public function testThrottleAppliesEveryLimitOfANamedLimiter(): void
    {
        $limiter = new RateLimiter(new TableRateLimiter(64));
        $suffix = uniqid();
        $limiter->for('login', static fn(Request $r): array => [
            Limit::perMinute(2)->by('email:' . (is_string($r->queryParam('email')) ? $r->queryParam('email') : '') . $suffix),
            Limit::perMinute(3)->by('ip:' . $suffix)->response(static fn(Request $r, RateLimitResult $result): Response => Response::json(['slow down' => $result->retryAfter > 0], 429)),
        ]);
        $limiter->for('open', static fn(): ?Limit => null);
        $container = new Container();
        $container->instance(RateLimiter::class, $limiter);
        Facade::setContainer($container);
        $pipeline = Pipeline::compile([Throttle::using('login')], static fn(): Response => Response::json(['ok' => true]));

        $first = $pipeline(self::request('a@x'));
        self::assertSame(200, $first->getStatus());
        self::assertSame('1', $first->getHeader('X-RateLimit-Remaining'), 'the tightest limit is reported');
        self::assertSame(200, $pipeline(self::request('a@x'))->getStatus());
        $blocked = $pipeline(self::request('a@x'));
        self::assertSame(429, $blocked->getStatus(), 'per-email limit of 2');
        self::assertNotNull($blocked->getHeader('Retry-After'));
        self::assertSame(200, $pipeline(self::request('b@x'))->getStatus(), 'a blocked request does not count against the other limits');
        $custom = $pipeline(self::request('c@x'));
        self::assertSame(429, $custom->getStatus(), 'per-address limit of 3 across emails');
        self::assertSame('{"slow down":true}', $custom->getBody());

        $open = Pipeline::compile([Throttle::using('open')], static fn(): Response => Response::json([]));
        for ($i = 0; $i < 5; $i++) {
            self::assertSame(200, $open(self::request('d@x'))->getStatus(), 'null means no limit');
        }
    }

    private static function request(string $email): Request
    {
        $raw = SwooleRequest::create([]);
        self::assertInstanceOf(SwooleRequest::class, $raw);
        $raw->parse("GET /login?email={$email} HTTP/1.1\r\nHost: h\r\n\r\n");

        return new Request(new IncomingRequest($raw), RequestContext::create());
    }
}
