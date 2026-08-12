<?php

namespace Spawn\Laravel\Tests;

use Illuminate\View\Factory;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionClassConstant;
use Spawn\Laravel\View\AsyncViewFactory;
use Spawn\Laravel\View\BladeRenderState;

/**
 * The list of properties AsyncViewFactory moves per request, checked against the
 * framework it was written for.
 *
 * A property added by a Laravel upgrade stays process-shared until it is classified, and
 * nothing about that fails on its own: the render still works, and two concurrent renders
 * quietly write into one value. This test is what makes the upgrade say so.
 */
class ViewRenderStateTest extends TestCase
{
    /**
     * Properties of the factory that describe the worker rather than a render: where
     * templates are found, which engine renders them, who is listening.
     */
    private const CONFIGURATION = [
        'engines',
        'finder',
        'events',
        'container',
        'shared',
        'extensions',
        'composers',
        'pathEngineCache',
        'normalizedNameCache',
    ];

    /**
     * @return string[]
     */
    private function renderState(): array
    {
        return (new ReflectionClassConstant(AsyncViewFactory::class, 'RENDER_STATE'))->getValue();
    }

    public function test_every_property_of_the_framework_factory_is_classified(): void
    {
        $declared = [];

        foreach ((new ReflectionClass(Factory::class))->getProperties() as $property) {
            if (! $property->isStatic()) {
                $declared[] = $property->getName();
            }
        }

        $unclassified = array_diff($declared, $this->renderState(), self::CONFIGURATION);

        $this->assertSame(
            [],
            array_values($unclassified),
            'Illuminate\View\Factory grew a property. Add it to AsyncViewFactory::RENDER_STATE and '
            . 'BladeRenderState if a render writes it, or to this test\'s CONFIGURATION if the worker owns it.',
        );
    }

    public function test_the_state_holder_carries_exactly_the_moved_properties(): void
    {
        $held = [];

        foreach ((new ReflectionClass(BladeRenderState::class))->getProperties() as $property) {
            $held[] = $property->getName();
        }

        sort($held);
        $moved = $this->renderState();
        sort($moved);

        $this->assertSame($moved, $held);
    }

    public function test_the_holder_starts_where_the_framework_starts(): void
    {
        $factory = new ReflectionClass(Factory::class);
        $holder  = new BladeRenderState();

        foreach ($this->renderState() as $name) {
            $this->assertSame(
                $factory->getDefaultProperties()[$name],
                $holder->$name,
                "BladeRenderState::\${$name} must start at the framework's own default",
            );
        }
    }
}
