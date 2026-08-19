<?php

namespace Spawn\Laravel\Database\Eloquent\Relations;

use Spawn\Laravel\Database\Eloquent\RelationWindow;

/**
 * Makes one relation class read its own coroutine instead of the shared flag.
 *
 * Two things do that, and both are needed. The property shadows `Relation::$constraints`:
 * Eloquent's `addConstraints()` reads it through late static binding, so the inherited body
 * consults this copy instead of the shared one. The method then answers from the window of
 * the coroutine that is building the relation.
 *
 * Nothing ever writes this copy. Every `noConstraints()` call in the framework names
 * `Relation`, `HasOne`, `MorphOne` or `HasOneThrough`, and none of those is one of these
 * subclasses, so the property is a constant `true` that PHP will not let us declare as one:
 * static properties cannot be readonly, and the inherited body reads a property.
 */
trait ConstrainsPerCoroutine
{
    protected static $constraints = true;

    public function addConstraints()
    {
        if (RelationWindow::isOpen()) {
            $this->addConstraintsExemptParts();

            return;
        }

        parent::addConstraints();
    }

    /**
     * The work Eloquent's own addConstraints() does whether constraints are on or off.
     *
     * For most relations there is none: the whole body sits under the flag. The join of a
     * pivot or a through table does not, and skipping it leaves a query that names a table
     * it never joined, so those classes fill this in.
     */
    protected function addConstraintsExemptParts(): void
    {
    }
}
