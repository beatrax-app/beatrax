<?php

declare(strict_types=1);

namespace Modules\Core\Internal\Providers;

use Illuminate\Cache\RateLimiter;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Hashing\Hasher;
use Illuminate\Http\Request;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Laravel\Fortify\Fortify;
use Modules\Core\Models\User;

/**
 * Configures Fortify for the single-user, localhost-only deployment:
 *
 * - Mounts the hand-written Livewire login view at `core::auth.login`.
 * - Authenticates email + password directly against the User model via the
 *   injected Hasher contract.
 *
 * `Fortify::loginView()` / `Fortify::authenticateUsing()` are Fortify's own
 * configuration DSL — they are library static methods, not Laravel facades,
 * so the facadeless rule does not need an exemption.
 */
final class FortifyServiceProvider extends ServiceProvider
{
    public function boot(Repository $config, Hasher $hasher, RateLimiter $rateLimiter): void
    {
        // Fortify-bound sessions run for 30 days regardless of the
        // session.lifetime value in config/session.php so the "remember me"
        // checkbox on the login form behaves consistently.
        $config->set('session.lifetime', 60 * 24 * 30);
        $config->set('session.expire_on_close', false);

        Fortify::loginView('core::auth.login');

        Fortify::authenticateUsing(static function (Request $request) use ($hasher): ?User {
            $email = $request->input('email');
            $password = $request->input('password');

            if (! is_string($email) || ! is_string($password)) {
                return null;
            }

            /** @var User|null $user */
            $user = User::query()->where('email', $email)->first();

            if ($user instanceof User && $hasher->check($password, $user->password)) {
                return $user;
            }

            return null;
        });

        // Login rate limiter (5/minute keyed by email + IP). Fortify references
        // this limiter by name via config('fortify.limiters.login').
        $rateLimiter->for('login', static function (Request $request): Limit {
            $email = $request->input(Fortify::username());
            $emailKey = is_string($email) ? Str::lower($email) : '';
            $throttleKey = Str::transliterate($emailKey.'|'.((string) $request->ip()));

            return Limit::perMinute(5)->by($throttleKey);
        });
    }
}
