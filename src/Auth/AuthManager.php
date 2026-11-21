<?php

declare(strict_types=1);

namespace Omnitrix\Auth;

use InvalidArgumentException;
use Omnitrix\Contracts\Guard;
use Omnitrix\Contracts\Hasher;
use Omnitrix\Core\Runtime\Config\InvalidConfigurationException;
use Omnitrix\Database\Model;

use function array_key_first;
use function array_keys;
use function class_exists;
use function in_array;
use function is_array;
use function is_int;
use function is_string;
use function is_subclass_of;

/**
 * Guards from the `auth` section (one instance per guard and worker; their state is per request):
 *
 * ```php
 * 'auth' => [
 *     'default' => 'api',
 *     'guards' => ['api' => ['driver' => 'token', 'provider' => 'users'], 'web' => ['driver' => 'session', 'provider' => 'users']],
 *     'providers' => ['users' => ['model' => App\Models\User::class]],
 * ],
 * ```
 */
final class AuthManager
{
    /** @var array<string, Guard> */
    private array $guards = [];

    /** @var array<string, array{driver: string, provider: string, remember_days: int}> */
    private readonly array $guardConfig;

    /** @var array<string, class-string<Model>> */
    private readonly array $providers;

    private readonly string $default;

    /** @param array<string, mixed> $config */
    public function __construct(array $config, private readonly Hasher $hasher)
    {
        $guards = [];
        foreach (is_array($config['guards'] ?? null) ? $config['guards'] : [] as $name => $guard) {
            if (!is_string($name) || !is_array($guard) || !in_array($guard['driver'] ?? null, ['session', 'token'], true) || !is_string($guard['provider'] ?? null)) {
                throw InvalidConfigurationException::at('auth.guards.' . $name, "expected ['driver' => 'session'|'token', 'provider' => name]");
            }
            $days = $guard['remember_days'] ?? 30;
            $guards[$name] = ['driver' => $guard['driver'], 'provider' => $guard['provider'], 'remember_days' => is_int($days) ? $days : 30];
        }
        $providers = [];
        foreach (is_array($config['providers'] ?? null) ? $config['providers'] : [] as $name => $provider) {
            $model = is_array($provider) ? ($provider['model'] ?? null) : null;
            if (!is_string($name) || !is_string($model) || !class_exists($model) || !is_subclass_of($model, Model::class)) {
                throw InvalidConfigurationException::at('auth.providers.' . $name, "expected ['model' => a Model class]");
            }
            $providers[$name] = $model;
        }
        foreach ($guards as $name => $guard) {
            if (!isset($providers[$guard['provider']])) {
                throw InvalidConfigurationException::at("auth.guards.{$name}.provider", "provider '{$guard['provider']}' is not configured");
            }
        }
        $default = $config['default'] ?? array_key_first($guards);
        if (!is_string($default) || !isset($guards[$default])) {
            throw InvalidConfigurationException::at('auth.default', 'expected the name of a configured guard');
        }
        $this->guardConfig = $guards;
        $this->providers = $providers;
        $this->default = $default;
    }

    public function guard(?string $name = null): Guard
    {
        $name ??= $this->default;
        if (isset($this->guards[$name])) {
            return $this->guards[$name];
        }
        $config = $this->guardConfig[$name] ?? throw new InvalidArgumentException("Auth guard '{$name}' is not configured");
        $provider = new ModelUserProvider($this->providers[$config['provider']], $this->hasher);

        return $this->guards[$name] = $config['driver'] === 'session'
            ? new SessionGuard($name, $provider, $config['remember_days'])
            : new TokenGuard($name, $provider);
    }

    public function defaultGuard(): string
    {
        return $this->default;
    }

    /** @return list<string> */
    public function guardNames(): array
    {
        return array_keys($this->guardConfig);
    }
}
