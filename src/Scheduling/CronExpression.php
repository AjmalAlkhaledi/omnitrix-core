<?php

declare(strict_types=1);

namespace Omnitrix\Scheduling;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use InvalidArgumentException;

use function count;
use function ctype_digit;
use function explode;
use function is_numeric;
use function preg_split;
use function range;
use function str_contains;
use function strtolower;
use function trim;

/**
 * Standard five-field cron expressions: minute hour day-of-month month day-of-week, with `*`,
 * lists (`1,15`), ranges (`1-5`), steps (`*\/10`, `0-30/5`) and names (`jan`, `mon`).
 * As in cron, when both day fields are restricted a day matching either runs.
 */
final readonly class CronExpression
{
    private const array MONTHS = ['jan' => 1, 'feb' => 2, 'mar' => 3, 'apr' => 4, 'may' => 5, 'jun' => 6, 'jul' => 7, 'aug' => 8, 'sep' => 9, 'oct' => 10, 'nov' => 11, 'dec' => 12];

    private const array DAYS = ['sun' => 0, 'mon' => 1, 'tue' => 2, 'wed' => 3, 'thu' => 4, 'fri' => 5, 'sat' => 6];

    /** @var array{array<int, true>, array<int, true>, array<int, true>, array<int, true>, array<int, true>} */
    private array $fields;

    private bool $anyDayOfMonth;

    private bool $anyDayOfWeek;

    public function __construct(public string $expression)
    {
        $parts = preg_split('/\s+/', trim($expression));
        if ($parts === false || count($parts) !== 5) {
            throw new InvalidArgumentException("Invalid cron expression '{$expression}' (five fields expected)");
        }
        $this->fields = [
            self::parse($parts[0], 0, 59, []),
            self::parse($parts[1], 0, 23, []),
            self::parse($parts[2], 1, 31, []),
            self::parse($parts[3], 1, 12, self::MONTHS),
            self::sundays(self::parse($parts[4], 0, 7, self::DAYS)),
        ];
        $this->anyDayOfMonth = $parts[2] === '*';
        $this->anyDayOfWeek = $parts[4] === '*';
    }

    /** Whether the expression matches the minute of `$time` (in `$timezone`). */
    public function isDue(DateTimeInterface $time, ?DateTimeZone $timezone = null): bool
    {
        $local = DateTimeImmutable::createFromInterface($time);
        if ($timezone !== null) {
            $local = $local->setTimezone($timezone);
        }
        [$minutes, $hours, $days, $months, $weekdays] = $this->fields;
        if (!isset($minutes[(int) $local->format('i')], $hours[(int) $local->format('G')], $months[(int) $local->format('n')])) {
            return false;
        }
        $dayOfMonth = isset($days[(int) $local->format('j')]);
        $dayOfWeek = isset($weekdays[(int) $local->format('w')]);
        if ($this->anyDayOfMonth || $this->anyDayOfWeek) {
            return $dayOfMonth && $dayOfWeek;
        }

        return $dayOfMonth || $dayOfWeek;
    }

    /** The first matching minute after `$after` (searching up to about four years). */
    public function nextRunAfter(DateTimeInterface $after, ?DateTimeZone $timezone = null): DateTimeImmutable
    {
        $time = DateTimeImmutable::createFromInterface($after);
        $time = $time->setTime((int) $time->format('G'), (int) $time->format('i'))->modify('+1 minute');
        for ($i = 0; $i < 2_200_000; $i++) {
            if ($this->isDue($time, $timezone)) {
                return $time;
            }
            $time = $time->modify('+1 minute');
        }
        throw new InvalidArgumentException("Cron expression '{$this->expression}' never matches");
    }

    /**
     * Day 7 is Sunday too.
     *
     * @param array<int, true> $days
     *
     * @return array<int, true>
     */
    private static function sundays(array $days): array
    {
        if (isset($days[7])) {
            unset($days[7]);
            $days[0] = true;
        }

        return $days;
    }

    /**
     * @param array<string, int> $names
     *
     * @return array<int, true>
     */
    private static function parse(string $field, int $min, int $max, array $names): array
    {
        $values = [];
        foreach (explode(',', strtolower($field)) as $part) {
            $step = 1;
            if (str_contains($part, '/')) {
                [$part, $stepText] = explode('/', $part, 2);
                if (!ctype_digit($stepText) || (int) $stepText < 1) {
                    throw new InvalidArgumentException("Invalid step in cron field '{$field}'");
                }
                $step = (int) $stepText;
            }
            if ($part === '*') {
                [$from, $to] = [$min, $max];
            } elseif (str_contains($part, '-')) {
                [$a, $b] = explode('-', $part, 2);
                [$from, $to] = [self::value($a, $names, $field), self::value($b, $names, $field)];
            } else {
                $from = self::value($part, $names, $field);
                $to = $step > 1 ? $max : $from;
            }
            if ($from < $min || $to > $max || $from > $to) {
                throw new InvalidArgumentException("Cron field '{$field}' is out of range {$min}-{$max}");
            }
            foreach (range($from, $to, $step) as $value) {
                $values[$value] = true;
            }
        }

        return $values;
    }

    /** @param array<string, int> $names */
    private static function value(string $text, array $names, string $field): int
    {
        if (isset($names[$text])) {
            return $names[$text];
        }
        if (!is_numeric($text) || !ctype_digit($text)) {
            throw new InvalidArgumentException("Invalid value '{$text}' in cron field '{$field}'");
        }

        return (int) $text;
    }
}
