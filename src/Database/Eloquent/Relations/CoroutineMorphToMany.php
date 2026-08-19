<?php

namespace Spawn\Laravel\Database\Eloquent\Relations;

use Illuminate\Database\Eloquent\Relations\MorphToMany;

/**
 * Laravel's MorphToMany, deciding about its own constraints per coroutine.
 */
class CoroutineMorphToMany extends MorphToMany
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
