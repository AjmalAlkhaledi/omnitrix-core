<?php

declare(strict_types=1);

namespace Omnitrix\Core\Tests\Support;

use OpenSwoole\Coroutine;
use OpenSwoole\Coroutine\Channel;
use PHPUnit\Framework\TestCase as PHPUnitTestCase;
use Throwable;

use function count;
use function hrtime;
use function is_int;
use function json_decode;
use function sprintf;
use function usleep;

/**
 * Base test case for every Omnitrix test.
 *
 * Tests run inside the root coroutine created by bin/co-phpunit. This class adds:
 *  - a leak check: coroutines spawned by a test must have finished when the test ends;
 *  - helpers to run closures concurrently, collect results and measure elapsed time,
 *    rethrowing the first exception raised inside a child coroutine.
 */
abstract class TestCase extends PHPUnitTestCase
{
    private int $coroutinesAtStart = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->coroutinesAtStart = self::liveCoroutines();
    }

    protected function tearDown(): void
    {
        // Give already-finished children one scheduler turn to be reaped before counting.
        usleep(1000);
        $live = self::liveCoroutines();
        parent::tearDown();

        if ($live > $this->coroutinesAtStart) {
            self::fail(sprintf(
                'Coroutine leak: %d coroutine(s) spawned by %s are still running after the test.',
                $live - $this->coroutinesAtStart,
                static::class . '::' . $this->name(),
            ));
        }
    }

    /**
     * Decodes a JSON object and asserts it is one; keys are strings, values stay mixed.
     *
     * @return array<string, mixed>
     */
    protected static function decodeJsonObject(string $json): array
    {
        $decoded = json_decode($json, true);
        self::assertIsArray($decoded, 'expected a JSON object, got: ' . $json);
        $object = [];
        foreach ($decoded as $key => $value) {
            $object[(string) $key] = $value;
        }

        return $object;
    }

    /** Asserts `$value` is a string and returns it typed. */
    protected static function string(mixed $value): string
    {
        self::assertIsString($value);

        return $value;
    }

    /** Asserts `$value` is an int and returns it typed. */
    protected static function int(mixed $value): int
    {
        self::assertIsInt($value);

        return $value;
    }

    /**
     * Asserts `$value` is an array and returns it with string keys.
     *
     * @return array<string, mixed>
     */
    protected static function assoc(mixed $value): array
    {
        self::assertIsArray($value);
        $out = [];
        foreach ($value as $key => $item) {
            $out[(string) $key] = $item;
        }

        return $out;
    }

    public static function liveCoroutines(): int
    {
        $live = Coroutine::stats()['coroutine_num'] ?? 0;

        return is_int($live) ? $live : 0;
    }

    /**
     * Cooperative sleep with sub-second resolution (Co::sleep() truncates floats to int).
     */
    protected static function wait(float $seconds): void
    {
        usleep((int) ($seconds * 1_000_000));
    }

    /**
     * Runs every closure in its own coroutine, waits for all of them and returns
     * [results keyed and ordered like $tasks, elapsed seconds]. The first exception thrown by any task is
     * rethrown after all tasks finished, so no coroutine leaks past the test.
     *
     * @template T
     * @param array<array-key, callable(): T> $tasks
     * @return array{0: array<array-key, T>, 1: float}
     */
    protected static function runConcurrently(array $tasks): array
    {
        $count = count($tasks);
        $done = new Channel($count > 0 ? $count : 1);
        $started = hrtime(true);

        foreach ($tasks as $key => $task) {
            Coroutine::create(static function () use ($key, $task, $done): void {
                try {
                    $done->push([$key, $task(), null]);
                } catch (Throwable $e) {
                    $done->push([$key, null, $e]);
                }
            });
        }

        $results = [];
        foreach ($tasks as $key => $_) {
            $results[$key] = null; // reserve input order; filled as tasks complete
        }
        $failure = null;

        for ($i = 0; $i < $count; $i++) {
            /** @var array{0: array-key, 1: mixed, 2: Throwable|null} $item */
            $item = $done->pop();
            [$key, $value, $error] = $item;
            if ($error !== null) {
                $failure ??= $error;
                unset($results[$key]);
                continue;
            }
            $results[$key] = $value;
        }

        $elapsed = (hrtime(true) - $started) / 1e9;

        if ($failure !== null) {
            throw $failure;
        }

        /** @var array<array-key, T> $results */
        return [$results, $elapsed];
    }
}
