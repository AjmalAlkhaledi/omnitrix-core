<?php

declare(strict_types=1);

namespace Omnitrix\Auth;

use InvalidArgumentException;
use Omnitrix\Contracts\Authenticatable;
use Omnitrix\Contracts\Hasher;
use Omnitrix\Database\Model;

use function is_string;
use function is_subclass_of;

/** Finds users through a model class and checks their passwords. */
final class ModelUserProvider
{
    /** @param class-string<Model> $model */
    public function __construct(private readonly string $model, private readonly Hasher $hasher)
    {
        if (!is_subclass_of($model, Model::class) || !is_subclass_of($model, Authenticatable::class)) {
            throw new InvalidArgumentException("{$model} must be a Model implementing " . Authenticatable::class);
        }
    }

    /** @return class-string<Model> */
    public function model(): string
    {
        return $this->model;
    }

    public function retrieveById(int|string $id): ?Authenticatable
    {
        $model = $this->model;
        $user = $model::find($id);

        return $user instanceof Authenticatable ? $user : null;
    }

    /**
     * The user matching every credential except `password` (`['email' => …]`).
     *
     * @param array<string, mixed> $credentials
     */
    public function retrieveByCredentials(array $credentials): ?Authenticatable
    {
        unset($credentials['password']);
        if ($credentials === []) {
            return null;
        }
        $query = ($this->model)::query();
        foreach ($credentials as $column => $value) {
            $query->where($column, $value);
        }
        $user = $query->first();

        return $user instanceof Authenticatable ? $user : null;
    }

    /**
     * Checks the password; a correct password stored with outdated settings is re-hashed.
     *
     * @param array<string, mixed> $credentials
     */
    public function validateCredentials(Authenticatable $user, array $credentials): bool
    {
        $password = $credentials['password'] ?? null;
        if (!is_string($password) || !$this->hasher->check($password, $user->getAuthPassword())) {
            return false;
        }
        if ($this->hasher->needsRehash($user->getAuthPassword()) && $user instanceof Model) {
            $user->setAttribute('password', $this->hasher->make($password));
            $user->save();
        }

        return true;
    }

    public function hasher(): Hasher
    {
        return $this->hasher;
    }
}
