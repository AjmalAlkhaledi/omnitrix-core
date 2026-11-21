<?php

declare(strict_types=1);

namespace Omnitrix\Tests\Integration\Broadcasting;

use Omnitrix\Broadcasting\BroadcastManager;
use Omnitrix\Broadcasting\BroadcastRoutes;
use Omnitrix\Tests\Integration\Database\ModelsAndMigrationsTest;
use Omnitrix\Tests\Support\ProcessRunner;
use Omnitrix\Tests\Support\Services;
use Omnitrix\Tests\Support\TestCase;
use OpenSwoole\Coroutine;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;

use function dirname;
use function fclose;
use function feof;
use function fread;
use function fwrite;
use function hash;
use function is_resource;
use function microtime;
use function str_contains;
use function stream_set_timeout;
use function stream_socket_client;
use function strpos;
use function substr;
use function uniqid;

/** Server-Sent Events end to end: two workers, Redis pub/sub, private and presence channels. */
#[CoversClass(BroadcastRoutes::class)]
#[CoversClass(BroadcastManager::class)]
final class BroadcastingTest extends TestCase
{
    private const int PORT = 18705;

    private static ?ProcessRunner $server = null;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        ModelsAndMigrationsTest::createDatabase();
        $pdo = new PDO('mysql:host=' . Services::dbHost() . ';port=' . Services::dbPort() . ';dbname=' . ModelsAndMigrationsTest::database(), Services::dbUser(), Services::dbPassword(), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $pdo->exec('DROP TABLE IF EXISTS personal_access_tokens, comments, post_tag, tags, posts, profiles, users, migrations');
        $pdo->exec('CREATE TABLE users (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(50) NOT NULL, email VARCHAR(100) NOT NULL, password VARCHAR(255) NOT NULL, remember_token VARCHAR(100) NULL, created_at TIMESTAMP NULL, updated_at TIMESTAMP NULL)');
        $pdo->exec("INSERT INTO users (id, name, email, password) VALUES (1, 'Ann', 'ann@x', 'x'), (2, 'Bob', 'bob@x', 'x')");
        $pdo->exec('CREATE TABLE personal_access_tokens (id INT AUTO_INCREMENT PRIMARY KEY, tokenable_type VARCHAR(255) NOT NULL, tokenable_id INT NOT NULL, name VARCHAR(50) NOT NULL, token VARCHAR(64) NOT NULL, abilities JSON NULL, last_used_at TIMESTAMP NULL, expires_at TIMESTAMP NULL, created_at TIMESTAMP NULL, updated_at TIMESTAMP NULL)');
        $insert = $pdo->prepare("INSERT INTO personal_access_tokens (id, tokenable_type, tokenable_id, name, token, abilities) VALUES (?, 'StreamUser', ?, 't', ?, '[\"*\"]')");
        $insert->execute([1, 1, hash('sha256', 'ann-secret')]);
        $insert->execute([2, 2, hash('sha256', 'bob-secret')]);
        self::$server = new ProcessRunner(dirname(__DIR__, 2) . '/Fixtures/broadcast_server.php', [], [
            'OMNITRIX_FIXTURE_PORT' => (string) self::PORT, 'FIXTURE_DB' => ModelsAndMigrationsTest::database(), 'FIXTURE_PREFIX' => 'broadcast-' . uniqid() . ':',
        ]);
        self::$server->waitForPort('127.0.0.1', self::PORT, 15.0);
    }

    public static function tearDownAfterClass(): void
    {
        self::$server?->stop(15.0);
        self::$server = null;
        parent::tearDownAfterClass();
    }

