<?php

namespace Spawn\Laravel\Database\Eloquent;

use Closure;

use function Async\coroutine_context;

/**
 * The "build this relation without its own where clause" window, held per coroutine.
 *
 * Eloquent holds that decision in `Relation::$constraints`, a static property, which is one
 * flag per worker thread and therefore shared by every coroutine of that worker. This class
 * is the same decision kept where it belongs: the coroutine's own context, which nothing
 * inherits and which dies with the coroutine that owns it.
 *
 * Only Eloquent's eager loading opens a window, and only around building the relation object;
 * see CoroutineBuilder, which is the one caller.
 */
final class RelationWindow
{
    private const KEY = 'spawn.relation.window';

    /**
     * Run the callback with the window open, and return whatever it returns.
     *
     * Windows nest: an inner one closes without closing the outer. The count is restored even
     * when the callback throws, and a coroutine cancelled inside the window leaves nothing
     * behind, because the count lives in the context that goes away with it.
     */
    public static function open(Closure $callback): mixed
    {
        $context = coroutine_context();
        $depth = self::depth();

        $context->set(self::KEY, $depth + 1, true);

        try {
            return $callback();
        } finally {
            $context->set(self::KEY, $depth, true);
        }
    }

    /**
     * Whether the current coroutine asked for relations without their own constraints.
     */
    public static function isOpen(): bool
    {
        return self::depth() > 0;
    }

    private static function depth(): int
    {
        return (int) (coroutine_context()->findLocal(self::KEY) ?? 0);
    }
}
