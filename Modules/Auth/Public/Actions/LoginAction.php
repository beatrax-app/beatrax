<?php

declare(strict_types=1);

namespace Modules\Auth\Public\Actions;

use Illuminate\Auth\AuthManager;
use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Contracts\Hashing\Hasher;
use Modules\Auth\Internal\Lock\AppLockProvisioner;
use Modules\Auth\Internal\Services\SignInThrottle;
use Modules\Auth\Public\Exceptions\SignInThrottled;
use Modules\Auth\Public\Support\Username;
use Modules\Core\Models\User;
use Modules\Core\Public\Services\SessionFactory;

final readonly class LoginAction
{
    public function __construct(
        private Hasher $hasher,
        private AuthManager $auth,
        private AppLockProvisioner $provisioner,
        private SessionFactory $session,
        private SignInThrottle $throttle,
    ) {}

    /**
     * @throws SignInThrottled where the meter for this username is spent
     */
    public function __invoke(string $usernameInput, string $password, bool $rememberMe): bool
    {
        // Before the lookup and before the hash: an exhausted meter must cost
        // the caller nothing to discover, or the throttle becomes the timing
        // channel the equalised hash below exists to close.
        if ($this->throttle->isExhausted($usernameInput)) {
            throw new SignInThrottled($this->throttle->availableIn($usernameInput));
        }

        $this->throttle->recordAttempt($usernameInput);

        $normalized = Username::normalize($usernameInput);

        /** @var User|null $user */
        $user = User::query()->where('username', $normalized)->first();

        if (! $user instanceof User) {
            // Burns one hash so a missing username costs the same as a wrong
            // password. make() does the same bcrypt work as check() below.
            $this->hasher->make($password);

            return false;
        }

        if (! $this->hasher->check($password, $user->password)) {
            return false;
        }

        $this->throttle->clear($usernameInput);

        /** @var StatefulGuard $guard */
        $guard = $this->auth->guard();
        $guard->login($user, $rememberMe);

        $this->provisioner->primeSessionAfterLogin($user->id, $password, ($this->session)());

        return true;
    }
}
