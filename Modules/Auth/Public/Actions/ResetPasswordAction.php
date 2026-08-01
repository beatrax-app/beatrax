<?php

declare(strict_types=1);

namespace Modules\Auth\Public\Actions;

use Illuminate\Contracts\Hashing\Hasher;
use Illuminate\Database\DatabaseManager;
use Illuminate\Validation\ValidationException;
use Modules\Auth\Internal\Recovery\RecoveryCodeAuthenticator;
use Modules\Core\Public\Support\Lang;

// Never drives the guard: neither logs the user in nor reads the session,
// so the reset flow ends with the user signing in fresh on /login.
final class ResetPasswordAction
{
    private const MINIMUM_PASSWORD_LENGTH = 12;

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly Hasher $hasher,
        private readonly RecoveryCodeAuthenticator $authenticator,
    ) {}

    public function __invoke(string $usernameInput, string $codeInput, string $newPassword): void
    {
        if (strlen($newPassword) < self::MINIMUM_PASSWORD_LENGTH) {
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
    }
}
