<?php

declare(strict_types=1);

namespace Omnitrix\Console\Commands;

use LogicException;
use Omnitrix\Console\Command;
use Omnitrix\Contracts\EventDispatcher;
use Omnitrix\Core\Queue\Job;
use Omnitrix\Events\Dispatcher;
use Omnitrix\Facades\Facade;
use Omnitrix\Mail\Mailer;
use Omnitrix\Notifications\NotificationSender;
use Omnitrix\Queue\Worker;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

use function class_exists;
use function count;
use function ctype_digit;
use function is_array;
use function is_callable;
use function is_numeric;
use function is_string;
use function method_exists;

/**
 * Processes background jobs. Job names map to handlers in `config/queue.php`:
 *
 * ```php
 * return [
 *     'queue' => 'default',
 *     'concurrency' => 8,
 *     'timeout' => 60,
 *     'jobs' => ['emails.welcome' => App\Jobs\SendWelcomeEmail::class],
 * ];
 * ```
 *
 * A job class has a `handle(array $payload, Job $job): void` method. Handlers can use the
 * facades (DB, Redis, Cache, Http) exactly as route handlers do.
 */
#[AsCommand(name: 'queue:work', description: 'Process background jobs until stopped (SIGTERM/SIGINT)')]
final class QueueWorkCommand extends Command
{
    protected function configure(): void
    {
        $this->addOption('queue', null, InputOption::VALUE_REQUIRED, 'Queue name (default: queue.queue or "default")')
            ->addOption('concurrency', 'c', InputOption::VALUE_REQUIRED, 'Jobs processed at the same time')
            ->addOption('timeout', 't', InputOption::VALUE_REQUIRED, 'Seconds before a running job counts as failed');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $app = $this->app();
        $queue = $this->stringOption($input, 'queue') ?? $this->configString('queue.queue', 'default');
        $concurrency = $this->intOption($input, 'concurrency') ?? $this->configInt('queue.concurrency', 4);
        $timeout = $this->intOption($input, 'timeout') ?? $this->configInt('queue.timeout', 60);
        $jobs = $this->config('queue.jobs', []);
        if (!is_array($jobs)) {
            throw new LogicException("'jobs' in config/queue.php must map job names to handlers");
        }
        $worker = new Worker($app->config(), queue: $queue, concurrency: $concurrency, jobTimeout: (float) $timeout, logger: $app->logger());
        // Framework jobs (queued event listeners, …) are always handled.
        $worker->handle(Dispatcher::QUEUED_LISTENER_JOB, static function (array $payload): void {
            $dispatcher = Facade::container()?->get(EventDispatcher::class);
            if (!$dispatcher instanceof Dispatcher) {
                throw new LogicException('No event dispatcher in this worker');
            }
            $dispatcher->handleQueued($payload);
        });
        $worker->handle(Mailer::QUEUED_MAIL_JOB, static function (array $payload): void {
            $mailer = Facade::container()?->get(Mailer::class);
            if (!$mailer instanceof Mailer) {
                throw new LogicException('Mail is not configured in this worker (config/mail.php)');
            }
            $mailer->handleQueued($payload);
        });
        $worker->handle(NotificationSender::QUEUED_NOTIFICATION_JOB, static function (array $payload): void {
            $sender = Facade::container()?->get(NotificationSender::class);
            if (!$sender instanceof NotificationSender) {
                throw new LogicException('No notification sender in this worker');
            }
            $sender->handleQueued($payload);
        });
        foreach ($jobs as $name => $handler) {
            if (!is_string($name)) {
                throw new LogicException('Job names in config/queue.php must be strings');
            }
            $worker->handle($name, self::resolve($name, $handler));
        }
        $output->writeln("<info>Processing queue '{$queue}'</info> with " . count($jobs) . " application job type(s), concurrency {$concurrency}. Stop with Ctrl+C.");
        $code = $app->withServices(static fn(): int => $worker->run());
        $stats = $worker->stats();
        $output->writeln('Stopped. processed=' . ($stats['processed'] ?? 0) . ' failed=' . ($stats['failed'] ?? 0) . ' retried=' . ($stats['retried'] ?? 0));

        return $code;
    }

    /** @return callable(array<string, mixed>, Job): void */
    private static function resolve(string $name, mixed $handler): callable
    {
        if (is_string($handler) && class_exists($handler) && method_exists($handler, 'handle')) {
            return static function (array $payload, Job $job) use ($handler): void {
                $callable = [new $handler(), 'handle'];
                $callable($payload, $job);
            };
        }
        if (is_callable($handler)) {
            return static function (array $payload, Job $job) use ($handler): void {
                $handler($payload, $job);
            };
        }
        throw new LogicException("Handler for job '{$name}' must be a class with a handle() method or a callable");
    }

    private function stringOption(InputInterface $input, string $name): ?string
    {
        $value = $input->getOption($name);

        return is_string($value) && $value !== '' ? $value : null;
    }

    private function intOption(InputInterface $input, string $name): ?int
    {
        $value = $input->getOption($name);
        if ($value === null) {
            return null;
        }
        if (!is_string($value) || !ctype_digit($value) || (int) $value < 1) {
            throw new LogicException("--{$name} must be a positive integer");
        }

        return (int) $value;
    }

    private function configString(string $path, string $default): string
    {
        $value = $this->config($path, $default);

        return is_string($value) && $value !== '' ? $value : $default;
    }

    private function configInt(string $path, int $default): int
    {
        $value = $this->config($path, $default);

        return is_numeric($value) ? (int) $value : $default;
    }
}
