<?php

declare(strict_types=1);

namespace Omnitrix\Tests\Unit\Production;

use Omnitrix\Config\Env;
use Omnitrix\Core\Http\Server\IncomingRequest;
use Omnitrix\Core\Log\JsonLogger;
use Omnitrix\Core\Runtime\Config\InvalidConfigurationException;
use Omnitrix\Core\Runtime\Context\RequestContext;
use Omnitrix\Core\Runtime\Coroutine\Sleep;
use Omnitrix\Http\Middleware\Pipeline;
use Omnitrix\Http\Request;
use Omnitrix\Http\Response;
use Omnitrix\Observability\AccessLog;
use Omnitrix\Observability\HealthCheck;
use Omnitrix\Tests\Support\TestCase;
use OpenSwoole\Http\Request as SwooleRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use RuntimeException;

use function file_put_contents;
use function fopen;
use function putenv;
use function rewind;
use function stream_get_contents;
use function sys_get_temp_dir;
use function unlink;

#[CoversClass(HealthCheck::class)]
#[CoversClass(AccessLog::class)]
#[CoversClass(Env::class)]
final class ObservabilityAndEnvTest extends TestCase
{
    public function testHealthChecksRunConcurrentlyWithTimeouts(): void
    {
        $health = (new HealthCheck(0.3))
            ->add('fast', static fn(): bool => true)
            ->add('slow', static function (): bool {
                Sleep::milliseconds(150);
                return true;
            });
        $ok = $health->ready();
        self::assertSame(200, $ok->getStatus());
        self::assertStringContainsString('"checks":{"fast":"ok","slow":"ok"}', $ok->getBody());
        self::assertSame('{"status":"ok"}', $health->live()->getBody());

        $bad = (new HealthCheck(0.2))
            ->add('broken', static function (): bool {
                throw new RuntimeException('secret dsn in message');
            })
            ->add('hanging', static function (): bool {
                Sleep::milliseconds(2000);
                return true;
            })
            ->add('false', static fn(): bool => false);
        $r = $bad->ready();
        self::assertSame(503, $r->getStatus());
        self::assertStringContainsString('"broken":"error: RuntimeException"', $r->getBody());
        self::assertStringContainsString('"hanging":"timeout"', $r->getBody());
        self::assertStringContainsString('"false":"failed"', $r->getBody());
        self::assertStringNotContainsString('secret dsn', $r->getBody());
    }

    public function testAccessLogWritesOneStructuredLineWithoutSecrets(): void
    {
        $stream = fopen('php://memory', 'w+');
        self::assertNotFalse($stream);
        $raw = SwooleRequest::create([]);
        self::assertInstanceOf(SwooleRequest::class, $raw);
        $raw->parse("GET /orders?token=supersecret HTTP/1.1\r\nHost: h\r\nAuthorization: Bearer topsecret\r\n\r\n");
        $request = new Request(new IncomingRequest($raw), RequestContext::create());
        $request->setRoute([], 'orders', '/orders');
        Pipeline::compile([new AccessLog(new JsonLogger($stream))], static fn(): Response => Response::text('x', 201))($request);
        rewind($stream);
        $line = (string) stream_get_contents($stream);
        $record = self::decodeJsonObject($line);
        self::assertSame('request', $record['message']);
        self::assertSame(201, $record['status']);
        self::assertSame('/orders', $record['route']);
        self::assertSame($request->id(), $record['request_id']);
        self::assertStringNotContainsString('secret', $line, 'no query string or auth header in logs');
    }

    public function testEnvLoadingAndTypedAccess(): void
    {
        $file = sys_get_temp_dir() . '/omnitrix-env-' . getmypid();
        file_put_contents($file, "# comment\nOMNI_A=plain\nexport OMNI_B=\"quoted value # not a comment\"\nOMNI_C=7 # trailing comment\nOMNI_D=yes\nOMNI_EXISTING=from-file\n");
        putenv('OMNI_EXISTING=from-env');
        try {
            self::assertSame(4, Env::load($file));
            self::assertSame('plain', Env::get('OMNI_A'));
            self::assertSame('quoted value # not a comment', Env::get('OMNI_B'));
            self::assertSame(7, Env::int('OMNI_C', 0));
            self::assertTrue(Env::bool('OMNI_D', false));
            self::assertSame('from-env', Env::get('OMNI_EXISTING'), 'real environment wins');
            self::assertSame('dflt', Env::get('OMNI_MISSING', 'dflt'));
            self::assertSame(3, Env::int('OMNI_MISSING', 3));
            self::assertSame(0, Env::load('/nonexistent/.env'));
            try {
                Env::required('OMNI_MISSING');
                self::fail('missing required accepted');
            } catch (InvalidConfigurationException) {
            }
            putenv('OMNI_C=abc');
            $this->expectException(InvalidConfigurationException::class);
            Env::int('OMNI_C', 0);
        } finally {
            unlink($file);
            foreach (['OMNI_A', 'OMNI_B', 'OMNI_C', 'OMNI_D', 'OMNI_EXISTING'] as $name) {
                putenv($name);
            }
        }
    }
}
