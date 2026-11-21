<?php

declare(strict_types=1);

namespace Omnitrix\Session;

use JsonException;
use Omnitrix\Contracts\SessionHandler;
use Omnitrix\Core\Redis\RedisClient;

use function is_array;
use function json_decode;
use function json_encode;

use const JSON_THROW_ON_ERROR;

/** Sessions in Redis (`session:{id}`, expiring after the lifetime); the cookie holds only the id. */
final class RedisSessionHandler implements SessionHandler
{
    public function __construct(private readonly RedisClient $redis, private readonly string $prefix = 'session:') {}

    public function read(string $id): ?array
    {
        $raw = $this->redis->get($this->prefix . $id);
        if ($raw === null) {
            return null;
        }
        try {
            $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }
        if (!is_array($data)) {
            return null;
        }
        $typed = [];
        foreach ($data as $key => $value) {
            $typed[(string) $key] = $value;
        }

        return $typed;
    }

    public function write(string $id, array $data, int $lifetimeSeconds): void
    {
        $this->redis->set($this->prefix . $id, json_encode($data, JSON_THROW_ON_ERROR), $lifetimeSeconds);
    }

    public function destroy(string $id): void
    {
        $this->redis->delete($this->prefix . $id);
    }
}
