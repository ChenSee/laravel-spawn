<?php

namespace Spawn\Laravel\View;

/**
 * The Blade render state of one request.
 *
 * These are the properties `Illuminate\View\Factory` and its concerns declare and write
 * while a view renders: the bodies of `@section`, the `@push` and `@prepend` stacks, the
 * component and slot stacks, the nesting counter that decides when a render is finished,
 * and the ledger of `@once` blocks. Every one of them belongs to the page being rendered
 * and to nothing else.
 *
 * The defaults match the traits exactly, because {@see AsyncViewFactory} hands this
 * object to unmodified framework code that expects them.
 *
 * Every property here is also named in `AsyncViewFactory::RENDER_STATE`, which is what
 * takes it off the factory; `ViewRenderStateTest` fails when the two lists diverge.
 */
final class BladeRenderState
{
    public array $sections = [];

    public array $sectionStack = [];

    public array $pushes = [];

    public array $prepends = [];

    public array $pushStack = [];

    public array $componentStack = [];

    public array $componentData = [];

    public array $currentComponentData = [];

    public array $slots = [];

    public array $slotStack = [];

    public array $fragments = [];

    public array $fragmentStack = [];

    public array $loopsStack = [];

    public array $translationReplacements = [];

    /**
     * How deep the current render is. `Factory::flushStateIfDoneRendering()` empties the
     * state when it falls back to zero, so a request sharing this counter with another
     * has its sections wiped in the middle of a page.
     */
    public int $renderCount = 0;

    public array $renderedOnce = [];
}
