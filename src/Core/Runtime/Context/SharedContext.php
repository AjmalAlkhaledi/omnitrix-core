<?php

declare(strict_types=1);

namespace Omnitrix\Core\Runtime\Context;

use OpenSwoole\Coroutine;

use function array_key_exists;
use function is_array;

/**
 * Values that describe the current unit of work (request or job) — a tenant, a trace id, the
 * user — kept in the request context so child coroutines see them. Visible values are added to
 * every log line; both visible and hidden values travel with queued jobs and are restored in
 * the worker. Values must be JSON-compatible.
 *
 * @internal used through {@see \Omnitrix\Facades\Context}, the logger and the queue
 */
final class SharedContext
{
    private const string VISIBLE = 'omnitrix.context';

    private const string HIDDEN = 'omnitrix.context.hidden';

    private function __construct() {}

    public static function set(string $key, mixed $value, bool $hidden = false): void
    {
        $context = self::context();
        if ($context === null) {
            return;
        }
        $name = $hidden ? self::HIDDEN : self::VISIBLE;
        $values = self::read($context, $name);
        $values[$key] = $value;
        $context->set($name, $values);
    }

    public static function get(string $key, mixed $default = null, bool $hidden = false): mixed
    {
        $context = self::context();
        $values = $context === null ? [] : self::read($context, $hidden ? self::HIDDEN : self::VISIBLE);

        return array_key_exists($key, $values) ? $values[$key] : $default;
    }

    public static function has(string $key, bool $hidden = false): bool
    {
        $context = self::context();

        return $context !== null && array_key_exists($key, self::read($context, $hidden ? self::HIDDEN : self::VISIBLE));
    }

    public static function forget(string $key, bool $hidden = false): void
    {
        $context = self::context();
        if ($context === null) {
            return;
        }
        $name = $hidden ? self::HIDDEN : self::VISIBLE;
        $values = self::read($context, $name);
        unset($values[$key]);
        $context->set($name, $values);
    }

    /** @return array<string, mixed> */
    public static function all(bool $hidden = false): array
    {
        $context = self::context();

        return $context === null ? [] : self::read($context, $hidden ? self::HIDDEN : self::VISIBLE);
    }

    /** @return array{visible: array<string, mixed>, hidden: array<string, mixed>} what travels with a queued job */
    public static function dehydrate(): array
    {
        return ['visible' => self::all(), 'hidden' => self::all(hidden: true)];
    }

    /** @param array<mixed> $data */
    public static function hydrate(RequestContext $context, array $data): void
    {
        foreach (['visible' => self::VISIBLE, 'hidden' => self::HIDDEN] as $part => $name) {
            $values = [];
            foreach (is_array($data[$part] ?? null) ? $data[$part] : [] as $key => $value) {
                $values[(string) $key] = $value;
            }
            if ($values !== []) {
                $context->set($name, $values);
            }
        }
    }

    private static function context(): ?RequestContext
    {
        return Coroutine::getCid() > 0 ? ContextStorage::current() : null;
    }

    /** @return array<string, mixed> */
    private static function read(RequestContext $context, string $name): array
    {
        $values = $context->get($name, []);
        $typed = [];
        foreach (is_array($values) ? $values : [] as $key => $value) {
            $typed[(string) $key] = $value;
        }

        return $typed;
    }
}
