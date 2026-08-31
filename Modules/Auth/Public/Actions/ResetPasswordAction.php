<?php

declare(strict_types=1);

namespace Modules\Auth\Public\Actions;

use Illuminate\Cache\RateLimiter;
use Illuminate\Contracts\Hashing\Hasher;
use Illuminate\Database\DatabaseManager;
use Illuminate\Validation\ValidationException;
use Modules\Auth\Internal\Lock\AppLockProvisioner;
use Modules\Auth\Internal\Recovery\RecoveryCodeAuthenticator;
use Modules\Auth\Internal\Services\SessionRevoker;
use Modules\Auth\Public\Contracts\PasswordPolicy;
use Modules\Auth\Public\Support\Username;
use Modules\Core\Public\Enums\Duration;
use Modules\Core\Public\Support\Lang;

// Never drives the guard: neither logs the user in nor reads the session,
// so the reset flow ends with the user signing in fresh on /login.
final readonly class ResetPasswordAction
{
    // This route is a guest route, and one attempt costs ten bcrypt-12 hashes
    // plus an unbounded write into the household's alert banner. The hash
    // count is the enumeration defence and stays; this bounds what it costs.
    private const int MAX_ATTEMPTS = 5;

    private static function decaySeconds(): int
    {
        return Duration::Minute->seconds();
    }

    public function __construct(
        private DatabaseManager $db,
        private Hasher $hasher,
        private RecoveryCodeAuthenticator $authenticator,
        private AppLockProvisioner $provisioner,
        private SessionRevoker $sessions,
        private RateLimiter $limiter,
    ) {}

    public function __invoke(string $usernameInput, string $codeInput, string $newPassword): void
    {
        if (strlen($newPassword) < PasswordPolicy::MINIMUM_LENGTH) {
            throw ValidationException::withMessages([
                'password' => Lang::get('auth::reset_password.error_min_length'),
            ]);
        }

        // Keyed on what the caller typed, so an unknown username is metered
        // exactly like a known one and the limiter answers nothing about which.
        $throttleKey = 'auth.reset-password:'.Username::normalize($usernameInput);

        if ($this->limiter->tooManyAttempts($throttleKey, self::MAX_ATTEMPTS)) {
            throw ValidationException::withMessages([
                'code' => Lang::get('auth::reset_password.error_throttled', [
                    'wait' => $this->limiter->availableIn($throttleKey).'s',
                ]),
            ]);
        }

        $this->limiter->hit($throttleKey, self::decaySeconds());

        $user = $this->authenticator->verify($usernameInput, $codeInput);

        if ($user === null) {
            throw ValidationException::withMessages([
                'code' => Lang::get('auth::reset_password.error_wrong_code'),
            ]);
        }

        $this->limiter->clear($throttleKey);

        $this->db->connection()->table('users')
            ->where('id', $user->id)
            ->update([
                'password' => $this->hasher->make($newPassword),
                'force_password_change_at_next_login' => false,
            ]);

        // A recovery code proves the account, never the old password, so the
        // app-lock recovery wrap cannot be carried over. Stamped rather than
        // left to fail on the day it is needed.
        $this->provisioner->markRecoveryWrapStale($user->id);

        // The account may have been out of the owner's hands, so every session
        // goes; the caller is a guest with none to preserve.
        $this->sessions->revokeAllFor($user->id);
    }
}
