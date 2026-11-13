<?php

declare(strict_types=1);

namespace Omnitrix\Core\Runtime\Context;

use ArrayObject;
use OpenSwoole\Coroutine;

/**
 * Coroutine-local storage for the current {@see RequestContext}.
 *
 * Storage is the OpenSwoole per-coroutine context (an ArrayObject that dies with the
 * coroutine), so nothing is ever kept in static state. Child coroutines created with
 * `Coroutine::create()` inside a request do not get a copy automatically; {@see current()}
 * walks the parent chain (`getPcid()`) and caches the found context in the child's own
 * storage. A coroutine whose parent already finished sees no context: detached work cannot
 * silently keep using request state.
 */
final class ContextStorage
{
    private const string KEY = '__omnitrix.request_context';

    private function __construct() {}

    /** Binds a context to the current coroutine. */
    public static function bind(RequestContext $context): void
    {
        $storage = Coroutine::getContext();
        if ($storage === null) {
            throw new ContextNotAvailableException('ContextStorage::bind() must be called inside a coroutine');
        }
        $storage[self::KEY] = $context;
    }

    /** Removes the binding from the current coroutine (does not dispose the context). */
    public static function unbind(): void
    {
        $storage = Coroutine::getContext();
        if ($storage !== null) {
            unset($storage[self::KEY]);
        }
    }

    /** The context bound to this coroutine or inherited from an ancestor, or null. */
    public static function current(): ?RequestContext
    {
        $cid = Coroutine::getCid();
        if ($cid < 1) {
            return null;
        }

        $own = Coroutine::getContext();
        if ($own === null) {
            return null;
        }
        $found = self::read($own);
        if ($found !== null) {
            return $found;
        }

        // Walk ancestors; the chain is short (request coroutine → task → sub-task).
        $pcid = Coroutine::getPcid($cid);
        $depth = 0;
        while ($pcid > 0 && $depth++ < 64) {
            $storage = Coroutine::getContext($pcid);
            if ($storage === null) {
                return null; // ancestor finished: no inheritance across request end
            }
            $found = self::read($storage);
            if ($found !== null) {
                $own[self::KEY] = $found; // cache for repeated lookups in this coroutine

                return $found;
            }
            $pcid = Coroutine::getPcid($pcid);
        }

        return null;
    }

    /**
     * @throws ContextNotAvailableException
     */
    public static function require(): RequestContext
    {
        return self::current() ?? throw new ContextNotAvailableException('No request context is bound to the current coroutine');
    }

    /** @param ArrayObject<string, mixed> $storage */
    private static function read(ArrayObject $storage): ?RequestContext
    {
        $value = $storage[self::KEY] ?? null;

        return $value instanceof RequestContext ? $value : null;
    }
}
