<?php

declare(strict_types=1);

namespace Modules\Auth\Internal\Console;

use Illuminate\Console\Command;
use Illuminate\Contracts\Hashing\Hasher;
use Illuminate\Database\DatabaseManager;
use Modules\Auth\Internal\Lock\AppLockProvisioner;
use Modules\Auth\Internal\Services\SessionRevoker;
use Modules\Auth\Public\Contracts\PasswordPolicy;
use Modules\Auth\Public\Support\Username;
use Modules\Core\Models\User;

// No --password option, and a hard refusal when not interactive: a scripted
// run on an unattended machine must not be able to rewrite a password.
class ResetPasswordCommand extends Command
{
    /** @var string */
    protected $signature = 'beatrax:reset-password {username : Username of the account to reset}';

    /** @var string */
    protected $description = 'Interactively reset a user password. Also forces a password change at next sign-in, revokes every session, and invalidates the app-lock recovery wrap. Refuses non-interactive use.';

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly Hasher $hasher,
        private readonly SessionRevoker $sessions,
        private readonly AppLockProvisioner $provisioner,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        if (! $this->input->isInteractive()) {
            $this->error('beatrax:reset-password must be run interactively; there is no password flag.');

            return self::FAILURE;
        }

        return $this->resetInteractively();
    }

    private function resetInteractively(): int
    {
        // Larastan narrows the required `username` argument to string from the
        // typed signature, so no is_string() guard is needed.
        $username = Username::normalize($this->argument('username'));

        $user = User::query()->where('username', $username)->first();

        if (! $user instanceof User) {
            $this->error('No user with that username.');

            return self::FAILURE;
        }

        $password = $this->promptForPassword();
        if ($password === null) {
            return self::FAILURE;
        }

        $this->db->connection()->table('users')
            ->where('id', $user->id)
            ->update([
                'password' => $this->hasher->make($password),
                'force_password_change_at_next_login' => true,
            ]);

        // This road holds no old password to unwrap with, so the app-lock
        // recovery wrap cannot be carried over. Stamped rather than left to
        // fail on the day a forgotten PIN needs it — the lock screen offers
        // that road by name.
        $this->provisioner->markRecoveryWrapStale($user->id);

        // The escape hatch is reached when the account is already out of
        // reach, so whatever still holds it goes with the old password.
        $this->sessions->revokeAllFor($user->id);

        $this->info("Password updated for {$user->username}.");

        return self::SUCCESS;
    }

    private function promptForPassword(): ?string
    {
        $passwordInput = $this->secret('New password');
        $confirmInput = $this->secret('Confirm new password');
        $password = is_string($passwordInput) ? $passwordInput : '';
        $confirm = is_string($confirmInput) ? $confirmInput : '';

        if ($password !== $confirm) {
            $this->error('The two passwords do not match.');

            return null;
        }

        if (strlen($password) < PasswordPolicy::MINIMUM_LENGTH) {
            $this->error('Use at least 12 characters.');

            return null;
        }

        return $password;
    }
}
