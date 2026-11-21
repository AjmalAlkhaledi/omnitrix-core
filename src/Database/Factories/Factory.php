<?php

declare(strict_types=1);

namespace Omnitrix\Database\Factories;

use Closure;
use InvalidArgumentException;
use Omnitrix\Database\Collection;
use Omnitrix\Database\Model;

use function array_merge;
use function array_values;
use function count;
use function is_array;
use function is_callable;
use function is_string;

/**
 * Builds models with fake data for tests and seeders.
 *
 * ```php
 * final class UserFactory extends Factory
 * {
 *     protected string $model = User::class;
 *
 *     public function definition(): array
 *     {
 *         return ['name' => $this->faker->name(), 'email' => $this->faker->unique()->email(), 'password' => 'password'];
 *     }
 *
 *     public function admin(): static
 *     {
 *         return $this->state(['is_admin' => true]);
 *     }
 * }
 *
 * $user = UserFactory::new()->create();
 * $admins = UserFactory::new()->count(3)->admin()->create();
 * $users = User::factory()->count(2)->sequence(['team' => 'red'], ['team' => 'blue'])->make();
 * UserFactory::new()->has(PostFactory::new()->count(3), 'user_id')->create();
 * ```
 */
abstract class Factory
{
    /** @var class-string<Model> */
    protected string $model;

    protected Faker $faker;

    private ?int $count = null;

    /** @var list<array<string, mixed>|Closure> */
    private array $states = [];

    /** @var list<array<string, mixed>> */
    private array $sequence = [];

    /** @var list<Closure(Model): void> */
    private array $afterMaking = [];

    /** @var list<Closure(Model): void> */
    private array $afterCreating = [];

    /** @var list<array{Factory, string}> children created for each model */
    private array $has = [];

    private static ?Faker $sharedFaker = null;

    final public function __construct()
    {
        $this->faker = self::$sharedFaker ??= new Faker();
    }

    /** @return static */
    public static function new(): static
    {
        return new static();
    }

    /** Seeds the generator used by every factory (repeatable data). */
    public static function seed(int $seed): void
    {
        self::$sharedFaker = new Faker($seed);
    }

    /** @return array<string, mixed> default attributes of one model */
    abstract public function definition(): array;

    /** @return static */
    public function count(int $count): static
    {
        if ($count < 0) {
            throw new InvalidArgumentException('count() needs a non-negative number');
        }
        $clone = clone $this;
        $clone->count = $count;

        return $clone;
    }

    /**
     * Attributes (or a callback from the current attributes to changes) applied after the definition.
     *
     * @param array<string, mixed>|callable(array<string, mixed>): array<string, mixed> $state
     *
     * @return static
     */
    public function state(array|callable $state): static
    {
        $clone = clone $this;
        if (is_callable($state)) {
            $clone->states[] = $state(...);
        } else {
            $typed = [];
            foreach ($state as $key => $value) {
                $typed[(string) $key] = $value;
            }
            $clone->states[] = $typed;
        }

        return $clone;
    }

    /**
     * Cycles through these attribute sets, one per model.
     *
     * @param array<string, mixed> ...$states
     *
     * @return static
     */
    public function sequence(array ...$states): static
    {
        $clone = clone $this;
        $clone->sequence = array_values($states);

        return $clone;
    }

    /**
     * Also creates the child factory's models for each created model, setting `$foreignKey` to
     * the parent's key.
     *
     * @param Factory $factory
     *
     * @return static
     */
    public function has(self $factory, string $foreignKey): static
    {
        $clone = clone $this;
        $clone->has[] = [$factory, $foreignKey];

        return $clone;
    }

    /**
     * Sets a foreign key to the given parent model (`PostFactory::new()->for($user, 'user_id')`).
     *
     * @return static
     */
    public function for(Model $parent, string $foreignKey): static
    {
        return $this->state([$foreignKey => $parent->getKey()]);
    }

    /**
     * @param callable(Model): void $callback
     *
     * @return static
     */
    public function afterMaking(callable $callback): static
    {
        $clone = clone $this;
        $clone->afterMaking[] = $callback(...);

        return $clone;
    }

    /**
     * @param callable(Model): void $callback
     *
     * @return static
     */
    public function afterCreating(callable $callback): static
    {
        $clone = clone $this;
        $clone->afterCreating[] = $callback(...);

        return $clone;
    }

    /**
     * Attributes only, without a model (`count()` gives a list).
     *
     * @param array<string, mixed> $attributes
     *
     * @return array<string, mixed>|list<array<string, mixed>>
     */
    public function raw(array $attributes = []): array
    {
        if ($this->count === null) {
            return $this->attributes(0, $attributes);
        }
        $rows = [];
        for ($i = 0; $i < $this->count; $i++) {
            $rows[] = $this->attributes($i, $attributes);
        }

        return $rows;
    }

    /**
     * Models that are not saved.
     *
     * @param array<string, mixed> $attributes
     *
     * @return Model|Collection<Model>
     */
    public function make(array $attributes = []): Model|Collection
    {
        if ($this->count === null) {
            return $this->makeOne(0, $attributes);
        }
        $models = [];
        for ($i = 0; $i < $this->count; $i++) {
            $models[] = $this->makeOne($i, $attributes);
        }

        return new Collection($models);
    }

    /**
     * Saved models (and their children from has()).
     *
     * @param array<string, mixed> $attributes
     *
     * @return Model|Collection<Model>
     */
    public function create(array $attributes = []): Model|Collection
    {
        $models = [];
        for ($i = 0; $i < ($this->count ?? 1); $i++) {
            $model = $this->makeOne($i, $attributes);
            $model->save();
            foreach ($this->has as [$factory, $foreignKey]) {
                $factory->state([$foreignKey => $model->getKey()])->create();
            }
            foreach ($this->afterCreating as $callback) {
                $callback($model);
            }
            $models[] = $model;
        }

        return $this->count === null ? $models[0] : new Collection($models);
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return Model
     */
    private function makeOne(int $index, array $overrides): Model
    {
        $class = $this->model;
        $model = new $class();
        $model->forceFill($this->attributes($index, $overrides));
        foreach ($this->afterMaking as $callback) {
            $callback($model);
        }

        return $model;
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function attributes(int $index, array $overrides): array
    {
        $attributes = $this->definition();
        foreach ($this->states as $state) {
            $changes = $state instanceof Closure ? $state($attributes) : $state;
            if (!is_array($changes)) {
                throw new InvalidArgumentException('A state callback must return an array of attributes');
            }
            foreach ($changes as $key => $value) {
                $attributes[(string) $key] = $value;
            }
        }
        if ($this->sequence !== []) {
            $attributes = array_merge($attributes, $this->sequence[$index % count($this->sequence)]);
        }
        $attributes = array_merge($attributes, $overrides);
        foreach ($attributes as $key => $value) {
            if ($value instanceof self) {
                $parent = $value->create(); // a parent built on demand
                $attributes[$key] = $parent instanceof Model ? $parent->getKey() : null;
            } elseif ($value instanceof Closure || (is_callable($value) && !is_string($value) && !is_array($value))) {
                $attributes[$key] = $value($attributes);
            }
        }

        return $attributes;
    }
}
