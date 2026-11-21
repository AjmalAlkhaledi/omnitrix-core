<?php

declare(strict_types=1);

namespace Omnitrix\Auth\Access;

use Closure;
use InvalidArgumentException;
use Omnitrix\Contracts\Authenticatable;
use Omnitrix\Support\Str;
use ReflectionFunction;
use ReflectionNamedType;

use function array_slice;
use function array_values;
use function is_bool;
use function is_callable;
use function is_object;
use function is_string;
use function is_subclass_of;

/**
 * Abilities and model policies.
 *
 * ```php
 * $gate->define('reports.view', fn (User $user) => $user->is_admin);
 * $gate->policy(Post::class, PostPolicy::class);   // PostPolicy::update(User $user, Post $post): bool
 * $gate->before(fn (User $user) => $user->is_admin ? true : null);
 *
 * $gate->allows('update', $post);                   // with the authenticated user
 * $gate->forUser($other)->denies('reports.view');
 * $gate->authorize('delete', $post);                // AuthorizationException (403) when denied
 * ```
 *
 * Callbacks return a bool or an {@see AuthorizationResponse} (with a message). A guest is only
 * passed to callbacks whose user parameter is nullable; otherwise guests are denied. Policy
 * methods are named after the ability (`update`, `viewAny`, `force-delete` → `forceDelete`); a
 * policy's `before()` runs first. `before` hooks returning non-null decide the check.
 */
final class Gate implements \Omnitrix\Contracts\Gate
{
    /** @var array<string, Closure> */
    private array $abilities = [];

    /** @var array<class-string, class-string|object> */
    private array $policies = [];

    /** @var array<class-string, object> policy instances, created on first use */
    private array $policyInstances = [];

    /** @var list<Closure> */
    private array $before = [];

    /** @var list<Closure> */
    private array $after = [];

    /** @param (Closure(): ?Authenticatable)|null $user resolves the current user */
    public function __construct(private readonly ?Closure $user = null) {}

    /** @param callable $callback receives the user and the arguments; returns bool or AuthorizationResponse */
    public function define(string $ability, callable $callback): void
    {
        $this->abilities[$ability] = $callback(...);
    }

    /**
     * @param class-string        $model
     * @param class-string|object $policy
     */
    public function policy(string $model, string|object $policy): void
    {
        $this->policies[$model] = $policy;
    }

    /** @param callable $callback receives the user, ability and arguments; returns bool, AuthorizationResponse or null (no opinion) */
    public function before(callable $callback): void
    {
        $this->before[] = $callback(...);
    }

    /** @param callable $callback receives the user, ability, result and arguments; returns bool, AuthorizationResponse or null */
    public function after(callable $callback): void
    {
        $this->after[] = $callback(...);
    }

    public function has(string $ability): bool
    {
        return isset($this->abilities[$ability]);
    }

    /** A gate checking abilities for another user. */
    public function forUser(?Authenticatable $user): self
    {
        $gate = new self(static fn(): ?Authenticatable => $user);
        $gate->abilities = $this->abilities;
        $gate->policies = $this->policies;
        $gate->before = $this->before;
        $gate->after = $this->after;

        return $gate;
    }

    public function allows(string $ability, mixed ...$arguments): bool
    {
        return $this->inspect($ability, ...$arguments)->allowed;
    }

    public function denies(string $ability, mixed ...$arguments): bool
    {
        return !$this->allows($ability, ...$arguments);
    }

    /** @param list<string> $abilities all must be allowed */
    public function check(array $abilities, mixed ...$arguments): bool
    {
        foreach ($abilities as $ability) {
            if (!$this->allows($ability, ...$arguments)) {
                return false;
            }
        }

        return true;
    }

    /** @param list<string> $abilities at least one must be allowed */
    public function any(array $abilities, mixed ...$arguments): bool
    {
        foreach ($abilities as $ability) {
            if ($this->allows($ability, ...$arguments)) {
                return true;
            }
        }

        return false;
    }

    /** @throws AuthorizationException */
    public function authorize(string $ability, mixed ...$arguments): AuthorizationResponse
    {
        $response = $this->inspect($ability, ...$arguments);
        if ($response->denied()) {
            throw AuthorizationException::from($response);
        }

        return $response;
    }

    public function inspect(string $ability, mixed ...$arguments): AuthorizationResponse
    {
        $arguments = array_values($arguments);
        $user = $this->user !== null ? ($this->user)() : null;
        $result = null;
        foreach ($this->before as $hook) {
            $result = $this->call($hook, $user, [$ability, ...$arguments], guest: null);
            if ($result !== null) {
                break;
            }
        }
        $result ??= $this->evaluate($user, $ability, $arguments);
        foreach ($this->after as $hook) {
            $override = $this->call($hook, $user, [$ability, $result, ...$arguments], guest: null);
            if ($result === null && $override !== null) {
                $result = $override;
            }
        }

        return self::response($result);
    }

    /** @param list<mixed> $arguments */
    private function evaluate(?Authenticatable $user, string $ability, array $arguments): bool|AuthorizationResponse|null
    {
        $policy = $this->policyFor($arguments[0] ?? null);
        if ($policy !== null) {
            $before = [$policy, 'before'];
            if (is_callable($before)) {
                $result = $this->call($before(...), $user, [$ability, ...$arguments], guest: null);
                if ($result !== null) {
                    return self::normalise($result);
                }
            }
            $method = [$policy, Str::camel($ability)];
            if (is_callable($method)) {
                // A class name argument (create, viewAny) is not passed on.
                $rest = is_string($arguments[0] ?? null) ? array_slice($arguments, 1) : $arguments;

                return self::normalise($this->call($method(...), $user, $rest));
            }
        }
        $callback = $this->abilities[$ability] ?? null;

        return $callback === null ? false : self::normalise($this->call($callback, $user, $arguments));
    }

    private function policyFor(mixed $subject): ?object
    {
        $class = is_object($subject) ? $subject::class : (is_string($subject) ? $subject : null);
        if ($class === null) {
            return null;
        }
        foreach ($this->policies as $model => $policy) {
            if ($class === $model || is_subclass_of($class, $model)) {
                return $this->policyInstances[$model] ??= is_string($policy) ? new $policy() : $policy;
            }
        }

        return null;
    }

    /**
     * @param list<mixed> $arguments
     * @param false|null  $guest result when the callback does not accept guests: deny (abilities) or no opinion (hooks)
     */
    private function call(Closure $callback, ?Authenticatable $user, array $arguments, ?bool $guest = false): mixed
    {
        if ($user === null && !self::acceptsGuests($callback)) {
            return $guest;
        }

        return $callback($user, ...$arguments);
    }

    private static function acceptsGuests(Closure $callback): bool
    {
        $parameters = (new ReflectionFunction($callback))->getParameters();
        if ($parameters === []) {
            return true;
        }
        $type = $parameters[0]->getType();

        return $type === null || $type->allowsNull() || ($type instanceof ReflectionNamedType && $type->getName() === 'mixed');
    }

    private static function normalise(mixed $result): bool|AuthorizationResponse|null
    {
        return match (true) {
            $result === null, is_bool($result), $result instanceof AuthorizationResponse => $result,
            default => throw new InvalidArgumentException('Gate callbacks return a bool, null (before/after) or an AuthorizationResponse'),
        };
    }

    private static function response(mixed $result): AuthorizationResponse
    {
        $result = self::normalise($result);

        return $result instanceof AuthorizationResponse ? $result : ($result === true ? AuthorizationResponse::allow() : AuthorizationResponse::deny());
    }
}
