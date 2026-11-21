<?php

declare(strict_types=1);

namespace Omnitrix\Tests\Unit\Http;

use Omnitrix\Application;
use Omnitrix\Core\Database\Cache\CacheUnavailableException;
use Omnitrix\Core\Http\Server\IncomingRequest;
use Omnitrix\Core\Http\Server\ResponseWriter;
use Omnitrix\Core\Runtime\Config\InvalidConfigurationException;
use Omnitrix\Core\Runtime\Context\RequestContext;
use Omnitrix\Http\ErrorResponseFactory;
use Omnitrix\Http\Exception\HttpException;
use Omnitrix\Http\HttpKernel;
use Omnitrix\Http\Middleware\Next;
use Omnitrix\Http\Request;
use Omnitrix\Http\Response;
use Omnitrix\Routing\Router;
use Omnitrix\Tests\Support\MemoryResponseSink;
use Omnitrix\Tests\Support\RecordingExceptionHandler;
use Omnitrix\Tests\Support\TestCase;
use OpenSwoole\Http\Request as SwooleRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use Psr\Log\NullLogger;
use RuntimeException;
use stdClass;

use function strlen;

#[CoversClass(HttpKernel::class)]
#[CoversClass(ErrorResponseFactory::class)]
#[CoversClass(Application::class)]
final class HttpKernelTest extends TestCase
{
    private RecordingExceptionHandler $exceptions;

    private Application $app;

    protected function setUp(): void
    {
        parent::setUp();
        $this->exceptions = new RecordingExceptionHandler();
        $this->app = Application::create(['server' => ['port' => 18999, 'workers' => 1], 'http' => ['request_timeout' => 5], 'debug' => false], new NullLogger(), $this->exceptions);
        $this->app->middleware(static fn(Request $r, Next $next): Response => $next($r)->header('X-Global', 'yes'));
        $this->app->get('/users/{id:\d+}', static fn(Request $r): Response => Response::json(['id' => $r->routeInt('id'), 'name' => $r->routeName()]))->name('users.show');
        $this->app->get('/array', static fn(): array => ['a' => 1]);
        $this->app->get('/string', static fn(): string => 'plain');
        $this->app->get('/null', static fn() => null);
        $this->app->get('/object', static fn(): object => new stdClass());
        $this->app->get('/http-error', static function (): never {
            throw new HttpException(409, 'Conflict here', ['X-Hint' => 'retry'], ['field' => 'x']);
        });
        $this->app->get('/boom', static function (): never {
            throw new RuntimeException('db password=secret');
        });
        $this->app->post('/echo', static fn(Request $r): Response => Response::json($r->jsonObject()));
        $this->app->group('/admin', static function (Router $r): void {
            $r->get('/secret', static fn(): string => 'admin');
        }, [static fn(Request $r, Next $next): Response => $r->header('x-admin') === '1' ? $next($r) : Response::json(['error' => 'forbidden'], 403)]);
    }

    /** @return array{int, array<string, string>, string} */
    private function send(string $method, string $target, string $body = '', string $contentType = 'application/json'): array
    {
        $raw = SwooleRequest::create(['parse_body' => true]);
        self::assertInstanceOf(SwooleRequest::class, $raw);
        $head = "{$method} {$target} HTTP/1.1\r\nHost: h\r\n" . ($body !== '' ? "Content-Type: {$contentType}\r\nContent-Length: " . strlen($body) . "\r\n" : '') . "\r\n";
        $raw->parse($head . $body);
        $sink = new MemoryResponseSink();
        $kernel = $this->app->makeKernel();
        $kernel(new IncomingRequest($raw), new ResponseWriter($sink), RequestContext::create());

        return [(int) $sink->status, $sink->headers, $sink->body()];
    }

