<?php

declare(strict_types=1);

namespace Modules\Auth\Public\Actions;

use Illuminate\Contracts\Hashing\Hasher;
use Illuminate\Database\DatabaseManager;
use Illuminate\Validation\ValidationException;
use Modules\Auth\Internal\Recovery\RecoveryCodeAuthenticator;

/**
 * Recovery-code-driven password reset.
 *
 * Given a typed username, one recovery code, and a new password, this
 * action verifies the username + code through RecoveryCodeAuthenticator
 * (which consumes the code on a match), then writes the new password
 * hash onto the user row and clears `force_password_change_at_next_login`
 * — the user just chose the password they want, so no forced change is
 * pending.
 *
 * A password shorter than twelve characters raises a ValidationException
 * keyed `password`; a username + code that do not match raises one keyed
 * `code` with a generic message that never reveals whether the username
 * existed.
 *
 * The action mutates a password but never drives the guard: it neither
 * logs the user in nor reads the session, so the reset flow ends with the
 * user signing in fresh on `/login`.
 */
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
