<?php

declare(strict_types=1);

namespace Modules\Auth\Public\Actions;

use Illuminate\Auth\AuthManager;
use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Contracts\Hashing\Hasher;
use Illuminate\Contracts\Session\Session;
use Modules\Auth\Internal\Lock\AppLockProvisioner;
use Modules\Core\Models\User;

/**
 * Single permissible write path for credential-based sign-in.
 *
 * Normalises the supplied username (lowercase, trimmed) and verifies the
 * bcrypt password hash via the injected `Hasher`. On success the user is
 * logged in through the active guard and `true` is returned; on failure
 * the action returns `false` without distinguishing a missing account
 * from a wrong password, so callers can render a single generic error.
 *
 * App-lock coherence (WR-03 / LOCK-04): when the user has the app lock
 * enabled, a successful login primes the session via
 * AppLockProvisioner::primeSessionAfterLogin() — the plaintext password is
 * available right here, so the data key is recovered through the D-21
 * password recovery wrap and the session starts unlocked WITH the key.
 * If that unwrap fails the session starts LOCKED (PIN restores the key).
 * A lock-enabled session is never left "unlocked without a key".
 */
final class LoginAction
{
    public function __construct(
        private readonly Hasher $hasher,
        private readonly AuthManager $auth,
        private readonly AppLockProvisioner $provisioner,
        private readonly Session $session,
    ) {}

    public function __invoke(string $usernameInput, string $password, bool $rememberMe): bool
    {
        $normalized = strtolower(trim($usernameInput));

        /** @var User|null $user */
        $user = User::query()->where('username', $normalized)->first();

        if (! $user instanceof User || ! $this->hasher->check($password, $user->password)) {
            return false;
        }

        /** @var StatefulGuard $guard */
        $guard = $this->auth->guard();
        $guard->login($user, $rememberMe);

        // WR-03: establish coherent lock state while the plaintext password
        // is still in hand (no-op when the lock is not enabled).
        $this->provisioner->primeSessionAfterLogin($user->id, $password, $this->session);

        return true;
    }
}
