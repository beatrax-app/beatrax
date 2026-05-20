<?php

declare(strict_types=1);

namespace Modules\Auth\Public\Actions;

use Illuminate\Auth\AuthManager;
use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Contracts\Hashing\Hasher;
use Modules\Core\Models\User;

/**
 * Single permissible write path for credential-based sign-in.
 *
 * Normalises the supplied username (lowercase, trimmed) and verifies the
 * bcrypt password hash via the injected `Hasher`. On success the user is
 * logged in through the active guard and `true` is returned; on failure
 * the action returns `false` without distinguishing a missing account
 * from a wrong password, so callers can render a single generic error.
 */
final class LoginAction
{
    public function __construct(
        private readonly Hasher $hasher,
        private readonly AuthManager $auth,
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

        return true;
    }
}
