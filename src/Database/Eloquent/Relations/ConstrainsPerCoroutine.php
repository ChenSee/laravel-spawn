<?php

namespace Spawn\Laravel\Database\Eloquent\Relations;

use Spawn\Laravel\Database\Eloquent\RelationWindow;

/**
 * Makes one relation class decide about its own constraints from the coroutine that builds it.
 *
 * The window replaces `Relation::$constraints`, which the substituted `Relation` no longer
 * writes: it stays true, so the inherited `addConstraints()` bodies reached through
 * `parent::` behave as they do for a request that asked for nothing special.
 */
trait ConstrainsPerCoroutine
{
    /**
     * Shadows Relation::$constraints. The substituted Relation no longer writes that property,
     * but anything else that still does — a package, a test — would otherwise decide for these
     * classes; addConstraints() reads it through late static binding, so the inherited bodies
     * reached through parent:: consult this copy, which nothing writes at all.
     */
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
