<?php

declare(strict_types=1);

namespace Omnitrix\Tests\Integration\Database;

use Omnitrix\Core\Database\Config\DatabaseConfig;
use Omnitrix\Core\Database\Database;
use Omnitrix\Core\Database\Driver\MySQL\MySqlDriver;
use Omnitrix\Core\Database\Exception\ConnectionException;
use Omnitrix\Tests\Support\ProcessRunner;
use Omnitrix\Tests\Support\Services;
use Omnitrix\Tests\Support\TestCase;
use OpenSwoole\Coroutine\Http\Client as HttpClient;
use PHPUnit\Framework\Attributes\CoversNothing;

use function array_unique;
use function count;
use function dirname;
use function is_array;
use function is_int;
use function is_string;
use function json_decode;
use function json_encode;

/** Concurrent independent HTTP requests using the DB facade: isolation, pinning, pool reuse. */
#[CoversNothing]
final class DatabaseOverHttpTest extends TestCase
{
    private const string HOST = '127.0.0.1';

    private const int PORT = 18350;

    private static ?ProcessRunner $server = null;

    private static ?Database $admin = null;

    public static function setUpBeforeClass(): void
    {
        try {
            self::$admin = new Database(DatabaseConfig::fromArray(['driver' => 'mariadb', 'host' => Services::dbHost(), 'port' => Services::dbPort(), 'database' => Services::dbName(), 'username' => Services::dbUser(), 'password' => Services::dbPassword(), 'pool' => ['min' => 0, 'max' => 1]], 'admin'), new MySqlDriver());
            self::$admin->execute('DROP TABLE IF EXISTS omni_http_users');
            self::$admin->execute('CREATE TABLE omni_http_users (id INT AUTO_INCREMENT PRIMARY KEY, email VARCHAR(190) NOT NULL UNIQUE, name VARCHAR(100) NOT NULL, tag VARCHAR(64) NULL) ENGINE=InnoDB');
        } catch (ConnectionException $e) {
            Services::failUnreachable('MariaDB', $e->getMessage());
        }
        self::$server = new ProcessRunner(dirname(__DIR__, 2) . '/Fixtures/app_server.php', [], [
            'OMNITRIX_FIXTURE_PORT' => (string) self::PORT, 'OMNITRIX_FIXTURE_DB' => '1',
            'OMNITRIX_TEST_DB_HOST' => Services::dbHost(), 'OMNITRIX_TEST_DB_PORT' => (string) Services::dbPort(), 'OMNITRIX_TEST_DB_NAME' => Services::dbName(),
            'OMNITRIX_TEST_DB_USER' => Services::dbUser(), 'OMNITRIX_TEST_DB_PASS' => Services::dbPassword(),
        ]);
        self::$server->waitForPort(self::HOST, self::PORT, 15.0);
    }

    public static function tearDownAfterClass(): void
    {
        self::$server?->stop(10.0);
        self::$server = null;
        self::$admin?->execute('DROP TABLE IF EXISTS omni_http_users');
        self::$admin?->close(1.0);
        self::$admin = null;
    }

    /**
     * @param array<string, mixed>|null $body
     * @return array{status: int, json: array<string, mixed>}
     */
    private function request(string $method, string $path, ?array $body = null): array
    {
        $client = new HttpClient(self::HOST, self::PORT);
        $client->set(['timeout' => 10.0]);
        $client->setMethod($method);
        if ($body !== null) {
            $client->setHeaders(['Content-Type' => 'application/json']);
            $client->setData((string) json_encode($body));
        }
        $client->execute($path);
        $status = is_int($client->statusCode) ? $client->statusCode : -1;
        $decoded = json_decode(is_string($client->body) ? $client->body : '', true);
        $client->close();

        return ['status' => $status, 'json' => is_array($decoded) ? self::assoc($decoded) : []];
    }

    public function testFacadeWorksInsideWorkersAndPoolIsWarm(): void
    {
        $ping = $this->request('GET', '/db/ping');
        self::assertSame(200, $ping['status'], 'server stderr: ' . (string) self::$server?->stderr());
        self::assertSame(1, $ping['json']['ok']);
        $pool = self::assoc($ping['json']['pool']);
        self::assertGreaterThanOrEqual(2, $pool['size'], 'min connections were opened at worker start');
        self::assertSame(0, $pool['in_use']);
    }

    public function testCreateReadAndNotFoundOverHttp(): void
    {
        $created = $this->request('POST', '/db/users', ['email' => 'h1@x.io', 'name' => 'Hal']);
        self::assertSame(201, $created['status']);
        $id = self::int($created['json']['id']);

        $read = $this->request('GET', '/db/users/' . $id);
        self::assertSame(200, $read['status']);
        self::assertSame('Hal!', $read['json']['name'], 'both statements of the transaction were applied');
        self::assertSame(404, $this->request('GET', '/db/users/999999')['status']);
    }

    public function testConcurrentRequestsAreIsolatedAndPinTheirOwnConnection(): void
    {
        $tasks = [];
        for ($i = 0; $i < 24; $i++) {
            $tasks[$i] = fn(): array => $this->request('GET', '/db/isolation')['json'];
        }
        [$results, $elapsed] = self::runConcurrently($tasks);
        $requestIds = [];
        $cids = [];
        foreach ($results as $i => $json) {
            self::assertSame(1, $json['own_rows'], "request {$i} saw another request's uncommitted row");
            self::assertTrue($json['pinned'], "request {$i} switched connections inside its transaction");
            $requestIds[] = self::string($json['request_id']);
            $cids[] = self::int($json['cid']);
        }
        self::assertCount(24, array_unique($requestIds));
        self::assertLessThanOrEqual(16, count(array_unique($cids)), 'connections were reused across requests (2 workers × max 8)');
        self::assertLessThan(3.0, $elapsed);
        self::assertSame(0, self::assoc($this->request('GET', '/db/ping')['json']['pool'])['in_use'], 'no connection stays leased after requests end');
    }
}
