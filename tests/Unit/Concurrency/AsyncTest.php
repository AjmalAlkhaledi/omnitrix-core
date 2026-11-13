<?php

declare(strict_types=1);

namespace Omnitrix\Core\Tests\Unit\Concurrency;

use InvalidArgumentException;
use LogicException;
use Omnitrix\Core\Concurrency\Async;
use Omnitrix\Core\Concurrency\CancellationToken;
use Omnitrix\Core\Concurrency\CancelledException;
use Omnitrix\Core\Concurrency\Outcome;
use Omnitrix\Core\Concurrency\Semaphore;
use Omnitrix\Core\Concurrency\Task;
use Omnitrix\Core\Concurrency\TaskScope;
use Omnitrix\Core\Concurrency\TaskState;
use Omnitrix\Core\Concurrency\TimeoutException;
use Omnitrix\Core\Runtime\Context\ContextStorage;
use Omnitrix\Core\Runtime\Context\RequestContext;
use Omnitrix\Core\Runtime\Coroutine\Sleep;
use Omnitrix\Core\Tests\Support\Flag;
use Omnitrix\Core\Tests\Support\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use RuntimeException;

use function hrtime;
use function max;

#[CoversClass(Async::class)]
#[CoversClass(Task::class)]
#[CoversClass(TaskScope::class)]
#[CoversClass(TaskState::class)]
#[CoversClass(CancellationToken::class)]
#[CoversClass(Semaphore::class)]
#[CoversClass(Outcome::class)]
#[CoversClass(TimeoutException::class)]
#[CoversClass(CancelledException::class)]
final class AsyncTest extends TestCase
{
    protected function tearDown(): void
    {
        ContextStorage::unbind();
        parent::tearDown();
    }

    private static function elapsed(callable $fn): float
    {
        $t = hrtime(true);
        $fn();

        return (hrtime(true) - $t) / 1e9;
    }

    public function testAllRunsTasksConcurrentlyAndKeepsKeysAndOrder(): void
    {
        $results = null;
        $elapsed = self::elapsed(static function () use (&$results): void {
            $results = Async::all([
                'c' => static function (): string {
                    Sleep::milliseconds(300);
                    return 'C';
                },
                'a' => static function (): string {
                    Sleep::milliseconds(100);
                    return 'A';
                },
                'b' => static function (): string {
                    Sleep::milliseconds(200);
                    return 'B';
                },
            ]);
        });
        self::assertSame(['c' => 'C', 'a' => 'A', 'b' => 'B'], $results, 'input key order is preserved');
        self::assertLessThan(0.45, $elapsed, "three sleeps (300+200+100 ms serial) took {$elapsed}s; they must overlap");
        self::assertGreaterThanOrEqual(0.29, $elapsed);
    }

    public function testAllFailsFastCancelsSiblingsAndRethrowsTheOriginal(): void
    {
        $sibling = new Flag();
        $sibling->text = 'not started';
        $elapsed = self::elapsed(function () use ($sibling): void {
            try {
                Async::all([
                    'slow' => static function (CancellationToken $token) use ($sibling): void {
                        $sibling->text = 'running';
                        try {
                            Sleep::milliseconds(2000);
                            $sibling->text = 'completed';
                        } catch (CancelledException) {
                            $sibling->text = 'cancelled:' . ($token->reason() ?? '');
                            throw new CancelledException();
                        }
                    },
                    'fail' => static function (): never {
                        Sleep::milliseconds(50);
                        throw new RuntimeException('db down');
                    },
                ]);
                self::fail('expected the failure to propagate');
            } catch (RuntimeException $e) {
                self::assertSame('db down', $e->getMessage());
            }
        });
        self::assertStringStartsWith('cancelled:sibling failed', $sibling->text, 'sibling must be interrupted, not left running');
        self::assertLessThan(0.5, $elapsed, 'all() returns as soon as the sibling unwound');
    }

