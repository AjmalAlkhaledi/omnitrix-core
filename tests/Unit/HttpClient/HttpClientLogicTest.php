<?php

declare(strict_types=1);

namespace Omnitrix\Core\Tests\Unit\HttpClient;

use Closure;
use Omnitrix\Core\HttpClient\Exception\ConnectionException;
use Omnitrix\Core\HttpClient\Exception\ResponseTooLargeException;
use Omnitrix\Core\HttpClient\Exception\TimeoutException;
use Omnitrix\Core\HttpClient\Exception\TooManyRedirectsException;
use Omnitrix\Core\HttpClient\HttpClient;
use Omnitrix\Core\HttpClient\HttpClientConfig;
use Omnitrix\Core\HttpClient\PendingRequest;
use Omnitrix\Core\HttpClient\RequestOptions;
use Omnitrix\Core\HttpClient\Transport\Exchange;
use Omnitrix\Core\HttpClient\Transport\RawResponse;
use Omnitrix\Core\Tests\Support\FakeTransport;
use Omnitrix\Core\Tests\Support\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use Throwable;

use function str_repeat;

#[CoversClass(HttpClient::class)]
#[CoversClass(PendingRequest::class)]
#[CoversClass(RequestOptions::class)]
#[CoversClass(Exchange::class)]
#[CoversClass(RawResponse::class)]
final class HttpClientLogicTest extends TestCase
{
    /**
     * @param list<RawResponse|Throwable|Closure(Exchange): RawResponse> $script
     * @param array<string, mixed> $config
     * @return array{HttpClient, FakeTransport}
     */
    private function client(array $script, array $config = []): array
    {
        $transport = new FakeTransport($script);

        return [new HttpClient(HttpClientConfig::fromArray($config + ['retry_base_delay_ms' => 0]), $transport), $transport];
    }

    public function testBuildsHeadersQueryAndBodies(): void
    {
        [$client, $transport] = $this->client([new RawResponse(200, [], '{"ok":true}')]);
        $response = $client->request()->withHeaders(['x-custom' => 'v'])->withToken('t0k')->withQuery(['page' => 2])->asJson(['a' => 1])->post('http://api.local:8080/items?sort=asc');

        $x = $transport->exchanges[0];
        self::assertSame('POST', $x->method);
        self::assertSame('/items?sort=asc&page=2', $x->uri->target());
        self::assertSame('v', $x->headers['X-Custom']);
        self::assertSame('Bearer t0k', $x->headers['Authorization']);
        self::assertSame('application/json', $x->headers['Content-Type']);
        self::assertSame('api.local:8080', $x->headers['Host']);
        self::assertSame('Omnitrix/0.1', $x->headers['User-Agent']);
        self::assertSame('{"a":1}', $x->body);
        self::assertTrue($x->verifyTls);
        self::assertSame(['ok' => true], $response->json());
        self::assertSame('http://api.local:8080/items?sort=asc&page=2', $response->url());

        [$client, $transport] = $this->client([new RawResponse(200, [], '')]);
        $client->request()->withBasicAuth('u', 'p:w')->asForm(['a' => 'b c'])->timeout(1.5)->connectTimeout(0.5)->withoutTlsVerification()->put('https://h/f');
        $x = $transport->exchanges[0];
        self::assertSame('Basic ' . base64_encode('u:p:w'), $x->headers['Authorization']);
        self::assertSame('a=b%20c', $x->body);
        self::assertSame('application/x-www-form-urlencoded', $x->headers['Content-Type']);
        self::assertSame([1.5, 0.5, false], [$x->timeout, $x->connectTimeout, $x->verifyTls]);
    }

    public function testFollowsRedirectsChangingMethodAndStrippingCredentialsAcrossOrigins(): void
    {
        [$client, $transport] = $this->client([
            new RawResponse(303, ['location' => '/created/5'], ''),
            new RawResponse(302, ['location' => 'https://other.example/final'], ''),
            new RawResponse(200, [], 'done'),
        ]);
        $response = $client->request()->withToken('secret')->asJson(['x' => 1])->post('http://a.example/items');

        self::assertSame('done', $response->body());
        self::assertSame(2, $response->redirectCount());
        self::assertSame('https://other.example:443/final', $response->url());
        self::assertCount(3, $transport->exchanges);
        self::assertSame('GET', $transport->exchanges[1]->method, '303 switches to GET');
        self::assertSame('', $transport->exchanges[1]->body);
        self::assertArrayNotHasKey('Content-Type', $transport->exchanges[1]->headers);
        self::assertSame('Bearer secret', $transport->exchanges[1]->headers['Authorization'], 'same origin keeps credentials');
        self::assertArrayNotHasKey('Authorization', $transport->exchanges[2]->headers, 'cross-origin redirect drops credentials');
        self::assertSame('other.example', $transport->exchanges[2]->headers['Host']);
    }

