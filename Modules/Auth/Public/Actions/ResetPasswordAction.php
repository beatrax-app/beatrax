<?php

declare(strict_types=1);

namespace Modules\Auth\Public\Actions;

use Illuminate\Contracts\Hashing\Hasher;
use Illuminate\Database\DatabaseManager;
use Illuminate\Validation\ValidationException;
use Modules\Auth\Internal\Recovery\RecoveryCodeAuthenticator;

// Never drives the guard: neither logs the user in nor reads the session,
// so the reset flow ends with the user signing in fresh on /login.
final class ResetPasswordAction
{
    private const MINIMUM_PASSWORD_LENGTH = 12;

    private const WRONG_CODE_MESSAGE = 'That username and recovery code do not match. Check the code carefully — uppercase, no zero, no oh, no one, no L.';

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly Hasher $hasher,
        private readonly RecoveryCodeAuthenticator $authenticator,
    ) {}

    public function __invoke(string $usernameInput, string $codeInput, string $newPassword): void
    {
        if (strlen($newPassword) < self::MINIMUM_PASSWORD_LENGTH) {
            throw ValidationException::withMessages([
                'password' => 'Use at least 12 characters.',
            ]);
        }

        $user = $this->authenticator->verify($usernameInput, $codeInput);

        if ($user === null) {
            throw ValidationException::withMessages([
                'code' => self::WRONG_CODE_MESSAGE,
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
