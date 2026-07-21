<?php

declare(strict_types=1);

namespace Modules\Auth\Public\Actions;

use Illuminate\Auth\AuthManager;
use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Hashing\Hasher;
use Illuminate\Contracts\Session\Session;
use Illuminate\Database\DatabaseManager;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Modules\Auth\Internal\Recovery\RecoveryCodeGenerator;
use Modules\Auth\Models\UserRecoveryCode;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Events\UserInstalled;

/**
 * @link ../../../../.docs/features/auth/architecture.md
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
        private readonly Dispatcher $events,
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

        /** @var array{user: User, codesPlain: non-empty-list<string>} $result */
        $result = $this->db->connection()->transaction(function () use ($username, $password): array {
            // Promote the connection to an immediate write lock before the
            // existence check. A no-op UPDATE matches zero rows but still
            // acquires the lock, so a concurrent signup blocks here rather
            // than reading a stale zero-count snapshot under SQLite WAL.
            $this->db->connection()->statement('UPDATE users SET id = id WHERE 0 = 1');

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

            // Generate distinct codes: a collision is astronomically rare,
            // but the unique code_hash index would reject a duplicate
            // insert outright, so regenerate until ten distinct values
            // exist.
            $codesPlain = [];
            while (count($codesPlain) < self::RECOVERY_CODE_COUNT) {
                $code = $this->codeGenerator->generate();
                if (in_array($code, $codesPlain, true)) {
                    continue;
                }
                $codesPlain[] = $code;
            }

            foreach ($codesPlain as $plainCode) {
                UserRecoveryCode::query()->create([
                    'user_id' => $user->id,
                    'code_hash' => $this->hasher->make($plainCode),
                    'used_at' => null,
                    'created_at' => $now,
                ]);
            }

            return ['user' => $user, 'codesPlain' => $codesPlain];
        });

        // Dispatched after commit (never for a rolled-back user) and before
        // auto-login, so the listener chain runs in the same unauthenticated
        // context as the beatrax:install console path.
        $this->events->dispatch(new UserInstalled($result['user']->id));

        /** @var StatefulGuard $guard */
        $guard = $this->auth->guard();
        $guard->login($result['user']);

        $this->session->put('auth.signup.recovery_codes_plain', $result['codesPlain']);

        return $result;
    }
}