    public function testRedirectLimitsAndOptOut(): void
    {
        $loop = [];
        for ($i = 0; $i < 7; $i++) {
            $loop[] = new RawResponse(301, ['location' => '/again'], '');
        }
        [$client] = $this->client($loop, ['max_redirects' => 3]);
        try {
            $client->get('http://h/start');
            self::fail('expected redirect limit');
        } catch (TooManyRedirectsException $e) {
            self::assertStringContainsString('3 redirects', $e->getMessage());
        }

        [$client, $transport] = $this->client([new RawResponse(302, ['location' => '/elsewhere'], '')]);
        $response = $client->request()->withoutRedirecting()->get('http://h/start');
        self::assertSame(302, $response->status());
        self::assertSame('/elsewhere', $response->header('location'));
        self::assertCount(1, $transport->exchanges);
        self::assertSame(307, $this->client([new RawResponse(307, ['location' => '/x'], ''), new RawResponse(200, [], '')])[0]->request()->maxRedirects(0)->post('http://h/p')->status());
    }

    public function testRetriesOnlyIdempotentRequestsOnConnectionFailures(): void
    {
        [$client, $transport] = $this->client([new ConnectionException('refused'), new ConnectionException('refused'), new RawResponse(200, [], 'ok')], ['retries' => 2]);
        self::assertSame('ok', $client->get('http://h/')->body());
        self::assertCount(3, $transport->exchanges);
        self::assertSame(2, $client->stats()['retries']);

        [$client, $transport] = $this->client([new ConnectionException('refused'), new RawResponse(200, [], 'ok')], ['retries' => 2]);
        try {
            $client->request()->asJson(['x' => 1])->post('http://h/');
            self::fail('POST must not be retried');
        } catch (ConnectionException) {
        }
        self::assertCount(1, $transport->exchanges);

        [$client, $transport] = $this->client([new TimeoutException('slow'), new RawResponse(200, [], 'ok')], ['retries' => 2]);
        try {
            $client->get('http://h/');
            self::fail('timeouts are not retried by default');
        } catch (TimeoutException) {
        }
        self::assertCount(1, $transport->exchanges);

        [$client, $transport] = $this->client([new TimeoutException('slow'), new RawResponse(200, [], 'ok')]);
        self::assertSame('ok', $client->request()->retry(1, onTimeout: true)->get('http://h/')->body());
        self::assertCount(2, $transport->exchanges);

        [$client, $transport] = $this->client([new ConnectionException('refused'), new ConnectionException('refused')], ['retries' => 1]);
        try {
            $client->get('http://h/');
            self::fail('retries exhausted must rethrow');
        } catch (ConnectionException) {
        }
        self::assertSame(1, $client->stats()['failures']);
    }

    public function testResponseSizeLimitAndStats(): void
    {
        [$client] = $this->client([new RawResponse(200, [], str_repeat('x', 1001))], ['max_response_size' => 1000]);
        try {
            $client->get('http://h/big');
            self::fail('oversized response accepted');
        } catch (ResponseTooLargeException $e) {
            self::assertStringContainsString('1001 bytes, limit 1000', $e->getMessage());
        }
        [$client] = $this->client([new RawResponse(200, [], str_repeat('x', 1001))]);
        self::assertSame(1001, $client->request()->maxResponseSize(2000)->get('http://h/big')->size());
        $stats = $client->stats();
        self::assertSame(1, $stats['requests']);
        self::assertSame(1, $stats['responses']);
        self::assertSame(0, $stats['in_flight']);
    }

    public function testDownloadPassesTargetPathToTheTransport(): void
    {
        [$client, $transport] = $this->client([new RawResponse(200, ['content-length' => '5000000'], '')]);
        $response = $client->request()->download('http://h/file.bin', '/tmp/out.bin');
        self::assertSame('/tmp/out.bin', $transport->exchanges[0]->downloadTo);
        self::assertSame('GET', $transport->exchanges[0]->method);
        self::assertSame(200, $response->status());
        $client->close();
        self::assertTrue($transport->closed);
    }
}
