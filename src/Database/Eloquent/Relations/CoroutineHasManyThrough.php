<?php

namespace Spawn\Laravel\Database\Eloquent\Relations;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

/**
 * Laravel's HasManyThrough, deciding about its own constraints per coroutine.
 */
class CoroutineHasManyThrough extends HasManyThrough
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

    /**
     * See CoroutineHasMany::one(): upstream names HasOneThrough, which reads the shared flag.
     *
     * @return CoroutineHasOneThrough<*, *, *>
     */
    public function one()
    {
        return CoroutineHasOneThrough::noConstraints(fn () => new CoroutineHasOneThrough(
            tap($this->getQuery(), fn (Builder $query) => $query->getQuery()->joins = []),
            $this->farParent,
            $this->throughParent,
            $this->getFirstKeyName(),
            $this->getForeignKeyName(),
            $this->getLocalKeyName(),
            $this->getSecondLocalKeyName(),
        ));
    }
}
