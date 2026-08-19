<?php

namespace Spawn\Laravel\Database\Eloquent\Relations;

use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Laravel's HasMany, deciding about its own constraints per coroutine.
 */
class CoroutineHasMany extends HasMany
{
    use ConstrainsPerCoroutine;
}
