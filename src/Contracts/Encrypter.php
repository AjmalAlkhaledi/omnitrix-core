<?php

declare(strict_types=1);

namespace Omnitrix\Contracts;

/** Authenticated symmetric encryption with the application key. */
interface Encrypter
{
    /** Encrypts any serialisable value (JSON-encoded). */
    public function encrypt(mixed $value): string;

    /** @throws \Omnitrix\Encryption\DecryptException */
    public function decrypt(string $payload): mixed;

    public function encryptString(string $value): string;

    /** @throws \Omnitrix\Encryption\DecryptException */
    public function decryptString(string $payload): string;
}
