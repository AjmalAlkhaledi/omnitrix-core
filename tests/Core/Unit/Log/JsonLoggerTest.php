<?php

declare(strict_types=1);

namespace Omnitrix\Core\Tests\Unit\Log;

use Omnitrix\Core\Log\JsonLogger;
use Omnitrix\Core\Tests\Support\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use Psr\Log\InvalidArgumentException;
use Psr\Log\LogLevel;
use RuntimeException;
use stdClass;

use function explode;
use function fopen;
use function rewind;
use function stream_get_contents;
use function trim;

#[CoversClass(JsonLogger::class)]
final class JsonLoggerTest extends TestCase
{
    /** @return array{JsonLogger, resource} */
    private function logger(): array
    {
        $stream = fopen('php://memory', 'w+');
        self::assertNotFalse($stream);

        return [new JsonLogger($stream, ['secret', 'Authorization'], true, 'test'), $stream];
    }

    /**
     * @param resource $stream
     * @return list<array<string, mixed>>
     */
    private static function lines($stream): array
    {
        rewind($stream);
        $out = [];
        foreach (explode("\n", trim((string) stream_get_contents($stream))) as $line) {
            $out[] = self::decodeJsonObject($line);
        }

        return $out;
    }

    public function testWritesOneJsonObjectPerLineWithRedactionAndNesting(): void
    {
        [$logger, $stream] = $this->logger();
        $logger->info('hello {name}', ['name' => 'x', 'secret' => 's3', 'headers' => ['authorization' => 'Bearer t', 'accept' => 'json'], 'obj' => new stdClass(), 'e' => new RuntimeException('inner', 0, new RuntimeException('cause'))]);
        $logger->warning('second');

        $lines = self::lines($stream);
        self::assertCount(2, $lines);
        self::assertSame('info', $lines[0]['level']);
        self::assertSame('test', $lines[0]['channel']);
        self::assertSame('hello {name}', $lines[0]['message']);
        self::assertSame('[redacted]', $lines[0]['secret']);
        $headers = self::assoc($lines[0]['headers']);
        self::assertSame('[redacted]', $headers['authorization']);
        self::assertSame('json', $headers['accept']);
        self::assertSame('stdClass', $lines[0]['obj']);
        $e = self::assoc($lines[0]['e']);
        self::assertSame('inner', $e['message']);
        self::assertArrayHasKey('trace', $e);
        self::assertSame('cause', self::assoc($e['previous'])['message']);
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T/', self::string($lines[1]['ts']));
    }

    public function testRejectsUnknownLevels(): void
    {
        [$logger] = $this->logger();
        $this->expectException(InvalidArgumentException::class);
        $logger->log('loud', 'x');
    }

    public function testRecordsBelowTheMinimumLevelAreDiscarded(): void
    {
        $stream = fopen('php://memory', 'w+b');
        self::assertIsResource($stream);
        $logger = new JsonLogger($stream, minimumLevel: LogLevel::INFO);
        $logger->debug('hidden');
        $logger->info('shown');
        $logger->error('also shown');
        rewind($stream);
        $output = (string) stream_get_contents($stream);

        self::assertStringNotContainsString('hidden', $output);
        self::assertStringContainsString('"message":"shown"', $output);
        self::assertStringContainsString('"message":"also shown"', $output);

        $this->expectException(InvalidArgumentException::class);
        new JsonLogger($stream, minimumLevel: 'verbose');
    }
}
