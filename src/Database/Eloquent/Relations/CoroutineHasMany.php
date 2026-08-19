<?php

namespace Spawn\Laravel\Database\Eloquent\Relations;

use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Laravel's HasMany, deciding about its own constraints per coroutine.
 */
class CoroutineHasMany extends HasMany
{
    use ConstrainsPerCoroutine;

    /**
     * Eloquent's own one() names HasOne directly, and that class reads the shared flag, which
     * is now permanently true — the relation it built would carry a where clause the caller
     * asked it not to have. Same shape as upstream, over the class of this package.
     *
     * @return CoroutineHasOne<*, *>
     */
    public function one()
    {
        return CoroutineHasOne::noConstraints(fn () => tap(
            new CoroutineHasOne(
                $this->getQuery(),
                $this->parent,
                $this->foreignKey,
                $this->localKey
            ),
            function ($hasOne) {
                if ($inverse = $this->getInverseRelationship()) {
                    $hasOne->inverse($inverse);
                }
            }
        ));
    }
}
