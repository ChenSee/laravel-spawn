<?php

namespace Spawn\Laravel\View;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\View\Engines\EngineResolver;
use Illuminate\View\Factory;
use Illuminate\View\ViewFinderInterface;
use Spawn\Laravel\Foundation\RequestContext;

use function Async\root_context;

/**
 * A view factory whose render state belongs to the request instead of the worker.
 *
 * There is one factory per worker, as upstream intends: templates receive it as `$__env`,
 * `Component::$factory` caches it in a static, and `MailManager` and `Markdown` keep it in
 * their constructors. What moves per request is the state it writes while rendering —
 * `@section`, `@push`, the component and slot stacks, the nesting counter and the `@once`
 * ledger — because a render suspends whenever it touches the world: a composer queries the
 * database, a template reads a lazy relation, a template is compiled for the first time.
 * Shared, two responses write into one set of sections, and the nesting counter of one
 * render reaching zero empties the sections of the other in the middle of a page.
 *
 * The state moves without touching the framework methods that write it, of which there are
 * about fifty. The declared properties are removed from this object in the constructor, so
 * every read and write the inherited traits make falls through to `__get()` and `__set()`
 * and lands in a {@see BladeRenderState} taken from the request's context. `__get()`
 * returns by reference, which is what lets unmodified code run
 * `array_pop($this->sectionStack)` and `$this->pushes[$section][$key] .= $content`.
 *
 * Before bootCompleted(), and outside a request, one state held here answers instead: an
 * error page rendered during bootstrap, an artisan command and a test all keep working,
 * and nothing per-request is stored where the next request would inherit it.
 */
class AsyncViewFactory extends Factory
{
    /**
     * The properties of Factory and its concerns that belong to one render.
     *
     * Checked against the framework by ViewRenderStateTest, which fails when an upgrade
     * adds a property that is in neither this list nor its list of configuration.
     */
    private const RENDER_STATE = [
        'sections',
        'sectionStack',
        'pushes',
        'prepends',
        'pushStack',
        'componentStack',
        'componentData',
        'currentComponentData',
        'slots',
        'slotStack',
        'fragments',
        'fragmentStack',
        'loopsStack',
        'translationReplacements',
        'renderCount',
        'renderedOnce',
    ];

    private const CTX_KEY = 'view.shared';

    private const RENDER_STATE_KEY = 'view.render-state';

    private bool $async = false;

    /**
     * The render state used while there is no request to attach one to.
     */
    private BladeRenderState $processState;

    /**
     * The context the last render state came from, held so that its object handle cannot
     * be handed to another context, and the state that belongs to it.
     */
    private ?\Async\Context $memoContext = null;

    private ?BladeRenderState $memoState = null;

    public function __construct(EngineResolver $engines, ViewFinderInterface $finder, Dispatcher $events)
    {
        $this->processState = new BladeRenderState();

        parent::__construct($engines, $finder, $events);

        foreach (self::RENDER_STATE as $property) {
            unset($this->$property);
        }
    }

    public function bootCompleted(): void
    {
        $this->async = true;
    }

    /**
     * @return mixed a reference into this request's render state, so that the inherited
     *   trait methods can modify its arrays and counters in place
     */
    public function &__get(string $name)
    {
        $state = $this->renderState();

        return $state->$name;
    }

    public function __set(string $name, mixed $value): void
    {
        $this->renderState()->$name = $value;
    }

    public function __isset(string $name): bool
    {
        return isset($this->renderState()->$name);
    }

    public function __unset(string $name): void
    {
        unset($this->renderState()->$name);
    }

    /**
     * Share data with every view of this request, or of the worker before there are any.
     *
     * Before bootCompleted() the data joins the boot-time set the whole worker reads;
     * after it, an overlay in the request's context, which no other request sees.
     */
    public function share($key, $value = null)
    {
        if (! $this->async) {
            return parent::share($key, $value);
        }

        $keys = is_array($key) ? $key : [$key => $value];

        $ctx = RequestContext::current();
        $shared = $ctx->find(self::CTX_KEY);

        if ($shared === null) {
            $ctx->set(self::CTX_KEY, $keys);
        } else {
            foreach ($keys as $k => $v) {
                $shared[$k] = $v;
            }
            $ctx->set(self::CTX_KEY, $shared, replace: true);
        }

        return $value;
    }

    /**
     * The boot-time shared data with this request's overlay on top of it.
     */
    public function getShared()
    {
        if (! $this->async) {
            return parent::getShared();
        }

        $perRequest = RequestContext::current()->find(self::CTX_KEY) ?? [];

        return array_merge($this->shared, $perRequest);
    }

    /**
     * The render state of the request being served, or the process-wide one.
     *
     * Every read and write of the sixteen moved properties arrives here, which a
     * `@foreach` does on every iteration, so the answer for the last context is kept.
     * At 500 rows a page the lookup cost 225 of the 785 microseconds a render took, and
     * 98 of 668 with the answer kept: 15 per cent off the render
     * (tests/bench/bench_render.php, release build, median of eleven runs).
     *
     * Keeping the context object is what makes comparing it by identity safe. PHP hands
     * a freed object's handle to the next one, so a context released between requests
     * could otherwise come back as the same `===` and answer request B with the state of
     * request A; a context this factory still holds is never freed.
     */
    private function renderState(): BladeRenderState
    {
        if (! $this->async) {
            return $this->processState;
        }

        $context = RequestContext::current();

        if ($context === $this->memoContext) {
            return $this->memoState;
        }

        if ($context === root_context()) {
            return $this->processState;
        }

        $state = $context->find(self::RENDER_STATE_KEY);

        if (! $state instanceof BladeRenderState) {
            $state = new BladeRenderState();

            $context->set(self::RENDER_STATE_KEY, $state);
        }

        $this->memoContext = $context;
        $this->memoState   = $state;

        return $state;
    }
}
