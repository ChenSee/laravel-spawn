<?php

namespace Spawn\Laravel\Foundation;

use ReflectionClass;
use ReflectionProperty;

/**
 * Finds shared objects that hold a per-request one.
 *
 * A singleton lives as long as the worker; a per-request service lives for one request.
 * When the first captures the second — usually a constructor parameter kept in a
 * property — every later request is served by an object still wired to the first
 * request's state. StartSession and Redirector were both found that way, by accident;
 * this turns the accident into a walk.
 *
 * The walk sees properties and array elements. What a closure captured, and what lives
 * behind a resource or an external handle, it cannot reach: an empty result means clean
 * as far as the walk sees, not safe.
 */
final class PerRequestCaptureAudit
{
    /**
     * How many objects deep a capture is still reported.
     *
     * A capture is normally a constructor parameter kept in a property, which is depth 1.
     * The further levels catch a singleton that keeps the per-request object inside a
     * collaborator of its own; past that the path stops naming anything its author can
     * act on, while the walk keeps growing with the object graph.
     */
    private const MAX_DEPTH = 3;

    /**
     * How many nested arrays a capture is still reported through.
     *
     * Array elements sit at the depth of the object holding them, because a service kept
     * in a registry is captured just as surely as one kept in a property. Nesting still
     * needs a floor of its own: a reference cycle inside an array would otherwise not
     * terminate, and the configuration repository alone is thousands of nested entries
     * whose leaves are strings.
     */
    private const MAX_ARRAY_DEPTH = 4;

    /**
     * @param  array<int, string>  $perRequestByObjectId  spl_object_id() => the alias that object serves
     * @param  array<int, int>  $ownerIds  spl_object_id() => 0, the objects the walk refuses to enter
     */
    private function __construct(
        private readonly array $perRequestByObjectId,
        private readonly array $ownerIds,
    ) {
    }

    /**
     * Audit against the objects currently serving the per-request aliases.
     *
     * Identity is the whole test, so an alias with no object behind it contributes
     * nothing: nobody can have captured what has not been built.
     *
     * @param  array<string, object[]>  $perRequestInstances  alias => the objects serving
     *   it. One alias can have more than one: bootstrap builds an instance before the
     *   scoping starts, and each request builds its own afterwards. A singleton can have
     *   captured any of them, and which one it captured says when, not whether.
     * @param  object[]  $owners  objects allowed to hold a per-request instance, and
     *   therefore not walked into. The container is one: it holds every service by
     *   definition, and almost every service holds the container back, so a walk that
     *   enters it reports the same path through every subject it is given.
     */
    public static function against(array $perRequestInstances, array $owners = []): self
    {
        $byObjectId = [];

        foreach ($perRequestInstances as $alias => $instances) {
            foreach ($instances as $instance) {
                // First alias wins: several aliases share one object, and the one the
                // container was asked for reads better in a report than its target.
                $byObjectId[spl_object_id($instance)] ??= $alias;
            }
        }

        $ownerIds = [];

        foreach ($owners as $owner) {
            $ownerIds[spl_object_id($owner)] = 0;
        }

        return new self($byObjectId, $ownerIds);
    }

    /**
     * Whether this object is one of the per-request instances the audit was built from.
     *
     * A per-request object is a subject nobody needs audited: what it holds belongs to
     * the same request it does.
     */
    public function isPerRequest(object $subject): bool
    {
        return isset($this->perRequestByObjectId[spl_object_id($subject)]);
    }

    /**
     * Every per-request object reachable from $subject.
     *
     * @return array<string, string> the path that reached it => the alias it serves. A path
     *   starts at a property of $subject and reads one step per level, array keys included:
     *   "manager->drivers['session']".
     */
    public function capturesIn(object $subject): array
    {
        $found = [];
        $seen  = $this->ownerIds + [spl_object_id($subject) => 0];

        $this->walkProperties($subject, '', 1, $seen, $found);

        return $found;
    }

    /**
     * @param  array<int, int>  $seen  object id => the depth it was walked at
     * @param  array<string, string>  $found
     */
    private function walkProperties(object $subject, string $path, int $depth, array &$seen, array &$found): void
    {
        foreach ($this->propertiesOf($subject) as $property) {
            if ($property->isStatic() || ! $property->isInitialized($subject)) {
                continue;
            }

            $step = $property->getName();

            $this->walk(
                $property->getValue($subject),
                $path === '' ? $step : $path . '->' . $step,
                $depth,
                0,
                $seen,
                $found,
            );
        }
    }

    /**
     * @param  array<int, int>  $seen
     * @param  array<string, string>  $found
     */
    private function walk(mixed $value, string $path, int $depth, int $arrayDepth, array &$seen, array &$found): void
    {
        if (is_array($value)) {
            if ($arrayDepth >= self::MAX_ARRAY_DEPTH) {
                return;
            }

            foreach ($value as $key => $item) {
                $step = '[' . (is_int($key) ? $key : "'{$key}'") . ']';

                $this->walk($item, $path . $step, $depth, $arrayDepth + 1, $seen, $found);
            }

            return;
        }

        if (! is_object($value)) {
            return;
        }

        $id = spl_object_id($value);

        if (isset($this->perRequestByObjectId[$id])) {
            // The capture is the finding. What the per-request object holds in turn
            // belongs to it and is not a leak of the shared object that named it.
            $found[$path] = $this->perRequestByObjectId[$id];

            return;
        }

        if ($depth >= self::MAX_DEPTH) {
            return;
        }

        // Walked before, but possibly by a longer path that ran out of budget before it
        // reached what this one still can. Only a visit at the same depth or shallower
        // makes a second one pointless — and it is what ends a cycle.
        if (isset($seen[$id]) && $seen[$id] <= $depth) {
            return;
        }

        $seen[$id] = $depth;

        $this->walkProperties($value, $path, $depth + 1, $seen, $found);
    }

    /**
     * Declared properties of the object, its own first and its parents' after.
     *
     * Reflection on a class omits the private properties of its parents, and a captured
     * service is exactly the kind of thing a base class keeps private.
     *
     * @return ReflectionProperty[]
     */
    private function propertiesOf(object $subject): array
    {
        $properties = [];

        for ($class = new ReflectionClass($subject); $class !== false; $class = $class->getParentClass()) {
            foreach ($class->getProperties() as $property) {
                // Inherited properties are reported by every class down the chain; the
                // first sighting is the one that can read the value.
                $properties[$property->getName()] ??= $property;
            }
        }

        return $properties;
    }
}
