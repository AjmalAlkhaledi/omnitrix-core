<?php

declare(strict_types=1);

namespace Omnitrix\Console\Commands;

use DateTimeImmutable;
use Omnitrix\Console\Command;
use Omnitrix\Core\Concurrency\CancellationToken;
use Omnitrix\Core\Redis\RedisClient;
use Omnitrix\Core\Runtime\Coroutine\Sleep;
use Omnitrix\Core\Runtime\Shutdown\SignalHandler;
use Omnitrix\Facades\Facade;
use Omnitrix\Scheduling\Schedule;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Console\Output\OutputInterface;

use function count;
use function time;

/** Runs the scheduler in the foreground: the due tasks at the start of every minute, until SIGTERM/SIGINT. */
#[AsCommand(name: 'schedule:work', description: 'Run scheduled tasks every minute until stopped')]
final class ScheduleWorkCommand extends Command
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $app = $this->app();
        $schedule = $app->schedule();
        $console = $this->console();
        $schedule->useCommandRunner(static fn(string $name, array $arguments): int => $console->application()->find($name)->run(new ArrayInput(['command' => $name] + $arguments), new NullOutput()));
        $output->writeln('<info>Scheduler running</info> (' . count($schedule->tasks()) . ' task(s)). Stop with Ctrl+C.');

        return $app->withServices(static function () use ($schedule, $app): int {
            $stop = new CancellationToken();
            $signals = new SignalHandler();
            $signals->onTerminate(static function () use ($stop): void {
                $stop->cancel('signal');
            });
            $container = Facade::container();
            $redis = $container !== null && $container->has(RedisClient::class) ? $container->get(RedisClient::class) : null;
            try {
                self::loop($schedule, $stop, $redis instanceof RedisClient ? $redis : null, $app->logger());
            } finally {
                $signals->release();
            }

            return self::SUCCESS;
        });
    }

    private static function loop(Schedule $schedule, CancellationToken $stop, ?RedisClient $redis, LoggerInterface $logger): void
    {
        while (true) {
            for ($second = 60 - (time() % 60); $second > 0; $second--) { // to the start of the next minute
                if (!self::sleepUnlessStopped($stop)) {
                    return;
                }
            }
            $schedule->runDue(new DateTimeImmutable(), $redis, $logger);
        }
    }

    /** Sleeps one second; false once the scheduler was asked to stop. */
    private static function sleepUnlessStopped(CancellationToken $stop): bool
    {
        if (!self::running($stop)) {
            return false;
        }
        Sleep::for(1.0);

        return self::running($stop); // a signal may have arrived while sleeping
    }

    private static function running(CancellationToken $stop): bool
    {
        return !$stop->isCancelled();
    }
}
