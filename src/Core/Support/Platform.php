<?php

declare(strict_types=1);

namespace Omnitrix\Core\Support;

use OpenSwoole\Coroutine;

use function defined;
use function extension_loaded;
use function is_string;
use function php_uname;
use function phpversion;
use function preg_match;
use function sprintf;
use function version_compare;

use const PHP_OS_FAMILY;
use const PHP_VERSION;

/**
 * Read-only facts about the runtime the framework is executing on.
 *
 * Every check here is a plain function of the current process; nothing is cached across
 * workers and nothing mutates global state, so it is safe to call from any scope.
 */
final class Platform
{
    /** Minimum OpenSwoole release the framework is verified against (see ADR-001). */
    public const string MIN_OPENSWOOLE_VERSION = '26.2.0';

    /** Minimum PHP release accepted by the framework. */
    public const string MIN_PHP_VERSION = '8.3.0';

    /** First Linux kernel with io_uring; OpenSwoole aborts instead of falling back below it. */
    public const string MIN_IO_URING_KERNEL = '5.1';

    private function __construct() {}

    public static function phpVersion(): string
    {
        return PHP_VERSION;
    }

    public static function hasOpenSwoole(): bool
    {
        return extension_loaded('openswoole');
    }

    public static function openSwooleVersion(): ?string
    {
        $version = phpversion('openswoole');

        return is_string($version) ? $version : null;
    }

    /**
     * Kernel release string such as "4.14.190" (Linux) or null when not determinable.
     */
    public static function kernelVersion(): ?string
    {
        if (PHP_OS_FAMILY !== 'Linux') {
            return null;
        }

        return preg_match('/^(\d+\.\d+(?:\.\d+)?)/', php_uname('r'), $m) === 1 ? $m[1] : null;
    }

    /**
     * Whether the io_uring reactor can be *requested* safely: the extension exposes the
     * constant and the kernel is recent enough. On older kernels the
     * reactor fails with a fatal error rather than falling back to epoll.
     */
    public static function supportsIoUring(): bool
    {
        if (!defined('OPENSWOOLE_IO_URING')) {
            return false;
        }

        $kernel = self::kernelVersion();

        return $kernel !== null && version_compare($kernel, self::MIN_IO_URING_KERNEL, '>=');
    }

    public static function inCoroutine(): bool
    {
        return self::hasOpenSwoole() && Coroutine::getCid() > 0;
    }

    /**
     * @throws RuntimeRequirementException when PHP or OpenSwoole are missing or too old
     */
    public static function assertSupported(): void
    {
        if (version_compare(PHP_VERSION, self::MIN_PHP_VERSION, '<')) {
            throw new RuntimeRequirementException(sprintf(
                'Omnitrix requires PHP >= %s, running %s.',
                self::MIN_PHP_VERSION,
                PHP_VERSION,
            ));
        }

        $openSwoole = self::openSwooleVersion();

        if ($openSwoole === null) {
            throw new RuntimeRequirementException('Omnitrix requires the openswoole extension (>= ' . self::MIN_OPENSWOOLE_VERSION . ').');
        }

        if (version_compare($openSwoole, self::MIN_OPENSWOOLE_VERSION, '<')) {
            throw new RuntimeRequirementException(sprintf(
                'Omnitrix requires openswoole >= %s, loaded %s.',
                self::MIN_OPENSWOOLE_VERSION,
                $openSwoole,
            ));
        }
    }
}