    public function testAllTimesOutCancelsEverythingAndReportsTimeout(): void
    {
        $finished = 0;
        $cancelled = 0;
        $elapsed = self::elapsed(function () use (&$finished, &$cancelled): void {
            try {
                Async::all([
                    static function () use (&$finished): void {
                        Sleep::milliseconds(50);
                        $finished++;
                    },
                    static function () use (&$cancelled): void {
                        try {
                            Sleep::milliseconds(5000);
                        } catch (CancelledException $e) {
                            $cancelled++;
                            throw $e;
                        }
                    },
                    static function () use (&$cancelled): void {
                        try {
                            Sleep::milliseconds(5000);
                        } catch (CancelledException $e) {
                            $cancelled++;
                            throw $e;
                        }
                    },
                ], timeout: 0.2);
                self::fail('expected timeout');
            } catch (TimeoutException $e) {
                self::assertStringContainsString('0.2s', $e->getMessage());
            }
        });
        self::assertSame(1, $finished);
        self::assertSame(2, $cancelled);
        self::assertLessThan(0.6, $elapsed);
    }

    public function testAllSettledReportsEveryOutcome(): void
    {
        $outcomes = Async::allSettled([
            'ok' => static fn(): int => 1,
            'bad' => static function (): never {
                throw new RuntimeException('nope');
            },
            'slow' => static function (): string {
                Sleep::milliseconds(50);
                return 'late';
            },
        ]);
        self::assertTrue($outcomes['ok']->fulfilled);
        self::assertSame(1, $outcomes['ok']->get());
        self::assertTrue($outcomes['bad']->isRejected());
        self::assertInstanceOf(RuntimeException::class, $outcomes['bad']->reason);
        self::assertFalse($outcomes['bad']->cancelled);
        self::assertSame('late', $outcomes['slow']->value);

        $timed = Async::allSettled([
            'fast' => static fn(): string => 'f',
            'never' => static function (): void {
                Sleep::milliseconds(5000);
            },
        ], timeout: 0.1);
        self::assertTrue($timed['fast']->fulfilled);
        self::assertTrue($timed['never']->cancelled);
        try {
            $timed['never']->get();
            self::fail('rejected outcome must throw on get()');
        } catch (CancelledException) {
        }
        self::assertSame(['ok', 'bad', 'slow'], array_keys($outcomes));
    }

    public function testRaceReturnsTheFirstSuccessAndCancelsTheRest(): void
    {
        $loser = new Flag();
        $winner = Async::race([
            'slow' => static function () use ($loser): string {
                try {
                    Sleep::milliseconds(1000);
                } catch (CancelledException $e) {
                    $loser->set = true;
                    throw $e;
                } return 'slow';
            },
            'failing' => static function (): never {
                throw new RuntimeException('first to fail');
            },
            'fast' => static function (): string {
                Sleep::milliseconds(50);
                return 'fast';
            },
        ]);
        self::assertSame('fast', $winner, 'a failure does not win the race');
        self::assertTrue($loser->set);

        $failing = static function (string $name): callable {
            return static function () use ($name): string {
                if ($name !== '') {
                    throw new RuntimeException($name);
                }

                return $name;
            };
        };
        try {
            Async::race(['a' => $failing('a'), 'b' => $failing('b')]);
            self::fail('all-failed race must throw');
        } catch (RuntimeException $e) {
            self::assertContains($e->getMessage(), ['a', 'b']);
        }
    }

    public function testRaceRequiresAtLeastOneTask(): void
    {
        /** @var array<string, callable(): int> $none */
        $none = [];
        $this->expectException(InvalidArgumentException::class);
        Async::race($none);
    }

    public function testConcurrencyLimitBoundsRunningTasks(): void
    {
        $running = 0;
        $peak = 0;
        $tasks = [];
        for ($i = 0; $i < 12; $i++) {
            $tasks[] = static function () use (&$running, &$peak): int {
                $running++;
                $peak = max($peak, $running);
                Sleep::milliseconds(20);
                $running--;

                return 1;
            };
        }
        $elapsed = self::elapsed(static function () use ($tasks): void {
            Async::all($tasks, concurrency: 3);
        });
        self::assertSame(3, $peak);
        self::assertGreaterThanOrEqual(0.07, $elapsed, '12 tasks × 20 ms with 3 lanes take ≥ 80 ms');
    }

