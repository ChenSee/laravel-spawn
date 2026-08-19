<?php

namespace Spawn\Laravel\Database\Eloquent\Relations;

use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Laravel's MorphMany, deciding about its own constraints per coroutine.
 */
class CoroutineMorphMany extends MorphMany
{
    use ConstrainsPerCoroutine;
}
