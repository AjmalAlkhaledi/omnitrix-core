<?php

declare(strict_types=1);

namespace Omnitrix\Tests\Unit\Production;

use Omnitrix\Core\Http\Server\IncomingRequest;
use Omnitrix\Core\Runtime\Context\RequestContext;
use Omnitrix\Http\Middleware\Pipeline;
use Omnitrix\Http\Request;
use Omnitrix\Http\Response;
use Omnitrix\RateLimit\RateLimit;
use Omnitrix\RateLimit\RateLimiterInterface;
use Omnitrix\RateLimit\RateLimitResult;
use Omnitrix\RateLimit\TableRateLimiter;
use Omnitrix\Security\Authorize;
use Omnitrix\Security\BearerTokenAuth;
use Omnitrix\Security\Identity;
use Omnitrix\Security\SecurityHeaders;
use Omnitrix\Tests\Support\TestCase;
use OpenSwoole\Http\Request as SwooleRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use RuntimeException;
use Throwable;

#[CoversClass(BearerTokenAuth::class)]
#[CoversClass(Authorize::class)]
#[CoversClass(Identity::class)]
#[CoversClass(SecurityHeaders::class)]
#[CoversClass(RateLimit::class)]
#[CoversClass(TableRateLimiter::class)]
#[CoversClass(RateLimitResult::class)]
final class SecurityAndRateLimitTest extends TestCase
{
    private static function request(?string $token = null): Request
    {
        $raw = SwooleRequest::create([]);
        self::assertInstanceOf(SwooleRequest::class, $raw);
        $raw->parse("GET /x HTTP/1.1\r\nHost: h\r\n" . ($token !== null ? "Authorization: Bearer {$token}\r\n" : '') . "\r\n");

        return new Request(new IncomingRequest($raw), RequestContext::create());
    }

    private static function auth(): BearerTokenAuth
    {
        return new BearerTokenAuth(static fn(string $token): ?Identity => match ($token) {
            'admin' => new Identity('1', ['*']),
            'reader' => new Identity('2', ['orders.read']),
            default => null,
        });
    }

    public function testAuthenticationAndAuthorization(): void
    {
        $handler = static fn(Request $r): Response => Response::json(['who' => BearerTokenAuth::identity($r)?->id]);
        $pipeline = Pipeline::compile([self::auth(), Authorize::ability('orders.read')], $handler);

        $anon = $pipeline(self::request());
        self::assertSame(401, $anon->getStatus());
        self::assertSame('Bearer', $anon->getHeader('WWW-Authenticate'));
        $bad = $pipeline(self::request('forged'));
        self::assertSame(401, $bad->getStatus());
        self::assertSame('Bearer error="invalid_token"', $bad->getHeader('WWW-Authenticate'));
        self::assertSame('{"who":"2"}', $pipeline(self::request('reader'))->getBody());

        $write = Pipeline::compile([self::auth(), Authorize::ability('orders.write')], $handler);
        self::assertSame(403, $write(self::request('reader'))->getStatus());
        self::assertSame(200, $write(self::request('admin'))->getStatus(), 'wildcard ability');

        $optional = Pipeline::compile([new BearerTokenAuth(static fn(): ?Identity => null, optional: true)], $handler);
        self::assertSame('{"who":null}', $optional(self::request())->getBody());

        $noAuth = Pipeline::compile([Authorize::ability('x')], $handler);
        self::assertSame(401, $noAuth(self::request('admin'))->getStatus(), 'authorize without authentication');
    }

    public function testSecurityHeadersDoNotOverrideHandlerChoices(): void
    {
        $pipeline = Pipeline::compile([new SecurityHeaders(['X-Frame-Options' => 'SAMEORIGIN'], hsts: true)], static fn(): Response => Response::text('x')->header('Cache-Control', 'max-age=60'));
        $r = $pipeline(self::request());
        self::assertSame('nosniff', $r->getHeader('X-Content-Type-Options'));
        self::assertSame('SAMEORIGIN', $r->getHeader('X-Frame-Options'));
        self::assertSame('max-age=60', $r->getHeader('Cache-Control'));
        self::assertStringContainsString('max-age=31536000', (string) $r->getHeader('Strict-Transport-Security'));
        self::assertSame("default-src 'none'; frame-ancestors 'none'", $r->getHeader('Content-Security-Policy'));
    }

    public function testTableLimiterWindowsAndMiddlewareHeaders(): void
    {
        $limiter = new TableRateLimiter(1024);
        $results = [];
        for ($i = 0; $i < 4; $i++) {
            $results[] = $limiter->hit('k', 3, 60);
        }
        self::assertSame([true, true, true, false], array_map(static fn(RateLimitResult $r): bool => $r->allowed, $results));
        self::assertSame(0, $results[3]->remaining);
        self::assertGreaterThanOrEqual(1, $results[3]->retryAfter);
        self::assertTrue($limiter->hit('other', 3, 60)->allowed, 'keys are independent');

        $pipeline = Pipeline::compile([new RateLimit(new TableRateLimiter(), 2, 60, static fn(Request $r): string => 'fixed')], static fn(): Response => Response::text('ok'));
        $first = $pipeline(self::request());
        self::assertSame('2', $first->getHeader('X-RateLimit-Limit'));
        self::assertSame('1', $first->getHeader('X-RateLimit-Remaining'));
        $pipeline(self::request());
        $blocked = $pipeline(self::request());
        self::assertSame(429, $blocked->getStatus());
        self::assertNotNull($blocked->getHeader('Retry-After'));
    }

    public function testLimiterFailureFailsOpenAndIsReported(): void
    {
        $failing = new class implements RateLimiterInterface {
            public function hit(string $key, int $limit, int $windowSeconds): RateLimitResult
            {
                throw new RuntimeException('redis down');
            }
        };
        $reported = [];
        $pipeline = Pipeline::compile([new RateLimit($failing, 1, 60, onError: static function (Throwable $e) use (&$reported): void {
            $reported[] = $e->getMessage();
        })], static fn(): Response => Response::text('ok'));
        self::assertSame(200, $pipeline(self::request())->getStatus());
        self::assertSame(['redis down'], $reported);
    }
}
