<?php

namespace Spawn\Laravel\Tests\Fixtures;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\ServiceProvider;

use function Async\delay;

/**
 * Example of what the package has to keep working: an application that registers
 * its own auth driver once, during boot, in the plain Laravel way, and expects it
 * to be there on every request.
 *
 * Nothing here knows about coroutines — that is the point. The guard callback
 * yields, as a real one does when it looks the token up in the database, which is
 * what turns a shared manager from untidy into visibly wrong.
 */
class BotTokenAuthServiceProvider extends ServiceProvider
{
    public const TOKEN_HEADER = 'X-Bot-Token';

    public const DRIVER = 'bot-user-token';

    public function boot(): void
    {
        Auth::viaRequest(self::DRIVER, function (Request $request): ?BotUser {
            $token = $request->headers->get(self::TOKEN_HEADER);

            if ($token === null) {
                return null;
            }

            /* Stands in for the database lookup a real driver does here. */
            delay(20);

            return new BotUser($token);
        });
    }
}
