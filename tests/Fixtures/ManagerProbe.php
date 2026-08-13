<?php

namespace Spawn\Laravel\Tests\Fixtures;

/**
 * Stands in for a guard, and reports which auth manager the creator that built it
 * resolved against.
 */
class ManagerProbe
{
    public function __construct(public int $managerId)
    {
    }
}
