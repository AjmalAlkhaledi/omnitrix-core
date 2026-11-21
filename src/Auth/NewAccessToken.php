<?php

declare(strict_types=1);

namespace Omnitrix\Auth;

/** A token just created: `plainTextToken` is shown to the client once (`{id}|{secret}`). */
final readonly class NewAccessToken
{
    public function __construct(public PersonalAccessToken $accessToken, public string $plainTextToken) {}
}
