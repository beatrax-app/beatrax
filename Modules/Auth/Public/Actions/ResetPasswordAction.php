<?php

declare(strict_types=1);

namespace Modules\Auth\Public\Actions;

use Illuminate\Contracts\Hashing\Hasher;
use Illuminate\Database\DatabaseManager;
use Illuminate\Validation\ValidationException;
use Modules\Auth\Internal\Lock\AppLockProvisioner;
use Modules\Auth\Internal\Recovery\RecoveryCodeAuthenticator;
use Modules\Auth\Public\Contracts\PasswordPolicy;
use Modules\Core\Public\Support\Lang;

// Never drives the guard: neither logs the user in nor reads the session,
// so the reset flow ends with the user signing in fresh on /login.
final class ResetPasswordAction
{
    public function __construct(
        private readonly DatabaseManager $db,
        private readonly Hasher $hasher,
        private readonly RecoveryCodeAuthenticator $authenticator,
        private readonly AppLockProvisioner $provisioner,
    ) {}

    public function __invoke(string $usernameInput, string $codeInput, string $newPassword): void
    {
        if (strlen($newPassword) < PasswordPolicy::MINIMUM_LENGTH) {
            throw ValidationException::withMessages([
                'password' => Lang::get('auth::reset_password.error_min_length'),
            ]);
        }

        $user = $this->authenticator->verify($usernameInput, $codeInput);

        if ($user === null) {
            throw ValidationException::withMessages([
                'code' => Lang::get('auth::reset_password.error_wrong_code'),
            ]);
        }

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
        $this->db->connection()->table('sessions')
            ->where('user_id', $user->id)
            ->delete();
    }
}
