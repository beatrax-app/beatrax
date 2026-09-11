<?php

declare(strict_types=1);

namespace Modules\Auth\Internal\Fortify;

use Illuminate\Contracts\Hashing\Hasher;
use Illuminate\Http\Request;
use Illuminate\Support\ServiceProvider;
use Laravel\Fortify\Actions\AttemptToAuthenticate;
use Laravel\Fortify\Actions\PrepareAuthenticatedSession;
use Laravel\Fortify\Fortify;
use Modules\Auth\Internal\Services\SignInThrottle;
use Modules\Auth\Public\Support\Username;
use Modules\Core\Models\User;

// Metered in the callback rather than by route middleware. The Livewire form
// posts to /livewire/update, which no route middleware on /login ever sees, so
// a limiter declared on the route would leave the everyday path unmetered and
// look like it had covered it.
/**
 * @link ../../../../.docs/features/auth/architecture.md
 */
final class FortifyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Nothing to bind: every Fortify callback needs a resolved Hasher, so
        // all of this provider's wiring waits for boot().
    }

    public function boot(Hasher $hasher, SignInThrottle $throttle): void
    {
        Fortify::authenticateUsing(static function (Request $request) use ($hasher, $throttle): ?User {
            $username = $request->input('username');
            $password = $request->input('password');

            // Fortify has no channel for a wait, so a spent meter answers here
            // exactly as a wrong password does -- the same null a malformed
            // payload gets. The reader told nothing useful is the one who
            // bypassed the Livewire form.
            if (! is_string($username) || ! is_string($password) || $throttle->isExhausted($username)) {
                return null;
            }

            $throttle->recordAttempt($username);

            $normalized = Username::normalize($username);

            /** @var User|null $user */
            $user = User::query()->where('username', $normalized)->first();

            if ($user instanceof User && $hasher->check($password, $user->password)) {
                $throttle->clear($username);

                return $user;
            }

            return null;
        });

        Fortify::authenticateThrough(static fn (Request $request): array => [
            AttemptToAuthenticate::class,
            PrepareAuthenticatedSession::class,
            PrimeAppLockSession::class,
        ]);
    }
}
