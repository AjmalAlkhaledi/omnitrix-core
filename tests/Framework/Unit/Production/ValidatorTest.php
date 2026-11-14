<?php

declare(strict_types=1);

namespace Omnitrix\Tests\Unit\Production;

use InvalidArgumentException;
use Omnitrix\Tests\Support\TestCase;
use Omnitrix\Validation\ValidationException;
use Omnitrix\Validation\Validator;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(Validator::class)]
#[CoversClass(ValidationException::class)]
final class ValidatorTest extends TestCase
{
    public function testReturnsOnlyValidatedFieldsWithCasting(): void
    {
        $v = new Validator(['name' => 'required|string|min:2|max:50', 'email' => 'required|email', 'age' => 'int|between:18,130', 'admin' => 'bool', 'score' => 'numeric', 'role' => 'in:user,editor', 'note' => 'nullable|string', 'id' => 'uuid']);
        $data = $v->validate(['name' => 'Ann', 'email' => 'ann@example.com', 'age' => '42', 'admin' => 'true', 'score' => '1.5', 'role' => 'editor', 'note' => null, 'id' => '123e4567-e89b-12d3-a456-426614174000', 'is_superuser' => true]);
        self::assertSame(['name' => 'Ann', 'email' => 'ann@example.com', 'age' => 42, 'admin' => true, 'score' => 1.5, 'role' => 'editor', 'note' => null, 'id' => '123e4567-e89b-12d3-a456-426614174000'], $data);
        self::assertArrayNotHasKey('is_superuser', $data, 'unknown fields never pass through');
        self::assertSame(['name' => 'Bo', 'email' => 'b@x.io'], $v->validate(['name' => 'Bo', 'email' => 'b@x.io']), 'optional fields may be absent');
    }

    public function testCollectsAllErrorsPerField(): void
    {
        $v = new Validator(['name' => 'required|string', 'email' => 'required|email', 'age' => 'int|min:18', 'tags' => 'array|max:2', 'site' => 'url', 'code' => 'regex:/^[A-Z]{3}$/']);
        try {
            $v->validate(['email' => 'nope', 'age' => '12', 'tags' => [1, 2, 3], 'site' => 'ftp://x', 'code' => 'abc']);
            self::fail('expected validation failure');
        } catch (ValidationException $e) {
            self::assertSame(422, $e->status());
            self::assertSame(['name', 'email', 'age', 'tags', 'site', 'code'], array_keys($e->errors));
            self::assertSame(['The name field is required.'], $e->errors['name']);
            self::assertSame(['The age must be at least 18.'], $e->errors['age']);
            self::assertSame(['errors' => $e->errors], $e->details());
        }
    }

    public function testUnknownRulesAreProgrammingErrors(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Validator(['x' => 'required|sparkly']);
    }
}
