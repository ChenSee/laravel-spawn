<?php

namespace Spawn\Laravel\Debugbar\Collectors;

use Spawn\Laravel\Foundation\RequestContext;

/**
 * A collector subclass stays a single shared object (so it survives Debugbar's
 * instanceof checks and the one-time event subscription captures it), but routes
 * all accumulated data to a real collector kept in the request's context.
 *
 * The subclass supplies a factory (which also copies any shared configuration onto
 * the fresh per-request collector) and forwards its data methods through delegate().
 */
trait DelegatesToContext
{
    private string $contextKey;

    /** @var callable():object */
    private $delegateFactory;

    protected function bindContextDelegate(string $key, callable $factory): void
    {
        $this->contextKey = $key;
        $this->delegateFactory = $factory;
    }

    protected function delegate(): object
    {
        $ctx = RequestContext::current();
        $collector = $ctx->find($this->contextKey);

        if ($collector === null) {
            $collector = ($this->delegateFactory)();
            $ctx->set($this->contextKey, $collector);
        }

        return $collector;
    }
}
