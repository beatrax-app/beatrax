<?php

declare(strict_types=1);

namespace Modules\Auth\Public\Actions;

use Illuminate\Contracts\Hashing\Hasher;
use Illuminate\Database\DatabaseManager;
use Illuminate\Validation\ValidationException;
use Modules\Auth\Internal\Lock\AppLockProvisioner;
use Modules\Auth\Internal\Recovery\RecoveryCodeAuthenticator;
use Modules\Auth\Internal\Services\RecoveryAttemptThrottle;
use Modules\Auth\Internal\Services\SessionRevoker;
use Modules\Auth\Public\Contracts\PasswordPolicy;
use Modules\Core\Public\Support\Lang;

// Never drives the guard: neither logs the user in nor reads the session,
// so the reset flow ends with the user signing in fresh on /login.
final readonly class ResetPasswordAction
{
    public function __construct(
        private DatabaseManager $db,
        private Hasher $hasher,
        private RecoveryCodeAuthenticator $authenticator,
        private AppLockProvisioner $provisioner,
        private SessionRevoker $sessions,
        private RecoveryAttemptThrottle $attempts,
    ) {}

    public function __invoke(string $usernameInput, string $codeInput, string $newPassword): void
    {
        if (strlen($newPassword) < PasswordPolicy::MINIMUM_LENGTH) {
            throw ValidationException::withMessages([
                'password' => Lang::get('auth::reset_password.error_min_length'),
            ]);
        }

        // One attempt here costs ten bcrypt-12 hashes plus a write into the
        // household's alert banner. The hash count is the enumeration defence
        // and stays; the cap bounds how often it can be spent — and it is the
        // sheet's cap, shared with the sign-in escape that spends the same ten.
        if ($this->attempts->isExhausted($usernameInput)) {
            throw ValidationException::withMessages([
                'code' => Lang::get('auth::reset_password.error_throttled', [
                    'wait' => $this->attempts->availableIn($usernameInput).'s',
                ]),
            ]);
        }

        $this->attempts->recordAttempt($usernameInput);

        $user = $this->authenticator->verify($usernameInput, $codeInput);

        if ($user === null) {
            throw ValidationException::withMessages([
                'code' => Lang::get('auth::reset_password.error_wrong_code'),
            ]);
        }

        $this->attempts->clear($usernameInput);

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
