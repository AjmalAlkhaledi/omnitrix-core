<?php

declare(strict_types=1);

namespace Omnitrix\Tests\Integration\Session;

use Omnitrix\Contracts\Encrypter;
use Omnitrix\Contracts\SessionHandler;
use Omnitrix\Core\Container\Container;
use Omnitrix\Core\Http\Server\IncomingRequest;
use Omnitrix\Core\Redis\RedisClient;
use Omnitrix\Core\Redis\RedisConfig;
use Omnitrix\Core\Runtime\Context\ContextStorage;
use Omnitrix\Core\Runtime\Context\RequestContext;
use Omnitrix\Encryption\AesEncrypter;
use Omnitrix\Facades\Facade;
use Omnitrix\Facades\Session;
use Omnitrix\Http\Cookie;
use Omnitrix\Http\Middleware\EncryptCookies;
use Omnitrix\Http\Middleware\Pipeline;
use Omnitrix\Http\Request;
use Omnitrix\Http\Response;
use Omnitrix\Security\VerifyCsrfToken;
use Omnitrix\Session\ArraySessionHandler;
use Omnitrix\Session\RedisSessionHandler;
use Omnitrix\Session\SessionConfig;
use Omnitrix\Session\StartSession;
use Omnitrix\Session\Store;
use Omnitrix\Tests\Support\Services;
use Omnitrix\Tests\Support\TestCase;
use OpenSwoole\Http\Request as SwooleRequest;
use PHPUnit\Framework\Attributes\CoversClass;

use function implode;
use function is_string;
use function rawurlencode;
use function strlen;
use function uniqid;

/** Sessions, CSRF and encrypted cookies through the middleware pipeline, request after request. */
#[CoversClass(StartSession::class)]
#[CoversClass(Store::class)]
#[CoversClass(VerifyCsrfToken::class)]
#[CoversClass(EncryptCookies::class)]
final class SessionTest extends TestCase
{
    /** @var array<string, string> cookies the "browser" keeps */
    private array $jar = [];

    private ?RedisClient $redis = null;

    protected function tearDown(): void
    {
        Facade::setContainer(null);
        $this->redis?->close();
        parent::tearDown();
    }

    public function testFlashDataAndAttributesAcrossRequestsForEveryDriver(): void
    {
        foreach (['array', 'cookie', 'redis'] as $driver) {
            $this->jar = [];
            $this->configure($driver);
            $pipeline = Pipeline::compile([new StartSession()], static function (Request $r): Response {
                $action = $r->queryParam('do');
                if ($action === 'login') {
                    Session::put('user', 10);
                    Session::flash('status', 'Welcome');
                }

                return Response::json(['user' => Session::get('user'), 'status' => Session::get('status')]);
            });
            self::assertSame('{"user":null,"status":null}', $this->send($pipeline, 'GET', '/?do=nothing')->getBody(), $driver);
            self::assertSame([], $this->jar, "{$driver}: an unused session sends no cookie");
            $this->send($pipeline, 'GET', '/?do=login');
            self::assertArrayHasKey('omnitrix_session', $this->jar, $driver);
            self::assertSame('{"user":10,"status":"Welcome"}', $this->send($pipeline, 'GET', '/')->getBody(), "{$driver}: flash data reaches the next request");
            self::assertSame('{"user":10,"status":null}', $this->send($pipeline, 'GET', '/')->getBody(), "{$driver}: and only that one");
        }
    }

    public function testRegenerationAndInvalidation(): void
    {
        $handler = $this->configure('array');
        $pipeline = Pipeline::compile([new StartSession()], static function (Request $r): Response {
            match ($r->queryParam('do')) {
                'login' => (static function (): void {
                    Session::put('user', 1);
                    Session::regenerate();
                })(),
                'logout' => Session::invalidate(),
                default => null,
            };

            return Response::json(['user' => Session::get('user')]);
        });
        $this->send($pipeline, 'GET', '/?do=start');
        $this->send($pipeline, 'GET', '/?do=login');
        $first = $this->jar['omnitrix_session'] ?? '';
        $this->send($pipeline, 'GET', '/?do=login');
        $second = $this->jar['omnitrix_session'] ?? '';
        self::assertNotSame($first, $second, 'a new id after regenerate()');
        self::assertNull($handler->read($first), 'the old id is destroyed');
        self::assertSame('{"user":1}', $this->send($pipeline, 'GET', '/')->getBody());
        $this->send($pipeline, 'GET', '/?do=logout');
        self::assertSame('{"user":null}', $this->send($pipeline, 'GET', '/')->getBody(), 'invalidate() forgets everything');
        self::assertNull($handler->read($second));
    }

