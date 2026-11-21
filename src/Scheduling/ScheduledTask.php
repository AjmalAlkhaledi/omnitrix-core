<?php

declare(strict_types=1);

namespace Omnitrix\Scheduling;

use Closure;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use InvalidArgumentException;

use function explode;
use function implode;
use function preg_match;
use function sha1;
use function sprintf;

/**
 * One scheduled task: what runs (a callback, a queued job or a console command) and when.
 * Frequency methods set the cron expression (the last call wins); `when()` / `skip()` add
 * conditions; `withoutOverlapping()` and `onOneServer()` use Redis locks.
 */
final class ScheduledTask
{
    private string $expression = '* * * * *';

    private ?DateTimeZone $timezone = null;

    /** @var list<Closure(): bool> */
    private array $filters = [];

    /** @var list<Closure(): bool> */
    private array $rejects = [];

    public ?int $overlapTtl = null;

    public bool $oneServer = false;

    private string $description = '';

    /** @param Closure(): mixed $run */
    public function __construct(public readonly string $kind, public readonly string $target, public readonly Closure $run) {}

    public function cron(string $expression): self
    {
        new CronExpression($expression); // validate
        $this->expression = $expression;

        return $this;
    }

    public function everyMinute(): self
    {
        return $this->cron('* * * * *');
    }

    public function everyTwoMinutes(): self
    {
        return $this->cron('*/2 * * * *');
    }

    public function everyFiveMinutes(): self
    {
        return $this->cron('*/5 * * * *');
    }

    public function everyTenMinutes(): self
    {
        return $this->cron('*/10 * * * *');
    }

    public function everyFifteenMinutes(): self
    {
        return $this->cron('*/15 * * * *');
    }

    public function everyThirtyMinutes(): self
    {
        return $this->cron('0,30 * * * *');
    }

    public function hourly(): self
    {
        return $this->cron('0 * * * *');
    }

    public function hourlyAt(int $minute): self
    {
        return $this->cron("{$minute} * * * *");
    }

    public function everyTwoHours(): self
    {
        return $this->cron('0 */2 * * *');
    }

    public function daily(): self
    {
        return $this->cron('0 0 * * *');
    }

    /** `dailyAt('13:30')` */
    public function dailyAt(string $time): self
    {
        [$hour, $minute] = self::time($time);

        return $this->cron("{$minute} {$hour} * * *");
    }

    public function twiceDaily(int $first = 1, int $second = 13, int $minute = 0): self
    {
        return $this->cron("{$minute} {$first},{$second} * * *");
    }

    public function weekdays(): self
    {
        return $this->days('1-5');
    }

    public function weekends(): self
    {
        return $this->days('0,6');
    }

    /** Day of week 0–6 (Sunday = 0) at `$time`. */
    public function weeklyOn(int $day, string $time = '00:00'): self
    {
        [$hour, $minute] = self::time($time);

        return $this->cron("{$minute} {$hour} * * {$day}");
    }

    public function weekly(): self
    {
        return $this->cron('0 0 * * 0');
    }

    public function monthly(): self
    {
        return $this->cron('0 0 1 * *');
    }

    public function monthlyOn(int $day = 1, string $time = '00:00'): self
    {
        [$hour, $minute] = self::time($time);

        return $this->cron("{$minute} {$hour} {$day} * *");
    }

    public function yearly(): self
    {
        return $this->cron('0 0 1 1 *');
    }

    /** Restricts the days of week of the current expression (`1-5`, `0,6`). */
    public function days(string $days): self
    {
        $parts = explode(' ', $this->expression);
        $parts[4] = $days;

        return $this->cron(implode(' ', $parts));
    }

    public function timezone(string $timezone): self
    {
        $this->timezone = new DateTimeZone($timezone);

        return $this;
    }

    /** @param callable(): bool $condition runs only when true */
    public function when(callable $condition): self
    {
        $this->filters[] = $condition(...);

        return $this;
    }

    /** @param callable(): bool $condition skips when true */
    public function skip(callable $condition): self
    {
        $this->rejects[] = $condition(...);

        return $this;
    }

    /** Skips a run while the previous one is still running (a Redis lock, released at the end or after `$minutes`). */
    public function withoutOverlapping(int $minutes = 1440): self
    {
        $this->overlapTtl = $minutes * 60;

        return $this;
    }

    /** With several servers running the scheduler, runs on only one of them each time. */
    public function onOneServer(): self
    {
        $this->oneServer = true;

        return $this;
    }

    public function description(string $description): self
    {
        $this->description = $description;

        return $this;
    }

    public function expression(): string
    {
        return $this->expression;
    }

    public function label(): string
    {
        return $this->description !== '' ? $this->description : $this->kind . ' ' . $this->target;
    }

    /** Stable id for locks. */
    public function id(): string
    {
        return sha1($this->kind . '|' . $this->target . '|' . $this->expression);
    }

    public function isDue(DateTimeInterface $now): bool
    {
        if (!(new CronExpression($this->expression))->isDue($now, $this->timezone)) {
            return false;
        }
        foreach ($this->filters as $filter) {
            if ($filter() !== true) {
                return false;
            }
        }
        foreach ($this->rejects as $reject) {
            if ($reject() === true) {
                return false;
            }
        }

        return true;
    }

    public function nextRunAfter(DateTimeInterface $now): DateTimeImmutable
    {
        return (new CronExpression($this->expression))->nextRunAfter($now, $this->timezone);
    }

    /** @return array{int, int} */
    private static function time(string $time): array
    {
        if (preg_match('/^(\d{1,2}):(\d{2})$/', $time, $m) !== 1 || (int) $m[1] > 23 || (int) $m[2] > 59) {
            throw new InvalidArgumentException(sprintf("Invalid time '%s' (HH:MM)", $time));
        }

        return [(int) $m[1], (int) $m[2]];
    }
}
