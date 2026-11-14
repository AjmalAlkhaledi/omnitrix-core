<?php

declare(strict_types=1);

namespace Omnitrix\Core\Tests\Integration\Foundation;

use Omnitrix\Core\Tests\Support\Services;
use Omnitrix\Core\Tests\Support\TestCase;
use OpenSwoole\Coroutine\Client;
use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\CoversNothing;

use function sprintf;
use function str_starts_with;
use function var_export;

use const OPENSWOOLE_SOCK_TCP;

/**
 * Verifies the no-Docker integration backends started by `bin/services up` and that both
 * are reached through cooperative (hooked / coroutine) sockets.
 */
#[CoversNothing]
final class LocalServicesTest extends TestCase
{
    public function testMariaDbAnswersQueriesConcurrentlyOverHookedPdo(): void
    {
        try {
            $probe = new PDO(Services::dbDsn(), Services::dbUser(), Services::dbPassword(), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 2]);
        } catch (PDOException $e) {
            Services::failUnreachable('MariaDB', $e->getMessage());
        }

        $statement = $probe->query('SELECT VERSION()');
        self::assertNotFalse($statement);
        $version = $statement->fetchColumn();
        self::assertIsString($version);
        self::assertTrue(str_starts_with($version, '11.') || str_starts_with($version, '10.') || str_starts_with($version, '8.'), "unexpected server version {$version}");

        $tasks = [];
        for ($i = 0; $i < 4; $i++) {
            $tasks[] = static function (): int {
                $pdo = new PDO(Services::dbDsn(), Services::dbUser(), Services::dbPassword(), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
                $stmt = $pdo->prepare('SELECT SLEEP(?), CONNECTION_ID()');
                $stmt->execute([0.3]);
                /** @var array{0: int, 1: int} $row PHP >= 8.1 mysqlnd returns native integer types */
                $row = $stmt->fetch(PDO::FETCH_NUM);

                return $row[1];
            };
        }

        [$ids, $elapsed] = self::runConcurrently($tasks);
        self::assertCount(4, array_unique($ids), 'each coroutine must hold its own connection');
        self::assertLessThan(0.6, $elapsed, sprintf('four 300 ms queries took %.3fs; they must overlap', $elapsed));
    }

    public function testRedisAnswersPingOverCoroutineTcpClient(): void
    {
        $client = new Client(OPENSWOOLE_SOCK_TCP);
        if (!$client->connect(Services::redisHost(), Services::redisPort(), 2.0)) {
            Services::failUnreachable('Redis', 'connect errCode=' . var_export($client->errCode, true));
        }

        self::assertNotFalse($client->send("PING\r\n"));
        $reply = $client->recv(2.0);
        $client->close();

        self::assertSame("+PONG\r\n", $reply);
    }
}