    public function testCsrfTokensAreRequiredForWrites(): void
    {
        $this->configure('array');
        $pipeline = Pipeline::compile([new StartSession(), new VerifyCsrfToken(['webhooks/*'])], static fn(): Response => Response::json(['ok' => true]));
        $this->send($pipeline, 'GET', '/form');
        $token = $this->jar['XSRF-TOKEN'] ?? '';
        self::assertNotSame('', $token, 'an XSRF-TOKEN cookie for JavaScript clients');
        self::assertSame(419, $this->send($pipeline, 'POST', '/orders')->getStatus());
        self::assertSame(419, $this->send($pipeline, 'POST', '/orders', ['X-CSRF-TOKEN' => 'forged'])->getStatus());
        self::assertSame(200, $this->send($pipeline, 'POST', '/orders', ['X-CSRF-TOKEN' => $token])->getStatus());
        self::assertSame(200, $this->send($pipeline, 'DELETE', '/orders/1', ['X-XSRF-TOKEN' => $token])->getStatus());
        self::assertSame(200, $this->send($pipeline, 'POST', '/orders', [], '_token=' . rawurlencode($token))->getStatus(), 'form field');
        self::assertSame(200, $this->send($pipeline, 'POST', '/webhooks/stripe')->getStatus(), 'excepted paths');
        $this->jar = [];
        self::assertSame(200, $this->send($pipeline, 'POST', '/orders', ['Authorization' => 'Bearer abc'])->getStatus(), 'bearer requests cannot be forged by a browser');
    }

    public function testCookiesAreEncryptedAndTamperingIsIgnored(): void
    {
        $this->configure('array');
        $pipeline = Pipeline::compile([new EncryptCookies(['plain'])], static fn(Request $r): Response => Response::json(['theme' => $r->cookie('theme'), 'plain' => $r->cookie('plain')])
            ->cookie(new Cookie('theme', 'dark'))
            ->cookie(new Cookie('plain', 'visible')));
        $this->send($pipeline, 'GET', '/');
        self::assertNotSame('dark', $this->jar['theme'] ?? null, 'the client sees ciphertext');
        self::assertSame('visible', $this->jar['plain'] ?? null);
        self::assertSame('{"theme":"dark","plain":"visible"}', $this->send($pipeline, 'GET', '/')->getBody(), 'the application sees plain values');
        $this->jar['theme'] = 'dark';
        self::assertSame('{"theme":null,"plain":"visible"}', $this->send($pipeline, 'GET', '/')->getBody(), 'an unencrypted (forged) value is dropped');
    }

    private function configure(string $driver): SessionHandler
    {
        $container = new Container();
        $container->instance(Encrypter::class, new AesEncrypter(AesEncrypter::generateKey()));
        $container->instance(SessionConfig::class, SessionConfig::fromArray(['driver' => $driver], true));
        $handler = new ArraySessionHandler();
        if ($driver === 'redis') {
            $this->redis = new RedisClient(RedisConfig::fromArray(['host' => Services::redisHost(), 'port' => Services::redisPort(), 'prefix' => 'session-test-' . uniqid() . ':']));
            $handler = new RedisSessionHandler($this->redis);
        }
        $container->instance(SessionHandler::class, $handler);
        Facade::setContainer($container);

        return $handler;
    }

    /**
     * @param callable(Request): Response $pipeline
     * @param array<string, string>        $headers
     */
    private function send(callable $pipeline, string $method, string $target, array $headers = [], string $body = ''): Response
    {
        $lines = ["{$method} {$target} HTTP/1.1", 'Host: h'];
        if ($this->jar !== []) {
            $pairs = [];
            foreach ($this->jar as $name => $value) {
                $pairs[] = $name . '=' . rawurlencode($value);
            }
            $lines[] = 'Cookie: ' . implode('; ', $pairs);
        }
        foreach ($headers as $name => $value) {
            $lines[] = "{$name}: {$value}";
        }
        if ($body !== '') {
            $lines[] = 'Content-Type: application/x-www-form-urlencoded';
            $lines[] = 'Content-Length: ' . strlen($body);
        }
        $raw = SwooleRequest::create([]);
        self::assertInstanceOf(SwooleRequest::class, $raw);
        $raw->parse(implode("\r\n", $lines) . "\r\n\r\n" . $body);
        $context = RequestContext::create();
        ContextStorage::bind($context);
        try {
            $response = $pipeline(new Request(new IncomingRequest($raw), $context));
        } finally {
            ContextStorage::unbind();
        }
        foreach ($response->getCookies() as $cookie) {
            if ($cookie->expires === 1) {
                unset($this->jar[$cookie->name]);
            } elseif (is_string($cookie->value)) {
                $this->jar[$cookie->name] = $cookie->value;
            }
        }

        return $response;
    }
}
