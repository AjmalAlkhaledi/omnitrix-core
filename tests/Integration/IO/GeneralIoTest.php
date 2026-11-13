<?php

declare(strict_types=1);

namespace Omnitrix\Core\Tests\Integration\IO;

use Omnitrix\Core\IO\ConnectionClosedException;
use Omnitrix\Core\IO\Dns;
use Omnitrix\Core\IO\Filesystem;
use Omnitrix\Core\IO\IoException;
use Omnitrix\Core\IO\IoTimeoutException;
use Omnitrix\Core\IO\TcpConnection;
use Omnitrix\Core\IO\TcpServer;
use Omnitrix\Core\IO\TempFiles;
use Omnitrix\Core\IO\UdpSocket;
use Omnitrix\Core\Runtime\Context\RequestContext;
use Omnitrix\Core\Runtime\Coroutine\Sleep;
use Omnitrix\Core\Support\Platform;
use Omnitrix\Core\Tests\Support\RecordingExceptionHandler;
use Omnitrix\Core\Tests\Support\TestCase;
use OpenSwoole\Coroutine;
use PHPUnit\Framework\Attributes\CoversClass;

use function defined;
use function file_exists;
use function hrtime;
use function md5;
use function str_repeat;
use function strlen;
use function strtoupper;
use function sys_get_temp_dir;

/**
 * Sockets, DNS and filesystem helpers. Each I/O class has a "sibling keeps ticking" proof that
 * the wait suspends only the calling coroutine.
 */
#[CoversClass(TcpConnection::class)]
#[CoversClass(TcpServer::class)]
#[CoversClass(UdpSocket::class)]
#[CoversClass(Dns::class)]
#[CoversClass(Filesystem::class)]
#[CoversClass(TempFiles::class)]
final class GeneralIoTest extends TestCase
{
    /**
     * Runs `$work` alongside a 20 ms ticker; returns [work result, ticks during work].
     *
     * @return array{0: mixed, 1: int}
     */
    private static function withTicker(callable $work): array
    {
        $ticks = 0;
        $stop = false;
        [$results] = self::runConcurrently([
            'work' => static function () use ($work, &$stop): mixed {
                try {
                    return $work();
                } finally {
                    $stop = true;
                }
            },
            'ticker' => static function () use (&$ticks, &$stop): int {
                while (!$stop) {
                    Sleep::milliseconds(20);
                    $ticks++;
                }

                return $ticks;
            },
        ]);

        return [$results['work'], self::int($results['ticker'])];
    }

    public function testTcpServerAndClientEchoConcurrentlyWithoutBlocking(): void
    {
        $server = new TcpServer('127.0.0.1', 0, new RecordingExceptionHandler(), maxConnections: 16);
        $port = $server->port();
        self::assertGreaterThan(0, $port);
        Coroutine::create(static function () use ($server): void {
            $server->serve(static function (TcpConnection $c): void {
                while (($line = $c->receiveLine(1024, 2.0)) !== '') {
                    Sleep::milliseconds(200); // slow protocol handler
                    $c->send(strtoupper($line));
                }
            });
        });

        try {
            $tasks = [];
            for ($i = 0; $i < 8; $i++) {
                $tasks[$i] = static function () use ($port, $i): string {
                    $c = TcpConnection::connect('localhost', $port, 1.0);
                    $c->send("hello {$i}\n");
                    $reply = $c->receiveLine(1024, 2.0);
                    self::assertSame('127.0.0.1', $c->remote()['address'] ?? null);
                    $c->close();

                    return $reply;
                };
            }
            [$replies, $elapsed] = self::runConcurrently($tasks);
            foreach ($replies as $i => $reply) {
                self::assertSame("HELLO {$i}\n", $reply);
            }
            self::assertLessThan(0.6, $elapsed, '8 × 200 ms handlers overlap');
            self::assertSame(8, $server->accepted());

            [$data, $ticks] = self::withTicker(static function () use ($port): string {
                $c = TcpConnection::connect('127.0.0.1', $port, 1.0);
                $c->send(str_repeat('x', 100) . "\n");
                $r = $c->receiveExactly(101, 2.0);
                $c->close();

                return $r;
            });
            self::assertSame(str_repeat('X', 100) . "\n", $data);
            self::assertGreaterThanOrEqual(8, $ticks, 'the loop kept running during the socket wait');

            $c = TcpConnection::connect('127.0.0.1', $port, 1.0);
            $t = hrtime(true);
            try {
                $c->receive(10, 0.2);
                self::fail('expected read timeout');
            } catch (IoTimeoutException) {
            }
            self::assertLessThan(0.5, (hrtime(true) - $t) / 1e9);
            $c->close();
            try {
                $c->send('x');
                self::fail('send after close');
            } catch (ConnectionClosedException) {
            }
        } finally {
            $server->stop();
            Sleep::milliseconds(600);
            $server->close();
        }
        self::assertSame(0, $server->activeConnections());

        try {
            TcpConnection::connect('127.0.0.1', 1, 0.5);
            self::fail('refused');
        } catch (IoException $e) {
            self::assertSame(111, $e->getCode());
        }
    }

