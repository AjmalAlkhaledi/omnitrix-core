<?php

declare(strict_types=1);

namespace Omnitrix\Core\Runtime\Coroutine;

use Omnitrix\Core\Runtime\Context\ContextStorage;
use Omnitrix\Core\Runtime\Exception\ExceptionHandlerInterface;
use OpenSwoole\Coroutine;
use RuntimeException;
use Throwable;

/**
 * Guarded coroutine creation.
 *
 * An uncaught exception inside any OpenSwoole coroutine is a fatal error that terminates the
 * worker process. Every coroutine the framework starts goes
 * through {@see spawn()}, which routes failures to the exception handler instead and binds the
 * caller's {@see \Omnitrix\Core\Runtime\Context\RequestContext} into the child explicitly, so the
 * child keeps it even if the spawning coroutine finishes first.
 */
final class Coroutines
{
    private function __construct() {}

    /**
     * @param callable(): void $body
     * @param array<string, mixed> $context diagnostic context forwarded to the handler on failure
     *
     * @return int coroutine id
     */
    public static function spawn(callable $body, ExceptionHandlerInterface $handler, array $context = []): int
    {
        $inherited = ContextStorage::current();
        $cid = Coroutine::create(static function () use ($body, $handler, $context, $inherited): void {
            if ($inherited !== null) {
                ContextStorage::bind($inherited);
            }
            try {
                $body();
            } catch (Throwable $e) {
                $handler->handle($e, $context + ['origin' => 'coroutine', 'cid' => Coroutine::getCid()]);
            }
        });

        if ($cid === false) {
            throw new RuntimeException('Failed to create coroutine (max_coroutine reached or no reactor)');
        }

        return $cid;
    }
}