    public function testChildrenInheritTheRequestContextAndScopeNeverLeaks(): void
    {
        $context = RequestContext::create();
        ContextStorage::bind($context);
        $before = self::liveCoroutines();

        $seen = Async::all([
            'child' => static fn(): ?string => ContextStorage::current()?->id,
            'nested' => static fn(): array => Async::all(['grandchild' => static fn(): ?string => ContextStorage::current()?->id]),
        ]);
        self::assertSame($context->id, $seen['child']);
        self::assertSame($context->id, self::assoc($seen['nested'])['grandchild']);
        self::wait(0.005); // hooked usleep: lets the timer-callback coroutines of Sleep::for() unwind
        self::assertSame($before, self::liveCoroutines(), 'no task survives the group');
    }

    public function testScopeClosesRemainingTasksEvenWhenTheBodyThrows(): void
    {
        $interrupted = new Flag();
        try {
            Async::scope(static function (TaskScope $scope) use ($interrupted): void {
                $scope->spawn(static function () use ($interrupted): void {
                    try {
                        Sleep::milliseconds(5000);
                    } catch (CancelledException $e) {
                        $interrupted->set = true;
                        throw $e;
                    }
                }, 'background');
                Sleep::milliseconds(20);
                if ($scope->count() > 0) {
                    throw new LogicException('body failed');
                }
            });
            self::fail('body exception must propagate');
        } catch (LogicException $e) {
            self::assertSame('body failed', $e->getMessage());
        }
        self::assertTrue($interrupted->set, 'the orphan task was cancelled when the scope closed');
    }

    public function testTaskLifecycleAwaitJoinAndCancellation(): void
    {
        $scope = new TaskScope();
        $task = $scope->spawn(static function (): string {
            Sleep::milliseconds(50);
            return 'done';
        }, 'job');
        self::assertSame('job', $task->key);
        self::assertFalse($task->isDone());
        self::assertFalse($task->join(0.01), 'join with a short timeout reports not done');
        self::assertSame('done', $task->await(1.0));
        self::assertSame(TaskState::Completed, $task->state());
        self::assertSame('done', $task->await(), 'awaiting twice returns the cached result');

        $long = $scope->spawn(static function (): void {
            Sleep::milliseconds(5000);
        }, 'long');
        Sleep::milliseconds(5);
        $long->cancel('operator request');
        try {
            $long->await(1.0);
            self::fail('cancelled task must throw');
        } catch (CancelledException $e) {
            self::assertStringContainsString('cancelled', $e->getMessage());
        }
        self::assertSame(TaskState::Cancelled, $long->state());

        self::assertSame(0, $scope->close());
        $this->expectException(LogicException::class);
        $scope->spawn(static fn() => null);
    }

    public function testWithTimeoutAndCancellationTokenPropagation(): void
    {
        self::assertSame('ok', Async::withTimeout(static fn(): string => 'ok', 1.0));
        try {
            Async::withTimeout(static function (): void {
                Sleep::milliseconds(2000);
            }, 0.1);
            self::fail('expected timeout');
        } catch (TimeoutException) {
        }

        $parent = new CancellationToken();
        $observed = null;
        $callbackReason = null;
        $parent->onCancel(static function (?string $reason) use (&$callbackReason): void {
            $callbackReason = $reason;
        });
        try {
            Async::all([
                static function (CancellationToken $token) use (&$observed, $parent): void {
                    Sleep::milliseconds(20);
                    $parent->cancel('upstream gone');
                    $token->throwIfCancelled();
                    $observed = 'not cancelled';
                },
            ], parent: $parent);
            self::fail('cancelled parent must cancel the group');
        } catch (CancelledException $e) {
            self::assertSame('upstream gone', $e->getMessage());
        }
        self::assertNull($observed);
        self::assertSame('upstream gone', $callbackReason);
        self::assertTrue($parent->createChild()->isCancelled(), 'children of a cancelled token start cancelled');
    }

    public function testSemaphoreTimeoutAndRelease(): void
    {
        $sem = new Semaphore(1);
        $sem->acquire();
        self::assertSame(0, $sem->available());
        try {
            $sem->acquire(0.05);
            self::fail('second acquire must time out');
        } catch (TimeoutException) {
        }
        $sem->release();
        self::assertSame('x', $sem->withPermit(static fn(): string => 'x'));
        self::assertSame(1, $sem->available());
        self::assertSame(1, $sem->peak());
        $this->expectException(LogicException::class);
        $sem->release();
    }

    public function testRejectsBadTimeouts(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Async::all([static fn() => 1], timeout: 0.0);
    }
}
