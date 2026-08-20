<?php

declare(strict_types=1);

namespace Modules\Auth\Public\Actions;

use Illuminate\Auth\AuthManager;
use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Contracts\Hashing\Hasher;
use Modules\Auth\Internal\Lock\AppLockProvisioner;
use Modules\Core\Models\User;
use Modules\Core\Public\Services\SessionFactory;

final class LoginAction
{
    public function __construct(
        private readonly Hasher $hasher,
        private readonly AuthManager $auth,
        private readonly AppLockProvisioner $provisioner,
        private readonly SessionFactory $session,
    ) {}

    public function __invoke(string $usernameInput, string $password, bool $rememberMe): bool
    {
        $normalized = strtolower(trim($usernameInput));

        /** @var User|null $user */
        $user = User::query()->where('username', $normalized)->first();

        if (! $user instanceof User) {
            // Burn one hash on the account-not-found path so a missing username
            // takes the same time as a wrong password — no enumeration oracle.
            // make() runs the same bcrypt work as the check() below and the
            // result is discarded; nothing is hardcoded.
            $this->hasher->make($password);

            return false;
        }

        if (! $this->hasher->check($password, $user->password)) {
            return false;
        }

        /** @var StatefulGuard $guard */
        $guard = $this->auth->guard();
        $guard->login($user, $rememberMe);

        // Establishes coherent lock state while the plaintext password is
        // still in hand (no-op when the lock is not enabled).
        $this->provisioner->primeSessionAfterLogin($user->id, $password, ($this->session)());

        return true;
    }
}
