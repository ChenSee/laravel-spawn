<?php

namespace Spawn\Laravel\Foundation;

use Illuminate\Foundation\AliasLoader;
use Illuminate\Support\Facades\Facade;
use ReflectionMethod;

/**
 * The container keys this application's facades resolve through.
 *
 * A facade names its key in a protected static method, so reflection is the only way to
 * read the key without resolving the service. The facades come from the two lists Laravel
 * builds an application's aliases from (the framework's own and whatever the application
 * added to it), so nothing here carries a list of its own.
 */
final class FacadeRoots
{
    /**
     * Every container key a facade of this application resolves.
     *
     * Nothing is resolved: the keys are read from the facade classes. A facade that names
     * no key is left out, which covers `Facade` itself and any subclass answering through
     * `getFacadeRoot()` instead.
     *
     * @return string[] keys, each one once
     */
    public static function accessors(): array
    {
        $accessors = [];

        foreach (self::facades() as $facade) {
            $accessor = self::accessorOf($facade);

            if ($accessor !== null) {
                $accessors[$accessor] = true;
            }
        }

        return array_keys($accessors);
    }

    /**
     * @return string[] the facade classes of the framework and of the application
     */
    private static function facades(): array
    {
        $aliases = array_merge(
            Facade::defaultAliases()->values()->all(),
            array_values(AliasLoader::getInstance()->getAliases()),
        );

        // The alias lists carry helpers as well as facades — Arr, Str, the Eloquent model.
        return array_filter(
            $aliases,
            static fn ($class) => is_string($class) && is_subclass_of($class, Facade::class),
        );
    }

    private static function accessorOf(string $facade): ?string
    {
        $accessor = new ReflectionMethod($facade, 'getFacadeAccessor');

        // Facade::getFacadeAccessor() throws by contract, so a subclass that did not
        // override it resolves some other way and has no key to report.
        if ($accessor->getDeclaringClass()->getName() === Facade::class) {
            return null;
        }

        $key = $accessor->invoke(null);

        return is_string($key) ? $key : null;
    }
}
