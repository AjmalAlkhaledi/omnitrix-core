<?php

declare(strict_types=1);

/* Creates and seeds bench_users (10 000 rows). Idempotent: re-seeds on every run. */

use Omnitrix\Core\Database\Config\DatabaseConfig;
use Omnitrix\Core\Database\Database;
use Omnitrix\Core\Database\Driver\MySQL\MySqlDriver;

require dirname(__DIR__) . '/vendor/autoload.php';

OpenSwoole\Coroutine::run(static function (): void {
    $env = static fn(string $k, string $d): string => is_string(getenv($k)) && getenv($k) !== '' ? (string) getenv($k) : $d;
    $db = new Database(DatabaseConfig::fromArray(['driver' => 'mariadb', 'host' => $env('OMNITRIX_TEST_DB_HOST', '127.0.0.1'), 'port' => (int) $env('OMNITRIX_TEST_DB_PORT', '33306'), 'database' => $env('OMNITRIX_TEST_DB_NAME', 'omnitrix_test'), 'username' => $env('OMNITRIX_TEST_DB_USER', 'root'), 'password' => $env('OMNITRIX_TEST_DB_PASS', ''), 'pool' => ['min' => 0, 'max' => 1]]), new MySqlDriver());
    $db->execute('DROP TABLE IF EXISTS bench_users');
    $db->execute('CREATE TABLE bench_users (id INT PRIMARY KEY, name VARCHAR(64) NOT NULL, balance BIGINT NOT NULL DEFAULT 0, visits BIGINT NOT NULL DEFAULT 0, score DOUBLE NOT NULL DEFAULT 0) ENGINE=InnoDB');
    for ($start = 1; $start <= 10000; $start += 1000) {
        $rows = [];
        for ($i = $start; $i < $start + 1000; $i++) {
            $rows[] = ['id' => $i, 'name' => 'user' . $i, 'balance' => $i % 1000, 'visits' => 0, 'score' => 0.0];
        }
        $db->table('bench_users')->insert($rows);
    }
    echo 'seeded ', $db->table('bench_users')->count(), " rows\n";
    $db->close(1.0);
});
