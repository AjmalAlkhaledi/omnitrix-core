<?php

declare(strict_types=1);

namespace Omnitrix\Session;

use Omnitrix\Support\Collection;

use function array_filter;
use function array_key_exists;
use function array_merge;
use function array_unique;
use function array_values;
use function bin2hex;
use function in_array;
use function is_array;
use function is_string;
use function preg_match;
use function random_bytes;

/**
 * The session of one request: attributes, flash data (kept for the next request only) and the
 * CSRF token. Created by {@see StartSession}; reach it with `Session::…` or
 * `$request->attribute('session')`.
 */
final class Store
{
    private const string FLASH_NEW = '_flash.new';

    private const string FLASH_OLD = '_flash.old';

    private const string TOKEN = '_token';

    private bool $started = false;

    private bool $dirty = false;

    /** Previous id to destroy after a regeneration. */
    private ?string $replaced = null;

    private bool $invalidated = false;

    /** @param array<string, mixed> $attributes */
    public function __construct(private string $id, private array $attributes = [], private readonly bool $existed = false) {}

    public static function generateId(): string
    {
        return bin2hex(random_bytes(20));
    }

    public static function isValidId(string $id): bool
    {
        return preg_match('/^[a-f0-9]{40}$/', $id) === 1;
    }

    public function id(): string
    {
        return $this->id;
    }

    /** Whether the session existed before this request (its cookie was valid). */
    public function existed(): bool
    {
        return $this->existed;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return Collection::dataGet($this->attributes, $key, $default);
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->attributes) && $this->attributes[$key] !== null;
    }

    public function put(string $key, mixed $value): void
    {
        $this->attributes[$key] = $value;
        $this->touch();
    }

    /** @param array<string, mixed> $values */
    public function putMany(array $values): void
    {
        foreach ($values as $key => $value) {
            $this->put($key, $value);
        }
    }

    public function push(string $key, mixed $value): void
    {
        $list = $this->attributes[$key] ?? [];
        $list = is_array($list) ? array_values($list) : [$list];
        $list[] = $value;
        $this->put($key, $list);
    }

    /** Reads and removes a value. */
    public function pull(string $key, mixed $default = null): mixed
    {
        $value = $this->attributes[$key] ?? $default;
        $this->forget($key);

        return $value;
    }

    public function forget(string ...$keys): void
    {
        foreach ($keys as $key) {
            unset($this->attributes[$key]);
        }
        $this->touch();
    }

    /** Removes every attribute (the session and its id stay). */
    public function flush(): void
    {
        $this->attributes = [];
        $this->touch();
    }

    /** @return array<string, mixed> attributes without the internal ones */
    public function all(): array
    {
        $all = $this->attributes;
        unset($all[self::FLASH_NEW], $all[self::FLASH_OLD], $all[self::TOKEN]);

        return $all;
    }

    /** A value available in this request and the next one only. */
    public function flash(string $key, mixed $value): void
    {
        $this->put($key, $value);
        $new = $this->list(self::FLASH_NEW);
        $new[] = $key;
        $this->attributes[self::FLASH_NEW] = array_values(array_unique($new));
        $this->attributes[self::FLASH_OLD] = array_values(array_filter($this->list(self::FLASH_OLD), static fn(string $k): bool => $k !== $key));
    }

    /** Keeps the current flash data for one more request. */
    public function reflash(): void
    {
        $this->attributes[self::FLASH_NEW] = array_values(array_unique(array_merge($this->list(self::FLASH_NEW), $this->list(self::FLASH_OLD))));
        $this->attributes[self::FLASH_OLD] = [];
        $this->touch();
    }

    /** @internal called at the end of the request: last request's flash data is removed. */
    public function ageFlashData(): void
    {
        foreach ($this->list(self::FLASH_OLD) as $key) {
            unset($this->attributes[$key]);
        }
        if ($this->list(self::FLASH_OLD) !== [] || $this->list(self::FLASH_NEW) !== []) {
            $this->touch();
        }
        $this->attributes[self::FLASH_OLD] = $this->list(self::FLASH_NEW);
        $this->attributes[self::FLASH_NEW] = [];
    }

    /** The CSRF token of this session (created on first use). */
    public function token(): string
    {
        $token = $this->attributes[self::TOKEN] ?? null;
        if (!is_string($token) || $token === '') {
            $token = bin2hex(random_bytes(20));
            $this->put(self::TOKEN, $token);
        }

        return $token;
    }

    public function regenerateToken(): string
    {
        unset($this->attributes[self::TOKEN]);

        return $this->token();
    }

    /**
     * Gives the session a new id, keeping its data (call it after login to prevent session
     * fixation); `$destroy` removes the old id's data at once.
     */
    public function regenerate(bool $destroy = true): void
    {
        if ($destroy && !$this->invalidated) {
            $this->replaced = $this->id;
        }
        $this->id = self::generateId();
        $this->touch();
    }

    /** Removes all data and starts a new, empty session with a new id (logout). */
    public function invalidate(): void
    {
        $this->attributes = [];
        $this->regenerate();
        $this->invalidated = true;
    }

    /** @internal */
    public function isDirty(): bool
    {
        return $this->dirty;
    }

    /** @internal whether anything used the session (it is then saved and its cookie sent) */
    public function isStarted(): bool
    {
        return $this->started || $this->existed;
    }

    /** @internal */
    public function replacedId(): ?string
    {
        return $this->replaced;
    }

    /**
     * @internal
     *
     * @return array<string, mixed>
     */
    public function raw(): array
    {
        return $this->attributes;
    }

    private function touch(): void
    {
        $this->dirty = true;
        $this->started = true;
    }

    /** @return list<string> */
    private function list(string $key): array
    {
        $value = $this->attributes[$key] ?? [];
        $list = [];
        foreach (is_array($value) ? $value : [] as $item) {
            if (is_string($item) && !in_array($item, $list, true)) {
                $list[] = $item;
            }
        }

        return $list;
    }
}
