<?php

namespace Spawn\Laravel\Config;

use Illuminate\Config\Repository;
use Illuminate\Support\Arr;
use Spawn\Laravel\Foundation\RequestContext;

use function Async\root_context;

/**
 * Coroutine-safe Config Repository.
 *
 * Before bootCompleted(): behaves like stock Repository (writes to $items).
 * After bootCompleted(): set() writes to the request's own overlay in RequestContext.
 * get() checks overlay first, then falls through to base $items.
 *
 * Base $items are immutable after boot — shared read-only across all coroutines.
 */
class AsyncConfig extends Repository
{
    /**
     * The context entry every instance keeps its overlay under.
     *
     * One key for the whole class, so an overlay left in a long-lived context answers
     * for every AsyncConfig that reads it afterwards.
     */
    public const CTX_KEY = 'config.overlay';

    private bool $async = false;

    private bool $diagnostics = false;

    public function bootCompleted(): void
    {
        // Cached here rather than read inside set(): a coroutine that writes this very
        // key would otherwise turn the report off for its own writes, and the lookup
        // would repeat on every write for a setting that cannot change after boot.
        $this->diagnostics = (bool) $this->get('async.diagnostics', false);
        $this->async       = true;
    }

    public function set($key, $value = null)
    {
        if (! $this->async) {
            parent::set($key, $value);
            return;
        }

        $ctx = RequestContext::current();
        $overlay = $ctx->find(self::CTX_KEY) ?? [];

        $keys = is_array($key) ? $key : [$key => $value];

        // The root context only. A write from any other scope without a request is lost
        // the same way, but under DevServer and artisan every write comes from such a
        // scope, and the wider test would report the ordinary case as a defect.
        if ($this->diagnostics && $ctx === root_context()) {
            $this->reportRootContextWrite(array_keys($keys));
        }

        foreach ($keys as $k => $v) {
            Arr::set($overlay, $k, $v);
        }

        $ctx->set(self::CTX_KEY, $overlay, replace: true);
    }

    public function get($key, $default = null)
    {
        if (! $this->async) {
            return parent::get($key, $default);
        }

        if (is_array($key)) {
            return $this->getMany($key);
        }

        $overlay = RequestContext::current()->find(self::CTX_KEY);

        if ($overlay !== null && Arr::has($overlay, $key)) {
            $base = Arr::get($this->items, $key);
            $override = Arr::get($overlay, $key);

            if (is_array($base) && is_array($override)) {
                return array_replace_recursive($base, $override);
            }

            return $override;
        }

        return Arr::get($this->items, $key, $default);
    }

    public function getMany($keys)
    {
        $config = [];

        foreach ($keys as $key => $default) {
            if (is_numeric($key)) {
                [$key, $default] = [$default, null];
            }

            $config[$key] = $this->get($key, $default);
        }

        return $config;
    }

    public function has($key)
    {
        if (! $this->async) {
            return parent::has($key);
        }

        $overlay = RequestContext::current()->find(self::CTX_KEY);

        if ($overlay !== null && Arr::has($overlay, $key)) {
            return true;
        }

        return Arr::has($this->items, $key);
    }

    public function all()
    {
        if (! $this->async) {
            return parent::all();
        }

        $overlay = RequestContext::current()->find(self::CTX_KEY) ?? [];

        return array_replace_recursive($this->items, $overlay);
    }

    /**
     * @param  string[]  $keys
     */
    private function reportRootContextWrite(array $keys): void
    {
        error_log(
            "[async] config write from the root context: '".implode("', '", $keys)
            ."'; requests run in their own scope and read the base configuration instead"
        );
    }
}
