<?php

namespace Spawn\Laravel\Database\Eloquent;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * The Eloquent builder with the three "build me a bare relation" places made per-coroutine.
 *
 * Eloquent asks for a relation without its own where clause in exactly three methods, all of
 * them here, and each says so by calling `Relation::noConstraints()` — which writes a static
 * property every coroutine of the worker thread shares. Each is wrapped in a window of the
 * current coroutine instead; the inherited call still writes the shared property, and the
 * relation classes of CoroutineRelations no longer read it.
 *
 * @template TModel of \Illuminate\Database\Eloquent\Model
 *
 * @extends Builder<TModel>
 */
class CoroutineBuilder extends Builder
{
    /** @param  string  $name */
    public function getRelation($name)
    {
        return RelationWindow::open(fn () => parent::getRelation($name));
    }

    /** @param  string  $type */
    protected function getBelongsToRelation(MorphTo $relation, $type)
    {
        return RelationWindow::open(fn () => parent::getBelongsToRelation($relation, $type));
    }

    /** @param  string  $relation */
    protected function getRelationWithoutConstraints($relation)
    {
        return RelationWindow::open(fn () => parent::getRelationWithoutConstraints($relation));
    }
}
