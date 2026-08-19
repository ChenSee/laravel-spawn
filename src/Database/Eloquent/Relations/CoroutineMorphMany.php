<?php

namespace Spawn\Laravel\Database\Eloquent\Relations;

use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Laravel's MorphMany, deciding about its own constraints per coroutine.
 */
class CoroutineMorphMany extends MorphMany
{
    use ConstrainsPerCoroutine;

    /**
     * See CoroutineHasMany::one(): upstream names MorphOne, which reads the shared flag.
     *
     * @return CoroutineMorphOne<*, *>
     */
    public function one()
    {
        return CoroutineMorphOne::noConstraints(fn () => tap(
            new CoroutineMorphOne(
                $this->getQuery(),
                $this->getParent(),
                $this->morphType,
                $this->foreignKey,
                $this->localKey
            ),
            function ($morphOne) {
                if ($inverse = $this->getInverseRelationship()) {
                    $morphOne->inverse($inverse);
                }
            }
        ));
    }
}
