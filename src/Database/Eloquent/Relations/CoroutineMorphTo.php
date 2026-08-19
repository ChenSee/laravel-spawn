<?php

namespace Spawn\Laravel\Database\Eloquent\Relations;

use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Laravel's MorphTo, deciding about its own constraints per coroutine.
 */
class CoroutineMorphTo extends MorphTo
{
    use ConstrainsPerCoroutine;
}
