# Testing Applications

Code that uses coroutines, pools or hooked I/O must run inside an OpenSwoole coroutine
container. The framework ships `vendor/bin/omnitrix-phpunit`, a drop-in PHPUnit runner that
starts one root coroutine with all runtime hooks enabled and runs PHPUnit inside it:

```sh
vendor/bin/omnitrix-phpunit                 # same arguments as vendor/bin/phpunit
vendor/bin/omnitrix-phpunit --testsuite unit
```

Install PHPUnit in your application (`composer require --dev phpunit/phpunit`).

## Testing handlers without a server

Routes can be exercised by starting the application in a child process and sending real HTTP
requests, which tests the whole stack. For fast feedback, keep business logic in plain classes
and test it directly; inside `omnitrix-phpunit` tests may use `Async`, the database layer and
the HTTP client as they would in a handler.

```php
use Omnitrix\Facades\Async;
use PHPUnit\Framework\TestCase;

final class PricingTest extends TestCase
{
    public function testQuotesAreFetchedConcurrently(): void
    {
        $start = microtime(true);
        $results = Async::all([
            'a' => static function (): int { Async::sleep(0.2); return 1; },
            'b' => static function (): int { Async::sleep(0.2); return 2; },
        ]);

        self::assertSame(['a' => 1, 'b' => 2], $results);
        self::assertLessThan(0.35, microtime(true) - $start);
    }
}
```

## Integration tests against a running server

```php
use PHPUnit\Framework\TestCase;

final class ApiTest extends TestCase
{
    private static $process;

    public static function setUpBeforeClass(): void
    {
        self::$process = proc_open([PHP_BINARY, __DIR__ . '/../public/server.php'], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, null, ['PORT' => '18081'] + getenv());
        // Poll the port until it accepts connections.
    }

    public static function tearDownAfterClass(): void
    {
        proc_terminate(self::$process, SIGTERM);   // graceful shutdown
        proc_close(self::$process);
    }
}
```

Facades are bound to the worker container; outside a running server, configure the services
your test needs explicitly or test through HTTP.
