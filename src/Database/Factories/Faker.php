<?php

declare(strict_types=1);

namespace Omnitrix\Database\Factories;

use DateTimeImmutable;
use InvalidArgumentException;
use OverflowException;
use Random\Engine\Mt19937;
use Random\Randomizer;

use function array_map;
use function bin2hex;
use function count;
use function implode;
use function max;
use function min;
use function round;
use function rtrim;
use function serialize;
use function sprintf;
use function str_pad;
use function strlen;
use function strtolower;
use function substr;
use function ucfirst;

use const STR_PAD_LEFT;

/**
 * Fake data for factories and seeders, without external packages. Seed it for repeatable data
 * (`new Faker(42)`); `unique()` returns a generator that never repeats a value.
 */
class Faker
{
    private const array FIRST = ['Ann', 'Bob', 'Cleo', 'Dan', 'Eva', 'Finn', 'Grace', 'Hugo', 'Iris', 'Jack', 'Kira', 'Leo', 'Maya', 'Noah', 'Olga', 'Paul', 'Quinn', 'Rosa', 'Sam', 'Tara', 'Umar', 'Vera', 'Will', 'Xena', 'Yara', 'Zane', 'Amir', 'Lina', 'Omar', 'Sara'];

    private const array LAST = ['Adams', 'Baker', 'Clark', 'Davis', 'Evans', 'Fischer', 'Garcia', 'Haddad', 'Ito', 'Jensen', 'Khan', 'Lopez', 'Moreau', 'Nakamura', 'Olsen', 'Petrov', 'Rossi', 'Schmidt', 'Tanaka', 'Weber', 'Young', 'Zimmer', 'Hassan', 'Kowalski', 'Novak'];

    private const array WORDS = ['alpha', 'amber', 'anchor', 'aurora', 'basin', 'beacon', 'birch', 'breeze', 'canyon', 'cedar', 'cloud', 'comet', 'coral', 'delta', 'dune', 'ember', 'falcon', 'fern', 'field', 'forest', 'frost', 'garden', 'glacier', 'harbor', 'hill', 'horizon', 'island', 'lake', 'lantern', 'meadow', 'mesa', 'moon', 'nova', 'ocean', 'orchid', 'pebble', 'pine', 'planet', 'prairie', 'quartz', 'raven', 'reef', 'ridge', 'river', 'sable', 'shore', 'sky', 'spark', 'stone', 'summit', 'thunder', 'tide', 'valley', 'willow', 'wind', 'zenith'];

    private const array CITIES = ['Amman', 'Berlin', 'Cairo', 'Dubai', 'Lisbon', 'London', 'Madrid', 'Nairobi', 'Oslo', 'Paris', 'Rome', 'Seoul', 'Sydney', 'Tokyo', 'Toronto', 'Vienna'];

    private const array COUNTRIES = ['Austria', 'Canada', 'Egypt', 'France', 'Germany', 'Italy', 'Japan', 'Jordan', 'Kenya', 'Norway', 'Portugal', 'South Korea', 'Spain', 'United Arab Emirates', 'United Kingdom', 'Australia'];

    private const array STREETS = ['Main Street', 'Oak Avenue', 'Park Road', 'River Lane', 'Station Road', 'Hill Street', 'Garden Way', 'Lake Drive'];

    private readonly Randomizer $random;

    /** @var array<string, true> */
    private array $seen = [];

    private bool $unique = false;

    private ?self $uniqueInstance = null;

    public function __construct(?int $seed = null)
    {
        $this->random = $seed === null ? new Randomizer() : new Randomizer(new Mt19937($seed));
    }

    /** A generator whose values never repeat (until it runs out after many tries). */
    public function unique(): self
    {
        if ($this->unique) {
            return $this;
        }
        if ($this->uniqueInstance === null) {
            $this->uniqueInstance = clone $this;
            $this->uniqueInstance->unique = true;
        }

        return $this->uniqueInstance;
    }

    public function firstName(): string
    {
        return $this->pick(self::FIRST);
    }

    public function lastName(): string
    {
        return $this->pick(self::LAST);
    }

    public function name(): string
    {
        return $this->distinct(fn(): string => $this->pick(self::FIRST) . ' ' . $this->pick(self::LAST));
    }

    public function userName(): string
    {
        return $this->distinct(fn(): string => strtolower($this->pick(self::FIRST)) . '.' . strtolower($this->pick(self::LAST)) . $this->numberBetween(1, 999));
    }

    /** Addresses on example.com / example.org / example.net (reserved: mail never reaches anyone). */
    public function email(): string
    {
        return $this->distinct(fn(): string => strtolower($this->pick(self::FIRST)) . '.' . strtolower($this->pick(self::LAST)) . $this->numberBetween(1, 99999) . '@' . $this->pick(['example.com', 'example.org', 'example.net']));
    }

    public function safeEmail(): string
    {
        return $this->email();
    }

    public function password(int $length = 16): string
    {
        return substr(bin2hex($this->random->getBytes(max(8, $length))), 0, max(8, $length));
    }

    public function word(): string
    {
        return $this->distinct(fn(): string => $this->pick(self::WORDS));
    }