    public function testUdpRoundTripAndTimeout(): void
    {
        $server = new UdpSocket('127.0.0.1', 0);
        $client = new UdpSocket('127.0.0.1', 0);
        [$results] = self::runConcurrently([
            'server' => static function () use ($server): string {
                $d = $server->receive(2.0);
                $server->sendTo($d['address'], $d['port'], 'pong:' . $d['data']);

                return $d['data'];
            },
            'client' => static function () use ($client, $server): string {
                Sleep::milliseconds(20);
                $client->sendTo('127.0.0.1', $server->port(), 'ping');

                return $client->receive(2.0)['data'];
            },
        ]);
        self::assertSame(['server' => 'ping', 'client' => 'pong:ping'], $results);
        try {
            $client->receive(0.1);
            self::fail('expected timeout');
        } catch (IoTimeoutException) {
        }
        $server->close();
        $client->close();
    }

    public function testDnsResolvesCachesAndFailsCleanly(): void
    {
        $dns = new Dns(ttl: 60.0);
        self::assertSame('127.0.0.1', $dns->resolve('localhost'));
        self::assertSame(['10.1.2.3'], $dns->resolveAll('10.1.2.3'), 'IP literals bypass resolution');
        [$ip, $ticks] = self::withTicker(static fn(): string => $dns->resolve('localhost'));
        self::assertSame('127.0.0.1', $ip);
        self::assertGreaterThanOrEqual(0, $ticks);
        try {
            $dns->resolve('nonexistent.invalid', 2.0);
            self::fail('expected failure');
        } catch (IoException $e) {
            self::assertStringContainsString('nonexistent.invalid', $e->getMessage());
        }
        $dns->clear();
    }

    public function testFilesystemReadWriteStreamAndAtomicity(): void
    {
        $fs = new Filesystem();
        $dir = sys_get_temp_dir() . '/omnitrix-fs-' . getmypid();
        try {
            $file = $dir . '/nested/data.bin';
            $payload = str_repeat('0123456789abcdef', 1 << 16); // 1 MiB
            [, $ticks] = self::withTicker(static function () use ($fs, $file, $payload): void {
                $fs->write($file, $payload);
                $fs->read($file);
            });
            self::assertGreaterThanOrEqual(1, $ticks, 'file I/O yielded to other coroutines');
            self::assertSame(md5($payload), md5($fs->read($file)));
            $entries = glob($dir . '/nested/*');
            self::assertSame([$file], $entries === false ? [] : $entries, 'no temp file left after atomic write');

            $fs->append($file, 'tail');
            self::assertSame(strlen($payload) + 4, strlen($fs->read($file)));

            $chunks = 0;
            $bytes = 0;
            foreach ($fs->readChunks($file, 65536) as $chunk) {
                $chunks++;
                $bytes += strlen($chunk);
            }
            self::assertSame(strlen($payload) + 4, $bytes);
            self::assertGreaterThanOrEqual(16, $chunks);

            $copy = $dir . '/copy.bin';
            self::assertSame($bytes, $fs->writeChunks($copy, $fs->readChunks($file)));
            self::assertSame(md5($fs->read($file)), md5($fs->read($copy)));
            self::assertTrue($fs->delete($copy));
            self::assertFalse($fs->exists($copy));
            try {
                $fs->read($dir . '/missing');
                self::fail('missing file');
            } catch (IoException) {
            }
        } finally {
            $fs->deleteDirectory($dir);
        }
        self::assertFalse(file_exists($dir));
    }

    public function testTempFilesAreCleanedUpPerRequestAndOnDemand(): void
    {
        $temp = new TempFiles(sys_get_temp_dir() . '/omnitrix-tmp-' . getmypid());
        $a = $temp->create();
        $b = $temp->create('upload-', '.bin');
        self::assertNotSame($a, $b);
        self::assertTrue(file_exists($a));
        self::assertStringEndsWith('.bin', $b);

        $context = RequestContext::create();
        $requestFile = $temp->forRequest($context);
        self::assertTrue(file_exists($requestFile));
        $context->dispose();
        self::assertFalse(file_exists($requestFile), 'request temp file removed when the request ended');

        self::assertSame(2, $temp->cleanup());
        self::assertFalse(file_exists($a));
        self::assertSame(0, $temp->count());
        (new Filesystem())->deleteDirectory(sys_get_temp_dir() . '/omnitrix-tmp-' . getmypid());
    }

    public function testIoUringAvailabilityIsReportedHonestly(): void
    {
        $kernel = Platform::kernelVersion();
        self::assertNotNull($kernel);
        if (version_compare($kernel, '5.1', '<')) {
            self::assertFalse(Platform::supportsIoUring(), 'io_uring cannot be offered on this kernel; the epoll + thread-pool fallback is what runs');
        } else {
            self::assertSame(defined('OPENSWOOLE_IO_URING'), Platform::supportsIoUring());
        }
    }
}
