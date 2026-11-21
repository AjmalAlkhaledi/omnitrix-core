<?php

declare(strict_types=1);

namespace Omnitrix\Scheduling;

use Closure;
use DateTimeImmutable;
use DateTimeInterface;
use LogicException;
use Omnitrix\Core\Queue\QueueProducer;
use Omnitrix\Core\Redis\RedisClient;
use OpenSwoole\Coroutine;
use OpenSwoole\Coroutine\Channel;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

use function count;
use function implode;
use function microtime;
use function round;

/**
 * The application's scheduled tasks (`$app->schedule(fn (Schedule $s) => …)` in bootstrap.php):
 *
 * ```php
 * $s->call(fn () => Report::pruneOld())->daily()->description('prune reports');
 * $s->job('reports.build', ['period' => 'day'])->dailyAt('02:00')->onOneServer();
 * $s->command('cache:prune')->hourly()->withoutOverlapping();
 * ```
 *
 * `php omnitrix schedule:run` runs the tasks due in the current minute (call it every minute from
 * cron); `php omnitrix schedule:work` does that in a long-running process.
 */
final class Schedule
{
    /** @var list<ScheduledTask> */
    private array $tasks = [];

    /** @var (Closure(string, list<string>): int)|null */
    private ?Closure $commandRunner = null;

    /** @param callable(): mixed $callback runs in the scheduler process, with the facades */
    public function call(callable $callback, string $name = 'callback'): ScheduledTask
    {
        return $this->tasks[] = new ScheduledTask('call', $name, $callback(...));
    }

    /**
     * Dispatches a queued job (run by `queue:work`).
     *
     * @param array<string, mixed> $payload
     */
    public function job(string $name, array $payload = [], ?string $queue = null): ScheduledTask
    {
        return $this->tasks[] = new ScheduledTask('job', $name, static function () use ($name, $payload, $queue): void {
            $producer = \Omnitrix\Facades\Facade::container()?->get(QueueProducer::class);
            if (!$producer instanceof QueueProducer) {
                throw new LogicException('Scheduled jobs need the queue (redis configuration)');
            }
            $producer->dispatch($name, $payload, 0.0, $queue);
        });
    }

    /**
     * Runs a console command (`php omnitrix {name} {arguments}`) in the scheduler process.
     *
     * @param list<string> $arguments
     */
    public function command(string $name, array $arguments = []): ScheduledTask
    {
        return $this->tasks[] = new ScheduledTask('command', $name . ($arguments === [] ? '' : ' ' . implode(' ', $arguments)), function () use ($name, $arguments): void {
            if ($this->commandRunner === null) {
                throw new LogicException('Console commands can only be scheduled from the console scheduler');
            }
            $code = ($this->commandRunner)($name, $arguments);
            if ($code !== 0) {
                throw new RuntimeException("Command {$name} exited with {$code}");
            }
        });
    }

    /** @internal set by the scheduler commands */
    public function useCommandRunner(Closure $runner): void
    {
        $this->commandRunner = $runner;
    }

    /** @return list<ScheduledTask> */
    public function tasks(): array
    {
        return $this->tasks;
    }

    /** @return list<ScheduledTask> */
    public function dueTasks(DateTimeInterface $now): array
    {
        $due = [];
        foreach ($this->tasks as $task) {
            if ($task->isDue($now)) {
                $due[] = $task;
            }
        }

        return $due;
    }

    /**
     * Runs the tasks due at `$now`, concurrently, and waits for them. Locks for onOneServer()
     * and withoutOverlapping() are taken in Redis when given. Returns how many ran.
     */
    public function runDue(DateTimeInterface $now, ?RedisClient $redis, LoggerInterface $logger): int
    {
        $minute = DateTimeImmutable::createFromInterface($now)->format('YmdHi');
        $run = [];
        foreach ($this->dueTasks($now) as $task) {
            if (($task->oneServer || $task->overlapTtl !== null) && $redis === null) {
                $logger->warning('scheduled task needs Redis for its lock; skipped', ['task' => $task->label()]);
                continue;
            }
            if ($task->oneServer && $redis !== null && !$redis->setIfAbsent('schedule:' . $task->id() . ':' . $minute, '1', 3600)) {
                continue; // another server took this minute
            }
            if ($task->overlapTtl !== null && $redis !== null && !$redis->setIfAbsent('schedule:' . $task->id() . ':running', '1', $task->overlapTtl)) {
                $logger->info('scheduled task still running; skipped', ['task' => $task->label()]);
                continue;
            }
            $run[] = $task;
        }
        if ($run === []) {
            return 0;
        }
        $done = new Channel(count($run));
        foreach ($run as $task) {
            Coroutine::create(static function () use ($task, $redis, $logger, $done): void {
                $start = microtime(true);
                try {
                    ($task->run)();
                    $logger->info('scheduled task finished', ['task' => $task->label(), 'seconds' => round(microtime(true) - $start, 3)]);
                } catch (Throwable $e) {
                    $logger->error('scheduled task failed', ['task' => $task->label(), 'exception' => $e]);
                } finally {
                    if ($task->overlapTtl !== null && $redis !== null) {
                        try {
                            $redis->delete('schedule:' . $task->id() . ':running');
                        } catch (Throwable) {
                            // the lock expires on its own
                        }
                    }
                    $done->push(true);
                }
            });
        }
        foreach ($run as $ignored) {
            $done->pop();
        }

        return count($run);
    }
}