    /** @return list<string> */
    public function words(int $count = 3): array
    {
        $words = [];
        for ($i = 0; $i < $count; $i++) {
            $words[] = $this->pick(self::WORDS);
        }

        return $words;
    }

    public function sentence(int $words = 6): string
    {
        return $this->distinct(fn(): string => ucfirst(implode(' ', $this->words(max(1, $words)))) . '.');
    }

    public function paragraph(int $sentences = 3): string
    {
        $parts = [];
        for ($i = 0; $i < max(1, $sentences); $i++) {
            $parts[] = ucfirst(implode(' ', $this->words($this->numberBetween(4, 10)))) . '.';
        }

        return implode(' ', $parts);
    }

    public function text(int $maxCharacters = 200): string
    {
        $text = '';
        while (strlen($text) < $maxCharacters) {
            $text .= ($text === '' ? '' : ' ') . ucfirst(implode(' ', $this->words($this->numberBetween(4, 10)))) . '.';
        }

        return rtrim(substr($text, 0, $maxCharacters), ' ');
    }

    public function slug(int $words = 3): string
    {
        return $this->distinct(fn(): string => implode('-', $this->words(max(1, $words))));
    }

    public function numberBetween(int $min = 0, int $max = 1000): int
    {
        if ($min > $max) {
            throw new InvalidArgumentException('numberBetween() needs min <= max');
        }

        return $this->random->getInt($min, $max);
    }

    public function randomDigit(): int
    {
        return $this->numberBetween(0, 9);
    }

    public function randomFloat(int $decimals = 2, float $min = 0.0, float $max = 1000.0): float
    {
        return round($min + $this->random->getFloat(0.0, 1.0) * ($max - $min), $decimals);
    }

    public function boolean(int $chanceOfTrue = 50): bool
    {
        return $this->numberBetween(1, 100) <= $chanceOfTrue;
    }

    /**
     * @template T
     *
     * @param list<T> $values
     *
     * @return T
     */
    public function randomElement(array $values): mixed
    {
        if ($values === []) {
            throw new InvalidArgumentException('randomElement() needs values');
        }

        return $this->distinct(fn(): mixed => $values[$this->random->getInt(0, count($values) - 1)]);
    }

    /** A version 4 UUID. */
    public function uuid(): string
    {
        $hex = bin2hex($this->random->getBytes(16));

        return sprintf('%s-%s-4%s-%s%s-%s', substr($hex, 0, 8), substr($hex, 8, 4), substr($hex, 13, 3), '89ab'[$this->random->getInt(0, 3)], substr($hex, 17, 3), substr($hex, 20, 12));
    }

    public function dateTimeBetween(string $start = '-1 year', string $end = 'now'): DateTimeImmutable
    {
        $from = (new DateTimeImmutable($start))->getTimestamp();
        $to = (new DateTimeImmutable($end))->getTimestamp();

        return (new DateTimeImmutable())->setTimestamp($this->numberBetween(min($from, $to), max($from, $to)));
    }

    public function date(string $format = 'Y-m-d'): string
    {
        return $this->dateTimeBetween('-10 years')->format($format);
    }

    public function city(): string
    {
        return $this->pick(self::CITIES);
    }

    public function country(): string
    {
        return $this->pick(self::COUNTRIES);
    }

    public function streetAddress(): string
    {
        return $this->numberBetween(1, 300) . ' ' . $this->pick(self::STREETS);
    }

    public function postcode(): string
    {
        return str_pad((string) $this->numberBetween(1000, 99999), 5, '0', STR_PAD_LEFT);
    }

    /** A number in the reserved fictional range +1 555 01xx. */
    public function phoneNumber(): string
    {
        return $this->distinct(fn(): string => '+1-555-01' . str_pad((string) $this->numberBetween(0, 99), 2, '0', STR_PAD_LEFT));
    }

    public function url(): string
    {
        return 'https://' . $this->pick(['example.com', 'example.org', 'example.net']) . '/' . $this->slug(2);
    }

    public function company(): string
    {
        return ucfirst($this->pick(self::WORDS)) . ' ' . $this->pick(['Labs', 'Systems', 'Group', 'Works', 'Studio', 'Partners']);
    }

    public function hexColor(): string
    {
        return '#' . bin2hex($this->random->getBytes(3));
    }

    /** A capitalised title of `$words` words. */
    public function title(int $words = 3): string
    {
        return ucfirst(implode(' ', array_map(static fn(string $w): string => ucfirst($w), $this->words($words))));
    }

    /** @param list<string> $values */
    private function pick(array $values): string
    {
        return $values[$this->random->getInt(0, count($values) - 1)];
    }

    /**
     * @template T
     *
     * @param callable(): T $make
     *
     * @return T
     */
    private function distinct(callable $make): mixed
    {
        if (!$this->unique) {
            return $make();
        }
        for ($i = 0; $i < 10000; $i++) {
            $value = $make();
            $key = serialize($value);
            if (!isset($this->seen[$key])) {
                $this->seen[$key] = true;

                return $value;
            }
        }
        throw new OverflowException('No more unique values');
    }
}
