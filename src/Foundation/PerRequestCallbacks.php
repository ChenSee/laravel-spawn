<?php

namespace Spawn\Laravel\Foundation;

/**
 * The terminating callbacks registered by one request.
 *
 * A mutable object rather than a plain array in the context: a coroutine spawned inside
 * the request finds the same object its parent will drain, so a callback registered from
 * a nested coroutine still runs — an array would be copied into the child's context and
 * lost with it.
 */
final class PerRequestCallbacks
{
    /**
     * @var list<callable|string>
     */
    private array $callbacks = [];

    public function add(callable|string $callback): void
    {
        $this->callbacks[] = $callback;
    }

    /**
     * Take everything registered so far, leaving the list empty.
     *
     * Draining rather than reading: a callback is free to register another, and the
     * caller keeps draining until nothing new arrives.
     *
     * @return list<callable|string>
     */
    public function drain(): array
    {
        $taken = $this->callbacks;

        $this->callbacks = [];

        return $taken;
    }
}
