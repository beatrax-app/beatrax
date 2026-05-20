<?php

declare(strict_types=1);

namespace Modules\Auth\Public\Actions;

use Illuminate\Auth\AuthManager;
use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Contracts\Hashing\Hasher;
use Illuminate\Contracts\Session\Session;
use Illuminate\Database\DatabaseManager;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Modules\Auth\Internal\Recovery\RecoveryCodeGenerator;
use Modules\Auth\Models\UserRecoveryCode;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;

/**
 * Single permissible write path for the first-user signup ceremony.
 *
 * Creates the first account on the device together with ten hashed
 * recovery codes inside one database transaction. The transaction body
 * re-checks that no user exists before inserting any row, so two
 * concurrent signups cannot both succeed — the second observes the first
 * user and aborts. The first account becomes the owner: its
 * `is_developer` flag is set true.
 *
 * On success the new user is logged in through the active guard and the
 * ten plaintext codes are stashed in the session under
 * `auth.signup.recovery_codes_plain` so the inline display page can show
 * them exactly once. The plaintext is returned alongside the user; it is
 * never persisted in clear form.
 */
final class SignupAction
{
    private const MINIMUM_PASSWORD_LENGTH = 12;

    private const RECOVERY_CODE_COUNT = 10;

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly Hasher $hasher,
        private readonly AuthManager $auth,
        private readonly RecoveryCodeGenerator $codeGenerator,
        private readonly Clock $clock,
        private readonly Session $session,
    ) {}

    /**
     * @return array{user: User, codesPlain: list<string>}
     */
    public function __invoke(string $usernameInput, string $password): array
    {
        $username = strtolower(trim($usernameInput));

        if ($username === '') {
            throw new InvalidArgumentException('SignupAction: username must not be empty.');
        }

        if (strlen($password) < self::MINIMUM_PASSWORD_LENGTH) {
            throw ValidationException::withMessages([
                'password' => 'Use at least 12 characters.',
            ]);
        }

        /** @var array{user: User, codesPlain: list<string>} $result */
        $result = $this->db->connection()->transaction(function () use ($username, $password): array {
            // Re-check inside the transaction: the count outside it could
            // read a stale value before a concurrent signup commits.
            if ($this->db->connection()->table('users')->count() > 0) {
                throw ValidationException::withMessages([
                    'signup' => 'Signup is closed on this device.',
                ]);
            }

            $user = User::query()->create([
                'username' => $username,
                'password' => $this->hasher->make($password),
                'is_developer' => true,
                'force_password_change_at_next_login' => false,
            ]);

            $now = $this->clock->now();

            $codesPlain = [];
            for ($i = 0; $i < self::RECOVERY_CODE_COUNT; $i++) {
                $code = $this->codeGenerator->generate();
                $codesPlain[] = $code;

                UserRecoveryCode::query()->create([
                    'user_id' => $user->id,
                    'code_hash' => $this->hasher->make($code),
                    'used_at' => null,
                    'created_at' => $now,
                ]);
            }

            return ['user' => $user, 'codesPlain' => $codesPlain];
        });

        /** @var StatefulGuard $guard */
        $guard = $this->auth->guard();
        $guard->login($result['user']);

        $this->session->put('auth.signup.recovery_codes_plain', $result['codesPlain']);

        return $result;
    }
}
