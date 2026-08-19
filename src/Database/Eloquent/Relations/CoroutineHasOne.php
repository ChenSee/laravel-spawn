<?php

namespace Spawn\Laravel\Database\Eloquent\Relations;

use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Laravel's HasOne, deciding about its own constraints per coroutine.
 */
class CoroutineHasOne extends HasOne
{
    use ConstrainsPerCoroutine;
}
