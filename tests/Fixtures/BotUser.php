<?php

namespace Spawn\Laravel\Tests\Fixtures;

/**
 * The user a token resolves to. Identity is the token itself, so a test can tell
 * whose request a guard actually answered for.
 */
class BotUser
{
    public function __construct(public readonly string $token)
    {
    }
}
