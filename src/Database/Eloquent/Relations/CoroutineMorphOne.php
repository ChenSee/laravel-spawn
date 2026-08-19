<?php

namespace Spawn\Laravel\Database\Eloquent\Relations;

use Illuminate\Database\Eloquent\Relations\MorphOne;

/**
 * Laravel's MorphOne, deciding about its own constraints per coroutine.
 */
class CoroutineMorphOne extends MorphOne
{
    use ConstrainsPerCoroutine;
}
