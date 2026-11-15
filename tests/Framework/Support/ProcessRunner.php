<?php

declare(strict_types=1);

namespace Omnitrix\Tests\Support;

use OpenSwoole\Coroutine\Client;
use RuntimeException;

use function array_merge;
use function array_slice;
use function file_exists;
use function file_get_contents;
use function getenv;
use function hrtime;
use function is_resource;
use function microtime;
use function proc_close;
use function proc_get_status;
use function proc_open;
use function proc_terminate;
use function str_starts_with;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;
use function usleep;

use const OPENSWOOLE_SOCK_TCP;
use const PHP_BINARY;
use const SIGKILL;
use const SIGTERM;

/**
 * Starts a child process (fixture server, worker, tool) from inside a coroutine test.
 *
 * stdout/stderr go to temporary files, not pipes: a pipe that the test does not drain fills up
 * (64 KiB) and blocks the child on its next write — with a server that logs per request this
 * stalls workers mid-request. Waiting uses hooked usleep, so sibling
 * coroutines keep running.
 */
final class ProcessRunner
{
    /** @var resource */
    private $process;

    private readonly string $stdoutFile;

    private readonly string $stderrFile;

    private ?int $exitCode = null;

    /**
     * @param list<string>          $args
     * @param array<string, string> $env
     */
    public function __construct(string $script, array $args = [], array $env = [], ?string $binary = PHP_BINARY)
    {
        $command = array_merge($binary === null ? [$script] : [$binary, $script], $args);
        $environment = array_merge(self::inheritedEnv(), $env);
        $this->stdoutFile = self::tempFile('out');
        $this->stderrFile = self::tempFile('err');
        $process = proc_open($command, [1 => ['file', $this->stdoutFile, 'w'], 2 => ['file', $this->stderrFile, 'w']], $pipes, null, $environment);
        if (!is_resource($process)) {
            throw new RuntimeException("Failed to start {$script}");
        }
        $this->process = $process;
    }

    /**
     * Runs an arbitrary executable (no PHP prefix).
     *
     * @param list<string>          $argv
     * @param array<string, string> $env
     */
    public static function command(array $argv, array $env = []): self
    {
        $executable = $argv[0] ?? throw new RuntimeException('command requires an executable');

        return new self($executable, array_slice($argv, 1), $env, null);
    }

    public function pid(): int
    {
        return proc_get_status($this->process)['pid'];
    }

    public function isRunning(): bool
    {
        if ($this->exitCode !== null) {
            return false;
        }

        return proc_get_status($this->process)['running'];
    }

    /** Waits until a TCP port accepts connections or throws after the timeout. */
    public function waitForPort(string $host, int $port, float $timeout = 10.0): void
    {
        $deadline = microtime(true) + $timeout;
        while (microtime(true) < $deadline) {
            if (!$this->isRunning()) {
                throw new RuntimeException("Process exited (code {$this->wait(1.0)}) before listening on {$host}:{$port}\nstderr: " . $this->stderr());
            }
            $client = new Client(OPENSWOOLE_SOCK_TCP);
            $ok = $client->connect($host, $port, 0.5);
            $client->close();
            if ($ok) {
                return;
            }
            usleep(50_000);
        }
        throw new RuntimeException("Timed out waiting for {$host}:{$port}\nstderr: " . $this->stderr());
    }

    public function signal(int $signal): void
    {
        proc_terminate($this->process, $signal);
    }

    /** Sends SIGTERM and waits; escalates to SIGKILL after the timeout. */
    public function stop(float $timeout = 10.0): int
    {
        if ($this->isRunning()) {
            $this->signal(SIGTERM);
        }
        $code = $this->wait($timeout);
        if ($code === null) {
            $this->signal(SIGKILL);
            $code = $this->wait(5.0) ?? -1;
        }

        return $code;
    }

    /** Waits up to $timeout seconds for exit; returns the exit code or null if still running. */
    public function wait(float $timeout): ?int
    {
        $deadline = hrtime(true) + (int) ($timeout * 1e9);
        while ($this->exitCode === null) {
            $status = proc_get_status($this->process);
            if (!$status['running']) {
                $code = $status['exitcode'];
                // Under the proc hook proc_close() may return the raw wait status (code << 8).
                $closed = proc_close($this->process);
                if ($code < 0 && $closed >= 0) {
                    $code = $closed > 255 ? $closed >> 8 : $closed;
                }
                $this->exitCode = $code;
                break;
            }
            if (hrtime(true) > $deadline) {
                return null;
            }
            usleep(20_000);
        }

        return $this->exitCode;
    }

    public function stdout(): string
    {
        return (string) @file_get_contents($this->stdoutFile);
    }

    public function stderr(): string
    {
        return (string) @file_get_contents($this->stderrFile);
    }

    public function __destruct()
    {
        if ($this->exitCode === null) {
            $this->stop(3.0);
        }
        foreach ([$this->stdoutFile, $this->stderrFile] as $file) {
            if (file_exists($file)) {
                @unlink($file);
            }
        }
    }

    private static function tempFile(string $kind): string
    {
        $file = tempnam(sys_get_temp_dir(), 'omnitrix-proc-' . $kind . '-');
        if ($file === false) {
            throw new RuntimeException('Cannot create a temporary file for process output');
        }

        return $file;
    }

    /** @return array<string, string> */
    private static function inheritedEnv(): array
    {
        $env = [];
        foreach (['PATH', 'HOME', 'TMPDIR', 'LANG'] as $name) {
            $value = getenv($name);
            if ($value !== false) {
                $env[$name] = $value;
            }
        }
        // Test service settings (OMNITRIX_TEST_DB_ENGINE, ports...) reach bin/services and fixtures.
        foreach (getenv() as $name => $value) {
            if (str_starts_with($name, 'OMNITRIX_TEST_')) {
                $env[$name] = $value;
            }
        }

        return $env;
    }
}
