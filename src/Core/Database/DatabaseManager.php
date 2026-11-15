<?php

declare(strict_types=1);

namespace Omnitrix\Core\Database;

use Omnitrix\Core\Database\Config\DatabaseConfig;
use Omnitrix\Core\Database\Contracts\DriverInterface;
use Omnitrix\Core\Database\Driver\MySQL\MySqlDriver;
use Omnitrix\Core\Database\Exception\DatabaseException;
use Omnitrix\Core\Database\Query\Builder;
use Omnitrix\Core\Redis\RedisClient;
use Omnitrix\Core\Runtime\Config\InvalidConfigurationException;
use Psr\Log\LoggerInterface;

use function array_key_first;
use function array_keys;
use function is_array;
use function is_string;

/**
 * Registry of named databases for one worker. Built from the `database` config section, which
 * is either one connection (`['driver' => 'mysql', ...]`) or a map of named connections
 * (`['default' => [...], 'analytics' => [...]]`).
 */
final class DatabaseManager
{
    /** @var array<string, DatabaseConfig> */
    private array $configs = [];

    /** @var array<string, Database> */
    private array $databases = [];

    /** @var array<string, DriverInterface> */
    private array $drivers = [];

    private readonly string $defaultName;

    /**
     * @param array<string, mixed> $section
     * @param RedisClient|null     $redis   required when a connection enables the row cache (`cache` option)
     */
    public function __construct(array $section, private readonly ?LoggerInterface $logger = null, private readonly ?RedisClient $redis = null)
    {
        $this->configs = self::validate($section, $redis !== null);
        $this->defaultName = isset($this->configs['default']) ? 'default' : (string) array_key_first($this->configs);
        $this->drivers['mysql'] = new MySqlDriver();
        $this->drivers['mariadb'] = $this->drivers['mysql'];
    }

    /**
     * Validates the `database` section (one connection or named connections) without opening
     * anything, so configuration errors surface when the application is created.
     *
     * @param array<string, mixed> $section
     *
     * @return array<string, DatabaseConfig>
     *
     * @throws InvalidConfigurationException
     */
    public static function validate(array $section, bool $redisAvailable): array
    {
        $configs = [];
        $named = $section !== [] && is_array($section[array_key_first($section) ?? ''] ?? null) && !isset($section['driver']) && !isset($section['database']);
        if ($named) {
            foreach ($section as $name => $config) {
                if (!is_string($name) || !is_array($config)) {
                    throw InvalidConfigurationException::at('database', 'named connections must be string-keyed arrays');
                }
                /** @var array<string, mixed> $config */
                $configs[$name] = DatabaseConfig::fromArray($config, $name);
            }
        } else {
            $configs['default'] = DatabaseConfig::fromArray($section, 'default');
        }
        foreach ($configs as $name => $config) {
            if ($config->cache->enabled && !$redisAvailable) {
                throw InvalidConfigurationException::at("database.{$name}.cache", "the row cache needs the 'redis' configuration section");
            }
        }

        return $configs;
    }

    public function registerDriver(string $name, DriverInterface $driver): void
    {
        $this->drivers[$name] = $driver;
    }

    public function connection(?string $name = null): Database
    {
        $name ??= $this->defaultName;
        if (!isset($this->databases[$name])) {
            $config = $this->configs[$name] ?? throw new DatabaseException("Unknown database connection '{$name}'");
            $driver = $this->drivers[$config->driver] ?? throw new DatabaseException("No driver registered for '{$config->driver}'");
            $this->databases[$name] = new Database($config, $driver, $this->logger, $this->redis);
        }

        return $this->databases[$name];
    }

    public function table(string $table, ?string $connection = null): Builder
    {
        return $this->connection($connection)->table($table);
    }

    /** @return list<string> */
    public function names(): array
    {
        return array_keys($this->configs);
    }

    /** Opens the configured minimum connections of every database. */
    public function warmUp(): void
    {
        foreach ($this->names() as $name) {
            $this->connection($name)->warmUp();
        }
    }

    /** Drains and closes every pool. @return int leased connections that did not return in time */
    public function close(float $drainTimeout = 10.0): int
    {
        $leaked = 0;
        foreach ($this->databases as $database) {
            $leaked += $database->close($drainTimeout);
        }

        return $leaked;
    }
}
