<?php

namespace Spawn\Laravel\Database\Eloquent\Relations;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Laravel's BelongsTo, deciding about its own constraints per coroutine.
 */
class CoroutineBelongsTo extends BelongsTo
{
    use ConstrainsPerCoroutine;
}
