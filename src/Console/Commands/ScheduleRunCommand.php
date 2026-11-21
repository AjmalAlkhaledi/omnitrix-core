<?php

declare(strict_types=1);

namespace Omnitrix\Console\Commands;

use DateTimeImmutable;
use Omnitrix\Console\Command;
use Omnitrix\Core\Redis\RedisClient;
use Omnitrix\Facades\Facade;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Console\Output\OutputInterface;

/** Runs the scheduled tasks due now; call it every minute: `* * * * * cd /app && php omnitrix schedule:run`. */
#[AsCommand(name: 'schedule:run', description: 'Run the scheduled tasks that are due now')]
final class ScheduleRunCommand extends Command
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $app = $this->app();
        $schedule = $app->schedule();
        $console = $this->console();
        $schedule->useCommandRunner(static fn(string $name, array $arguments): int => $console->application()->find($name)->run(new ArrayInput(['command' => $name] + $arguments), new NullOutput()));
        $ran = $app->withServices(static function () use ($schedule, $app): int {
            $container = Facade::container();
            $redis = $container !== null && $container->has(RedisClient::class) ? $container->get(RedisClient::class) : null;

            return $schedule->runDue(new DateTimeImmutable(), $redis instanceof RedisClient ? $redis : null, $app->logger());
        });
        $output->writeln($ran === 0 ? 'No scheduled tasks are due.' : "Ran {$ran} scheduled task(s).");

        return self::SUCCESS;
    }
}
