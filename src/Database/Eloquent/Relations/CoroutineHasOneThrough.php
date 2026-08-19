<?php

namespace Spawn\Laravel\Database\Eloquent\Relations;

use Illuminate\Database\Eloquent\Relations\HasOneThrough;

/**
 * Laravel's HasOneThrough, deciding about its own constraints per coroutine.
 */
class CoroutineHasOneThrough extends HasOneThrough
{
    use ConstrainsPerCoroutine;

    /**
     * Eloquent joins the intermediate table with the constraints off as well, and a query
     * that skipped the join would name a table it never reached.
     */
    protected function addConstraintsExemptParts(): void
    {
        $this->performJoin();
    }
}