    public function testEventsReachListenersOnEveryWorkerAndPrivateChannelsAreAuthorized(): void
    {
        self::assertStringContainsString('HTTP/1.1 401', $this->request('GET', '/broadcasting/stream?channels=orders'), 'the authenticated endpoint needs a token');
        self::assertStringContainsString('HTTP/1.1 403', $this->request('GET', '/broadcasting/stream?channels=private-user.1', '2|bob-secret'), "Bob cannot listen to Ann's channel");
        self::assertStringContainsString('HTTP/1.1 403', $this->request('GET', '/public/stream?channels=private-user.1'), 'guests cannot listen to private channels');

        $ann = $this->open('/broadcasting/stream?channels=orders,private-user.1', '1|ann-secret');
        $guest = $this->open('/public/stream?channels=orders');
        try {
            Coroutine::usleep(300_000); // subscriptions are in place
            self::assertStringContainsString('"shipped":true', $this->request('POST', '/ship/42/1'));
            $annSeen = $this->readUntil($ann, '"channel":"private-user.1"');
            self::assertStringContainsString("event: OrderShipped\ndata: {\"channel\":\"orders\",\"data\":{\"order\":42,\"userId\":1}}", $annSeen);
            self::assertStringContainsString('"channel":"private-user.1"', $annSeen);
            $guestSeen = $this->readUntil($guest, 'event: OrderShipped');
            self::assertStringContainsString('"channel":"orders"', $guestSeen, 'public channels reach guests');
            self::assertStringNotContainsString('private-user', $guestSeen);
        } finally {
            fclose($ann);
            fclose($guest);
        }
    }

    public function testPresenceChannelsShareTheirMembers(): void
    {
        $ann = $this->open('/broadcasting/stream?channels=presence-room.lobby', '1|ann-secret');
        try {
            self::assertStringContainsString('"data":[{"id":1,"name":"Ann"}]', $this->readUntil($ann, 'presence.members'));
            $bob = $this->open('/broadcasting/stream?channels=presence-room.lobby', '2|bob-secret');
            $members = $this->readUntil($bob, 'presence.members');
            self::assertStringContainsString('{"id":1,"name":"Ann"}', $members);
            self::assertStringContainsString('{"id":2,"name":"Bob"}', $members);
            $joined = $this->readUntil($ann, 'presence.joined');
            self::assertStringContainsString("event: presence.joined\ndata: {\"channel\":\"presence-room.lobby\",\"data\":{\"id\":2,\"name\":\"Bob\"}}", $joined);
            self::assertStringNotContainsString('"data":{"id":1,"name":"Ann"}}', $joined, 'a member never receives their own join');
            fclose($bob);
            self::assertStringContainsString('"data":{"id":2,"name":"Bob"}', $this->readUntil($ann, 'presence.left'), 'leaving is announced');
        } finally {
            fclose($ann);
        }
    }

    /** @return resource */
    private function open(string $target, ?string $token = null)
    {
        $socket = stream_socket_client('tcp://127.0.0.1:' . self::PORT, $errno, $error, 5.0);
        self::assertTrue(is_resource($socket), (string) $error);
        stream_set_timeout($socket, 10);
        fwrite($socket, "GET {$target} HTTP/1.1\r\nHost: localhost\r\nAccept: text/event-stream\r\n" . ($token !== null ? "Authorization: Bearer {$token}\r\n" : '') . "\r\n");

        return $socket;
    }

    /** @param resource $socket */
    private function readUntil($socket, string $needle, float $timeout = 8.0): string
    {
        $buffer = '';
        $deadline = microtime(true) + $timeout;
        while (microtime(true) < $deadline) {
            $chunk = fread($socket, 8192);
            if ($chunk === false) {
                break;
            }
            $buffer .= $chunk;
            if (str_contains($buffer, $needle)) {
                // read the rest of this event
                if (str_contains(substr($buffer, (int) strpos($buffer, $needle)), "\n\n")) {
                    return $buffer;
                }
            }
            if ($chunk === '') {
                Coroutine::usleep(20_000);
            }
        }
        self::fail("did not receive {$needle}; got: {$buffer}");
    }

    private function request(string $method, string $target, ?string $token = null): string
    {
        $socket = stream_socket_client('tcp://127.0.0.1:' . self::PORT, $errno, $error, 5.0);
        self::assertTrue(is_resource($socket), (string) $error);
        fwrite($socket, "{$method} {$target} HTTP/1.1\r\nHost: localhost\r\nConnection: close\r\nContent-Length: 0\r\n" . ($token !== null ? "Authorization: Bearer {$token}\r\n" : '') . "\r\n");
        $response = '';
        while (!feof($socket)) {
            $chunk = fread($socket, 8192);
            if ($chunk === false) {
                break;
            }
            $response .= $chunk;
        }
        fclose($socket);

        return $response;
    }
}
