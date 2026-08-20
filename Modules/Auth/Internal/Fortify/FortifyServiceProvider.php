<?php

declare(strict_types=1);

namespace Modules\Auth\Internal\Fortify;

use Illuminate\Contracts\Hashing\Hasher;
use Illuminate\Http\Request;
use Illuminate\Support\ServiceProvider;
use Laravel\Fortify\Actions\AttemptToAuthenticate;
use Laravel\Fortify\Actions\PrepareAuthenticatedSession;
use Laravel\Fortify\Fortify;
use Modules\Core\Models\User;

// No throttle middleware by design: this is a local-only, single-machine
// deployment, so the password hash cost is the credential-guessing defence
// rather than a per-IP limiter.
final class FortifyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Nothing to bind: Fortify registers its own services.
    }

    public function boot(Hasher $hasher): void
    {
        Fortify::authenticateUsing(static function (Request $request) use ($hasher): ?User {
            $username = $request->input('username');
            $password = $request->input('password');

            if (! is_string($username) || ! is_string($password)) {
                return null;
            }

            $normalized = strtolower(trim($username));

            /** @var User|null $user */
            $user = User::query()->where('username', $normalized)->first();

            if ($user instanceof User && $hasher->check($password, $user->password)) {
                return $user;
            }

            return null;
        });

        Fortify::authenticateThrough(static fn (Request $request): array => [
            AttemptToAuthenticate::class,
            PrepareAuthenticatedSession::class,
        ]);
    }
}
