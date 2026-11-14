<?php

declare(strict_types=1);

namespace Omnitrix\Core\Tests\Unit\HttpClient;

use InvalidArgumentException;
use Omnitrix\Core\HttpClient\Exception\HttpClientException;
use Omnitrix\Core\HttpClient\Exception\RequestException;
use Omnitrix\Core\HttpClient\HttpClientConfig;
use Omnitrix\Core\HttpClient\Response;
use Omnitrix\Core\HttpClient\Uri;
use Omnitrix\Core\Runtime\Config\InvalidConfigurationException;
use Omnitrix\Core\Tests\Support\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(Uri::class)]
#[CoversClass(Response::class)]
#[CoversClass(RequestException::class)]
#[CoversClass(HttpClientConfig::class)]
final class UriAndResponseTest extends TestCase
{
    public function testUriParsingDefaultsAndTarget(): void
    {
        $u = Uri::parse('HTTPS://Api.Example.com/v1/users?x=1');
        self::assertSame(['https', 'api.example.com', 443, '/v1/users', 'x=1'], [$u->scheme, $u->host, $u->port, $u->path, $u->query]);
        self::assertTrue($u->isTls());
        self::assertSame('/v1/users?x=1', $u->target());
        self::assertSame('api.example.com', $u->hostHeader());
        self::assertSame('https://api.example.com:443', $u->origin());
        self::assertSame('http://h:8080', Uri::parse('http://h:8080')->origin());
        self::assertSame('h:8080', Uri::parse('http://h:8080')->hostHeader());
        self::assertSame('/', Uri::parse('http://h')->path);
        self::assertSame('/p?x=1&y=a%20b&z%5B0%5D=1', Uri::parse('http://h/p?x=1')->withQuery(['y' => 'a b', 'z' => [1]])->target());
    }

    public function testUriResolvesRedirectTargets(): void
    {
        $base = Uri::parse('https://a.example/dir/page?q=1');
        self::assertSame('http://b.example:80/x', (string) $base->resolve('http://b.example/x'));
        self::assertSame('https://c.example:443/y', (string) $base->resolve('//c.example/y'));
        self::assertSame('https://a.example:443/abs', (string) $base->resolve('/abs'));
        self::assertSame('https://a.example:443/dir/rel?z=2', (string) $base->resolve('rel?z=2'));
    }

    public function testUriRejectsInvalidUrls(): void
    {
        foreach (['/relative', 'ftp://x/y', 'not a url', 'http://'] as $bad) {
            try {
                Uri::parse($bad);
                self::fail("accepted {$bad}");
            } catch (InvalidArgumentException) {
            }
        }
        $this->expectException(InvalidArgumentException::class);
        Uri::parse('mailto:x@y');
    }

    public function testResponseAccessorsJsonAndThrow(): void
    {
        $r = new Response(201, ['content-type' => 'application/json', 'x-a' => '1'], '{"id":5,"big":12345678901234567890}', 'http://h/x', ['s=1'], 1, 0.25, '2');
        self::assertTrue($r->ok());
        self::assertFalse($r->failed());
        self::assertSame('1', $r->header('X-A'));
        self::assertTrue($r->hasHeader('x-a'));
        self::assertSame('application/json', $r->contentType());
        self::assertSame(['id' => 5, 'big' => '12345678901234567890'], $r->jsonObject());
        self::assertSame($r->json(), $r->json());
        self::assertSame(['s=1'], $r->setCookies());
        self::assertSame(1, $r->redirectCount());
        self::assertSame(0.25, $r->elapsed());
        self::assertSame('2', $r->protocol());
        self::assertSame($r, $r->throw());

        $bad = new Response(500, [], 'oops', 'http://h/y');
        self::assertTrue($bad->serverError());
        try {
            $bad->throw();
            self::fail('5xx must throw');
        } catch (RequestException $e) {
            self::assertSame(500, $e->getCode());
            self::assertSame($bad, $e->response);
            self::assertStringContainsString('http://h/y', $e->getMessage());
        }
        try {
            (new Response(200, [], '{bad', 'http://h/z'))->json();
            self::fail('invalid JSON accepted');
        } catch (HttpClientException $e) {
            self::assertStringContainsString('not valid JSON', $e->getMessage());
        }
        $this->expectException(HttpClientException::class);
        (new Response(200, [], '[1]', 'http://h/z'))->jsonObject();
    }

    public function testConfigDefaultsAndValidation(): void
    {
        $c = HttpClientConfig::defaults();
        self::assertSame(30.0, $c->timeout);
        self::assertSame(5.0, $c->connectTimeout);
        self::assertTrue($c->verifyTls);
        self::assertSame(0, $c->retries);
        self::assertSame(5, $c->maxRedirects);
        self::assertSame(32, $c->poolMaxPerHost);
        self::assertFalse($c->http2);
        self::assertSame(2.0, HttpClientConfig::fromArray(['timeout' => 2])->timeout);
        foreach ([['timeouts' => 1], ['timeout' => 0], ['ca_file' => '/nonexistent.pem'], ['pool' => ['size' => 1]], ['verify_tls' => 'yes']] as $bad) {
            try {
                HttpClientConfig::fromArray($bad);
                self::fail('accepted ' . json_encode($bad));
            } catch (InvalidConfigurationException) {
            }
        }
        $this->expectException(InvalidConfigurationException::class);
        HttpClientConfig::fromArray(['user_agent' => '']);
    }
}
