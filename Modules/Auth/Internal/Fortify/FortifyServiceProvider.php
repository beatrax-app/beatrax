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

/**
 * Configures Fortify to authenticate the active user against
 * `users.username` via the injected `Hasher` contract.
 *
 * The authenticator closure normalises the submitted username
 * (lowercase, trimmed) before the lookup and returns a generic null on
 * any miss, so a wrong password and a missing account are
 * indistinguishable from the response.
 *
 * The pipeline omits the throttle middleware by design: the deployment
 * is local-only and single-machine, so the natural cost factor of the
 * bcrypt hash is the defence against credential guessing rather than a
 * per-IP rate limiter.
 *
 * `Fortify::authenticateUsing()` / `Fortify::authenticateThrough()` are
 * Fortify's own configuration DSL — library static methods, not Laravel
 * facades — so the facadeless rule needs no exemption for them.
 */
final class FortifyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Fortify auto-binds its own services; no container wiring needed.
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
