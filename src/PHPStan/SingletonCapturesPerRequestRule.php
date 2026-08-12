<?php

declare(strict_types=1);

namespace Spawn\Laravel\PHPStan;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Identifier;
use PhpParser\NodeFinder;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use PHPStan\Type\ObjectType;
use PHPStan\Type\Type;
use Spawn\Laravel\Foundation\ScopedService;

/**
 * Flags a singleton registration whose object takes a per-request service.
 *
 * A singleton lives as long as the worker and a per-request service lives for one
 * request, so the object keeps answering through whichever request built it first.
 * That is how StartSession came to leave two concurrent logins with one session
 * cookie, and how the Redirector came to flash one user's errors into another's
 * session. Registering the same class with scoped() gives every request its own.
 *
 * Two shapes are recognised: singleton(Foo::class), where the constructor is read
 * straight off Foo, and singleton($abstract, fn () => new Foo(...)), where it is read
 * off every class the closure constructs. A closure that returns an object it did not
 * construct — a manager's driver, a value out of another service — is invisible here;
 * the runtime walk in PerRequestCaptureAudit is what covers those.
 *
 * @implements Rule<MethodCall>
 */
final class SingletonCapturesPerRequestRule implements Rule
{
    /**
     * Matched against a lowercased method name, which is why `singletonIf` is spelled
     * in lower case here.
     */
    private const REGISTRATION_METHODS = ['singleton', 'singletonif'];

    /**
     * Services this package scopes itself, beyond the aliases ScopedService names.
     *
     * Kept here rather than in the enum because the enum maps an alias to the key it is
     * stored under, and these have no key of their own: they are registered through
     * scopedSingleton() in AsyncServiceProvider. The concrete class is enough — a
     * parameter typed against the contract accepts the class that implements it, so
     * listing the contract as well would match nothing the class does not already match.
     *
     * @var class-string[]
     */
    private const PACKAGE_SCOPED_TYPES = [
        \Illuminate\Routing\UrlGenerator::class,
        \Illuminate\Routing\ResponseFactory::class,
        \Illuminate\Foundation\Vite::class,
        \Illuminate\Log\Context\Repository::class,
    ];

    /**
     * The whole list, built once: this rule runs on every method call in the analysed code.
     *
     * @var class-string[]|null
     */
    private ?array $allPerRequestTypes = null;

    /**
     * @param  class-string[]  $perRequestTypes  types the application declared per-request
     *   itself, through config('async.scoped_services'). The container reads that list at
     *   runtime; static analysis cannot, so an application that scopes services of its own
     *   passes their types here from its own phpstan configuration.
     */
    public function __construct(
        private readonly ReflectionProvider $reflectionProvider,
        private readonly array $perRequestTypes = [],
    ) {
    }

    public function getNodeType(): string
    {
        return MethodCall::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        if (! $node->name instanceof Identifier) {
            return [];
        }

        if (! in_array(strtolower($node->name->toString()), self::REGISTRATION_METHODS, true)) {
            return [];
        }

        if (! $this->isContainer($scope->getType($node->var))) {
            return [];
        }

        $errors = [];

        foreach ($this->registeredClasses($scope, $node) as $class) {
            foreach ($this->perRequestParameters($class) as $parameter => $service) {
                $errors[] = RuleErrorBuilder::message(sprintf(
                    'Singleton %s takes the per-request %s as $%s: every request after the first is '
                    . 'served through the first one. Register it with scoped() instead.',
                    $class,
                    $service,
                    $parameter,
                ))
                    ->identifier('coroutine.singletonCapturesPerRequest')
                    ->line($node->getStartLine())
                    ->build();
            }
        }

        return $errors;
    }

    private function isContainer(Type $type): bool
    {
        return (new ObjectType(\Illuminate\Contracts\Container\Container::class))->isSuperTypeOf($type)->yes();
    }

    /**
     * The classes this registration will construct, as far as the call site shows them.
     *
     * @return class-string[]
     */
    private function registeredClasses(Scope $scope, MethodCall $call): array
    {
        $args = $call->getArgs();

        if ($args === []) {
            return [];
        }

        // No concrete: the container builds the abstract itself.
        if (! isset($args[1])) {
            return $this->classNames($scope->getType($args[0]->value));
        }

        $concrete = $args[1]->value;
        $named    = $this->classNames($scope->getType($concrete));

        return $named !== [] ? $named : $this->constructedClasses($scope, $concrete);
    }

    /**
     * Every class instantiated anywhere inside the expression.
     *
     * @return class-string[]
     */
    private function constructedClasses(Scope $scope, Expr $concrete): array
    {
        $classes = [];

        foreach ((new NodeFinder())->findInstanceOf($concrete, New_::class) as $new) {
            if (! $new->class instanceof Node\Name) {
                continue;
            }

            $name = $scope->resolveName($new->class);

            if ($this->reflectionProvider->hasClass($name)) {
                $classes[] = $name;
            }
        }

        return $classes;
    }

    /**
     * Class names a type stands for, empty for anything that is not a class-string.
     *
     * @return class-string[]
     */
    private function classNames(Type $type): array
    {
        $names = [];

        foreach ($type->getConstantStrings() as $string) {
            $name = $string->getValue();

            if ($this->reflectionProvider->hasClass($name)) {
                $names[] = $name;
            }
        }

        return $names;
    }

    /**
     * Constructor parameters of the class that accept a per-request service.
     *
     * @param  class-string  $class
     * @return array<string, class-string> parameter name => the per-request type it accepts
     */
    private function perRequestParameters(string $class): array
    {
        if (! $this->reflectionProvider->hasClass($class)) {
            return [];
        }

        $reflection = $this->reflectionProvider->getClass($class);

        if (! $reflection->hasConstructor()) {
            return [];
        }

        $found = [];

        foreach ($reflection->getConstructor()->getOnlyVariant()->getParameters() as $parameter) {
            $type = $parameter->getType();

            // mixed, object and untyped accept a per-request service the same way they
            // accept everything else, and reporting them would report every constructor.
            if ($type->getObjectClassNames() === []) {
                continue;
            }

            foreach ($this->perRequestTypes() as $perRequest) {
                if ($type->isSuperTypeOf(new ObjectType($perRequest))->yes()) {
                    $found[$parameter->getName()] = $perRequest;

                    break;
                }
            }
        }

        return $found;
    }

    /**
     * @return class-string[]
     */
    private function perRequestTypes(): array
    {
        if ($this->allPerRequestTypes !== null) {
            return $this->allPerRequestTypes;
        }

        $types = array_merge($this->perRequestTypes, self::PACKAGE_SCOPED_TYPES);

        foreach (ScopedService::cases() as $case) {
            foreach ($case->types() as $type) {
                $types[] = $type;
            }
        }

        return $this->allPerRequestTypes = $types;
    }
}
