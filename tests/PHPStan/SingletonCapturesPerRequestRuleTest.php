<?php

namespace Spawn\Laravel\Tests\PHPStan;

use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use Spawn\Laravel\PHPStan\SingletonCapturesPerRequestRule;

/**
 * @extends RuleTestCase<SingletonCapturesPerRequestRule>
 */
class SingletonCapturesPerRequestRuleTest extends RuleTestCase
{
    protected function getRule(): Rule
    {
        return new SingletonCapturesPerRequestRule(self::createReflectionProvider());
    }

    public function test_it_flags_start_session_registered_as_a_singleton(): void
    {
        $captured = 'Singleton Illuminate\Session\Middleware\StartSession takes the per-request '
            . 'Illuminate\Session\SessionManager as $manager: every request after the first is served '
            . 'through the first one. Register it with scoped() instead.';

        $this->analyse([__DIR__ . '/Fixtures/SingletonRegistrations.php'], [
            [$captured, 14],
            [$captured, 19],
            [$captured, 27],
        ]);
    }

    public static function getAdditionalConfigFiles(): array
    {
        return [];
    }
}
