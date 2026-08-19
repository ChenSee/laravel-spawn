<?php

namespace Spawn\Laravel\Database\Eloquent;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use LogicException;
use Spawn\Laravel\Database\Eloquent\Relations\CoroutineBelongsTo;
use Spawn\Laravel\Database\Eloquent\Relations\CoroutineBelongsToMany;
use Spawn\Laravel\Database\Eloquent\Relations\CoroutineHasMany;
use Spawn\Laravel\Database\Eloquent\Relations\CoroutineHasManyThrough;
use Spawn\Laravel\Database\Eloquent\Relations\CoroutineHasOne;
use Spawn\Laravel\Database\Eloquent\Relations\CoroutineHasOneThrough;
use Spawn\Laravel\Database\Eloquent\Relations\CoroutineMorphMany;
use Spawn\Laravel\Database\Eloquent\Relations\CoroutineMorphOne;
use Spawn\Laravel\Database\Eloquent\Relations\CoroutineMorphTo;
use Spawn\Laravel\Database\Eloquent\Relations\CoroutineMorphToMany;

/**
 * Put this on a model — a base model of the application reaches all of them at once — and its
 * relations stop reading the flag every coroutine of the worker thread shares.
 *
 * What it is for. `Relation::$constraints` is a static property that eager loading switches
 * off while it builds a relation object and restores from a captured value. Under coroutine
 * concurrency the callback yields — a model booting against config or cache inside it — and
 * two things break: overlapping windows restore each other's captured value and leave the flag
 * off for the life of the worker, and while it is off every relation built by every sibling
 * coroutine comes out without its `where foreign_key = ?`, answering with the whole table and
 * saying nothing about it.
 *
 * How. The builder opens the window in the coroutine's own context, and the relation classes
 * read that instead of the property, through the two moves in ConstrainsPerCoroutine. Nothing
 * of Laravel's is patched, copied or reordered: these are the model's own factory methods,
 * which exist to be overridden.
 *
 * What it does not cover. `HasMany::one()`, `MorphMany::one()` and `HasManyThrough::one()`
 * build Laravel's own class rather than going through the model, so a relation converted that
 * way still reads the shared flag. The closure they wrap only constructs objects, so it is the
 * flag's value at that moment — not a window of their own — that can be wrong. And a relation
 * of a related model is built by that model's builder, so `with('items.tags')` needs the trait
 * on both.
 *
 * @phpstan-require-extends Model
 */
trait CoroutineRelations
{
    /**
     * @param  \Illuminate\Database\Query\Builder  $query
     * @return CoroutineBuilder<static>
     *
     * @throws LogicException When the model declares a builder that is not a CoroutineBuilder.
     */
    public function newEloquentBuilder($query)
    {
        // Laravel 13 lets a model name its builder with #[UseEloquentBuilder]. Overriding this
        // method would swallow that attribute, and the model would silently get a builder it
        // did not ask for — so the attribute wins, and a builder that cannot open the window
        // is refused rather than accepted into a worker where it reads another request's rows.
        $declared = method_exists($this, 'resolveCustomBuilderClass')
            ? $this->resolveCustomBuilderClass()
            : false;

        if ($declared === false) {
            return new CoroutineBuilder($query);
        }

        if (! is_a($declared, CoroutineBuilder::class, true)) {
            throw new LogicException(
                static::class.' uses CoroutineRelations and declares the builder '.$declared
                .', which does not extend '.CoroutineBuilder::class
                .'. Extend it, or drop the trait and accept Laravel\'s shared relation constraints.'
            );
        }

        return new $declared($query);
    }

    /** @param  string  $foreignKey */
    protected function newHasOne(Builder $query, Model $parent, $foreignKey, $localKey)
    {
        return new CoroutineHasOne($query, $parent, $foreignKey, $localKey);
    }

    /** @param  string  $foreignKey */
    protected function newHasMany(Builder $query, Model $parent, $foreignKey, $localKey)
    {
        return new CoroutineHasMany($query, $parent, $foreignKey, $localKey);
    }

    /** @param  string  $type */
    protected function newMorphOne(Builder $query, Model $parent, $type, $id, $localKey)
    {
        return new CoroutineMorphOne($query, $parent, $type, $id, $localKey);
    }

    /** @param  string  $type */
    protected function newMorphMany(Builder $query, Model $parent, $type, $id, $localKey)
    {
        return new CoroutineMorphMany($query, $parent, $type, $id, $localKey);
    }

    /** @param  string  $foreignKey */
    protected function newBelongsTo(Builder $query, Model $child, $foreignKey, $ownerKey, $relation)
    {
        return new CoroutineBelongsTo($query, $child, $foreignKey, $ownerKey, $relation);
    }

    /** @param  string  $foreignKey */
    protected function newMorphTo(Builder $query, Model $parent, $foreignKey, $ownerKey, $type, $relation): MorphTo
    {
        return new CoroutineMorphTo($query, $parent, $foreignKey, $ownerKey, $type, $relation);
    }

    /** @param  string  $firstKey */
    protected function newHasOneThrough(
        Builder $query,
        Model $farParent,
        Model $throughParent,
        $firstKey,
        $secondKey,
        $localKey,
        $secondLocalKey,
    ) {
        return new CoroutineHasOneThrough(
            $query, $farParent, $throughParent, $firstKey, $secondKey, $localKey, $secondLocalKey
        );
    }

    /** @param  string  $firstKey */
    protected function newHasManyThrough(
        Builder $query,
        Model $farParent,
        Model $throughParent,
        $firstKey,
        $secondKey,
        $localKey,
        $secondLocalKey,
    ) {
        return new CoroutineHasManyThrough(
            $query, $farParent, $throughParent, $firstKey, $secondKey, $localKey, $secondLocalKey
        );
    }

    /** @param  string  $table */
    protected function newBelongsToMany(
        Builder $query,
        Model $parent,
        $table,
        $foreignPivotKey,
        $relatedPivotKey,
        $parentKey,
        $relatedKey,
        $relationName = null,
    ) {
        return new CoroutineBelongsToMany(
            $query, $parent, $table, $foreignPivotKey, $relatedPivotKey, $parentKey, $relatedKey, $relationName
        );
    }

    /** @param  string  $name */
    protected function newMorphToMany(
        Builder $query,
        Model $parent,
        $name,
        $table,
        $foreignPivotKey,
        $relatedPivotKey,
        $parentKey,
        $relatedKey,
        $relationName = null,
        $inverse = false,
    ) {
        return new CoroutineMorphToMany(
            $query, $parent, $name, $table, $foreignPivotKey, $relatedPivotKey,
            $parentKey, $relatedKey, $relationName, $inverse
        );
    }
}
