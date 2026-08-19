<?php

namespace Spawn\Laravel\Database\Eloquent;

use Closure;

use function Async\coroutine_context;

/**
 * Eloquent's "build this relation without its own where clause" decision, held per coroutine.
 *
 * Upstream keeps it in `Relation::$constraints` and `Relation::$constraintsForNestedRelations`,
 * two static properties, which are one pair per worker thread and therefore shared by every
 * coroutine of that worker. Here the same decision is a stack in the coroutine's own context,
 * which nothing inherits and which dies with the coroutine that owns it.
 *
 * The copies of Eloquent under `overrides/` are the only callers: `withoutConstraints()` pushes
 * a frame that disables them, `withConstraints()` one that enables them again, and the relation
 * classes of this package read the top of the stack.
 */
final class RelationWindow
{
    private const KEY = 'spawn.relation.window';

    /**
     * Run the callback with the relation's own constraints switched off.
     *
     * @param  bool  $forNestedRelations  Whether a nested relation attribute resolved inside the
     *                                    callback should get its constraints back.
     */
    public static function open(Closure $callback, bool $forNestedRelations = false): mixed
    {
        return self::within($callback, [false, $forNestedRelations]);
    }

    /**
     * Run the callback with the relation's own constraints switched on.
     */
    public static function closed(Closure $callback): mixed
    {
        return self::within($callback, [true, self::forNestedRelations()]);
    }

    /**
     * Whether the current coroutine asked for relations without their own constraints.
     */
    public static function isOpen(): bool
    {
        $frames = self::frames();

        return $frames !== [] && end($frames)[0] === false;
    }

    /**
     * Whether a nested relation attribute resolved right now gets its constraints back.
     */
    public static function forNestedRelations(): bool
    {
        $frames = self::frames();

        return $frames !== [] && end($frames)[1] === true;
    }

    /**
     * Push a frame for the length of the callback. The stack is restored even when the callback
     * throws, and a coroutine killed inside one leaves nothing behind: the context goes with it.
     *
     * @param  array{0: bool, 1: bool}  $frame
     */
    private static function within(Closure $callback, array $frame): mixed
    {
        $context = coroutine_context();
        $frames = self::frames();

        $context->set(self::KEY, [...$frames, $frame], true);

        try {
            return $callback();
        } finally {
            $context->set(self::KEY, $frames, true);
        }
    }

    /**
     * @return list<array{0: bool, 1: bool}>
     */
    private static function frames(): array
    {
        return coroutine_context()->findLocal(self::KEY) ?? [];
    }
}
