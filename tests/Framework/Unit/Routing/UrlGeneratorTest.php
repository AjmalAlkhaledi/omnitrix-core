<?php

declare(strict_types=1);

namespace Omnitrix\Tests\Unit\Routing;

use InvalidArgumentException;
use LogicException;
use Omnitrix\Core\Container\Container;
use Omnitrix\Core\Http\Server\IncomingRequest;
use Omnitrix\Core\Runtime\Context\RequestContext;
use Omnitrix\Encryption\AesEncrypter;
use Omnitrix\Facades\Facade;
use Omnitrix\Http\Middleware\Pipeline;
use Omnitrix\Http\Request;
use Omnitrix\Http\Response;
use Omnitrix\Routing\Router;
use Omnitrix\Routing\UrlGenerator;
use Omnitrix\Routing\ValidateSignature;
use Omnitrix\Tests\Support\TestCase;
use OpenSwoole\Http\Request as SwooleRequest;
use PHPUnit\Framework\Attributes\CoversClass;

use function parse_url;
use function str_replace;

#[CoversClass(UrlGenerator::class)]
#[CoversClass(ValidateSignature::class)]
final class UrlGeneratorTest extends TestCase
{
    private function generator(string $base = 'https://api.example.com/'): UrlGenerator
    {
        $router = new Router();
        $router->get('/api/users/{id:\d+}', static fn() => null)->name('users.show');
        $router->get('/api/teams/{team}/members/{member}', static fn() => null)->name('members.show');
        $router->get('/email/verify/{id:\d+}/{hash}', static fn() => null)->name('verification.verify');

        return new UrlGenerator($router, $base, AesEncrypter::generateKey());
    }

    public function testPathsAndNamedRoutes(): void
    {
        $url = $this->generator();
        self::assertSame('https://api.example.com/health', $url->to('/health'));
        self::assertSame('https://api.example.com/search?q=a%20b&page=2', $url->to('search', ['q' => 'a b', 'page' => 2]));
        self::assertSame('https://api.example.com/api/users/10?tab=posts', $url->route('users.show', ['id' => 10, 'tab' => 'posts']));
        self::assertSame('https://api.example.com/api/teams/a%2Fb/members/7', $url->route('members.show', ['team' => 'a/b', 'member' => 7]), 'values are encoded');
        self::assertSame('/api/users/3', (new UrlGenerator((function (): Router {
            $r = new Router();
            $r->get('/api/users/{id}', static fn() => null)->name('u');

            return $r;
        })()))->route('u', ['id' => 3]), 'relative without app.url');

        foreach ([['users.show', []], ['users.show', ['id' => 'abc']], ['nope', []]] as [$name, $parameters]) {
            try {
                $url->route($name, $parameters);
                self::fail("{$name} accepted");
            } catch (InvalidArgumentException) {
                self::assertNotSame('', $name);
            }
        }
    }

    public function testSignedUrlsRejectChangesAndExpire(): void
    {
        $url = $this->generator();
        $signed = $url->signedRoute('verification.verify', ['id' => 10, 'hash' => 'abc', 'ref' => 'mail']);
        self::assertTrue($url->hasValidSignature(self::request($signed)));
        self::assertFalse($url->hasValidSignature(self::request(str_replace('/10/', '/11/', $signed))), 'a changed path is rejected');
        self::assertFalse($url->hasValidSignature(self::request(str_replace('ref=mail', 'ref=web', $signed))), 'a changed query is rejected');
        self::assertFalse($url->hasValidSignature(self::request('/email/verify/10/abc')), 'no signature');
        self::assertFalse($this->generator()->hasValidSignature(self::request($signed)), 'another key does not accept it');

        $temporary = $url->temporarySignedRoute('users.show', 60, ['id' => 5]);
        self::assertTrue($url->hasValidSignature(self::request($temporary)));
        $expired = $url->signedRoute('users.show', ['id' => 5], -10);
        self::assertTrue($url->hasCorrectSignature(self::request($expired)));
        self::assertTrue($url->signatureHasExpired(self::request($expired)));
        self::assertFalse($url->hasValidSignature(self::request($expired)));

        $this->expectException(LogicException::class);
        (new UrlGenerator(new Router()))->signedRoute('x');
    }

    public function testTheMiddlewareAnswers403ForInvalidOrExpiredLinks(): void
    {
        $url = $this->generator();
        $container = new Container();
        $container->instance(UrlGenerator::class, $url);
        Facade::setContainer($container);
        try {
            $pipeline = Pipeline::compile([new ValidateSignature()], static fn(): Response => Response::json(['ok' => true]));
            self::assertSame(200, $pipeline(self::request($url->signedRoute('users.show', ['id' => 1])))->getStatus());
            self::assertSame(403, $pipeline(self::request('/api/users/1?signature=forged'))->getStatus());
            $expired = $pipeline(self::request($url->signedRoute('users.show', ['id' => 1], -1)));
            self::assertSame(403, $expired->getStatus());
            self::assertStringContainsString('expired', $expired->getBody());
        } finally {
            Facade::setContainer(null);
        }
    }

    private static function request(string $url): Request
    {
        $parts = parse_url($url);
        $target = ($parts['path'] ?? '/') . (isset($parts['query']) ? '?' . $parts['query'] : '');
        $raw = SwooleRequest::create([]);
        self::assertInstanceOf(SwooleRequest::class, $raw);
        $raw->parse("GET {$target} HTTP/1.1\r\nHost: h\r\n\r\n");

        return new Request(new IncomingRequest($raw), RequestContext::create());
    }
}
