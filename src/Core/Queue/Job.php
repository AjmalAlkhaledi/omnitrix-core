<?php

declare(strict_types=1);

namespace Omnitrix\Core\Queue;

use InvalidArgumentException;
use JsonException;
use Omnitrix\Core\Support\Id;

use function is_array;
use function is_float;
use function is_int;
use function is_string;
use function json_decode;
use function json_encode;
use function microtime;

use const JSON_PRESERVE_ZERO_FRACTION;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

/** An immutable queued job. The JSON form is what lives in Redis. */
final readonly class Job
{
    /** @param array<string, mixed> $payload */
    public function __construct(
        public string $id,
        public string $name,
        public array $payload,
        public string $queue = 'default',
        public int $attempts = 0,
        public int $maxAttempts = 3,
        public float $createdAt = 0.0,
        public ?string $lastError = null,
        /** @var array<string, mixed> shared context of the dispatching request (see SharedContext) */
        public array $context = [],
    ) {
        if ($name === '') {
            throw new InvalidArgumentException('Job name must be non-empty');
        }
        if ($maxAttempts < 1) {
            throw new InvalidArgumentException('maxAttempts must be >= 1');
        }
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $context
     */
    public static function create(string $name, array $payload, string $queue = 'default', int $maxAttempts = 3, array $context = []): self
    {
        return new self(Id::generate(), $name, $payload, $queue, 0, $maxAttempts, microtime(true), null, $context);
    }

    public function withAttempt(?string $error): self
    {
        return new self($this->id, $this->name, $this->payload, $this->queue, $this->attempts + 1, $this->maxAttempts, $this->createdAt, $error, $this->context);
    }

    public function hasAttemptsLeft(): bool
    {
        return $this->attempts < $this->maxAttempts;
    }

    public function toJson(): string
    {
        try {
            return json_encode([
                'id' => $this->id, 'name' => $this->name, 'payload' => $this->payload, 'queue' => $this->queue,
                'attempts' => $this->attempts, 'max_attempts' => $this->maxAttempts, 'created_at' => $this->createdAt, 'last_error' => $this->lastError,
            ] + ($this->context === [] ? [] : ['context' => $this->context]), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
        } catch (JsonException $e) {
            throw new InvalidArgumentException('Job payload cannot be encoded as JSON: ' . $e->getMessage(), 0, $e);
        }
    }

    public static function fromJson(string $json): self
    {
        try {
            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new InvalidArgumentException('Malformed job: ' . $e->getMessage(), 0, $e);
        }
        if (!is_array($data) || !is_string($data['id'] ?? null) || !is_string($data['name'] ?? null) || !is_array($data['payload'] ?? null)) {
            throw new InvalidArgumentException('Malformed job document');
        }
        $payload = [];
        foreach ($data['payload'] as $k => $v) {
            $payload[(string) $k] = $v;
        }
        $created = $data['created_at'] ?? 0.0;
        $context = [];
        foreach (is_array($data['context'] ?? null) ? $data['context'] : [] as $k => $v) {
            $context[(string) $k] = $v;
        }

        return new self(
            $data['id'],
            $data['name'],
            $payload,
            is_string($data['queue'] ?? null) ? $data['queue'] : 'default',
            is_int($data['attempts'] ?? null) ? $data['attempts'] : 0,
            is_int($data['max_attempts'] ?? null) ? $data['max_attempts'] : 3,
            is_float($created) || is_int($created) ? (float) $created : 0.0,
            is_string($data['last_error'] ?? null) ? $data['last_error'] : null,
            $context,
        );
    }
}