    public function testDispatchesThroughGlobalMiddlewareToHandlersAndNormalisesReturnValues(): void
    {
        [$status, $headers, $body] = $this->send('GET', '/users/42');
        self::assertSame(200, $status);
        self::assertSame('yes', $headers['X-Global']);
        self::assertSame('{"id":42,"name":"users.show"}', $body);

        self::assertSame([200, '{"a":1}'], [$this->send('GET', '/array')[0], $this->send('GET', '/array')[2]]);
        [$status, $headers, $body] = $this->send('GET', '/string');
        self::assertSame('text/plain; charset=utf-8', $headers['Content-Type']);
        self::assertSame('plain', $body);
        self::assertSame(204, $this->send('GET', '/null')[0]);

        [$status, , $body] = $this->send('POST', '/echo', '{"k":"v"}');
        self::assertSame('{"k":"v"}', $body);
    }

    public function testErrorsBecomeJsonResponsesWithoutLeakingInternals(): void
    {
        [$status, $headers, $body] = $this->send('GET', '/nope');
        self::assertSame(404, $status);
        self::assertStringContainsString('"status":404', $body);
        self::assertStringContainsString('"request_id":"', $body);

        [$status, $headers] = $this->send('DELETE', '/users/1');
        self::assertSame(405, $status);
        self::assertSame('GET, HEAD', $headers['Allow']);

        [$status, $headers, $body] = $this->send('GET', '/http-error');
        self::assertSame(409, $status);
        self::assertSame('retry', $headers['X-Hint']);
        self::assertStringContainsString('"message":"Conflict here","field":"x"', $body);
        self::assertSame([], $this->exceptions->records, '4xx are not reported');

        [$status, , $body] = $this->send('GET', '/boom');
        self::assertSame(500, $status);
        self::assertStringNotContainsString('secret', $body);
        self::assertStringContainsString('"message":"Internal Server Error"', $body);
        $record = $this->exceptions->first();
        self::assertNotNull($record);
        self::assertSame('http.handler', $record['context']['origin']);

        [$status, , $body] = $this->send('GET', '/object');
        self::assertSame(500, $status, 'unsupported return type is a programming error');

        [$status, , $body] = $this->send('POST', '/echo', '{bad');
        self::assertSame(400, $status);
        self::assertStringContainsString('Malformed JSON', $body);
    }

    public function testGroupMiddlewareShortCircuits(): void
    {
        self::assertSame(403, $this->send('GET', '/admin/secret')[0]);
        $raw = SwooleRequest::create([]);
        self::assertInstanceOf(SwooleRequest::class, $raw);
        $raw->parse("GET /admin/secret HTTP/1.1\r\nHost: h\r\nX-Admin: 1\r\n\r\n");
        $sink = new MemoryResponseSink();
        ($this->app->makeKernel())(new IncomingRequest($raw), new ResponseWriter($sink), RequestContext::create());
        self::assertSame('admin', $sink->body());
        self::assertSame('yes', $sink->headers['X-Global']);
    }

    public function testDebugModeAddsExceptionDetails(): void
    {
        $factory = new ErrorResponseFactory(true);
        $response = $factory->fromThrowable(new RuntimeException('why'), 'rid');
        self::assertTrue($factory->isDebug());
        self::assertSame(500, $response->getStatus());
        self::assertStringContainsString('"exception":"RuntimeException"', $response->getBody());
        self::assertStringContainsString('"detail":"why"', $response->getBody());
        self::assertStringContainsString('"trace":[', $response->getBody());
    }

    public function testAWriteRefusedByAnUnreachableRowCacheIsRetryable(): void
    {
        $response = (new ErrorResponseFactory(false))->fromThrowable(new CacheUnavailableException('internal detail'), 'rid');
        self::assertSame(503, $response->getStatus());
        self::assertSame('1', $response->getHeader('Retry-After'));
        self::assertStringContainsString('"message":"Service Unavailable"', $response->getBody());
        self::assertStringNotContainsString('internal detail', $response->getBody(), 'no internals without debug');
    }

    public function testApplicationValidatesItsOwnSections(): void
    {
        try {
            Application::create(['http' => 'x']);
            self::fail('invalid http section accepted');
        } catch (InvalidConfigurationException $e) {
            self::assertStringContainsString("'http'", $e->getMessage());
        }
        $this->expectException(InvalidConfigurationException::class);
        Application::create(['debug' => 'yes']);
    }
}
