<?php

declare(strict_types=1);

namespace Modules\Auth\Public\Actions;

use Illuminate\Contracts\Hashing\Hasher;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\QueryException;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Modules\Auth\Internal\Recovery\RecoveryCodeGenerator;
use Modules\Auth\Models\UserRecoveryCode;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Creates a partner account on behalf of the device owner.
 *
 * The caller must be a developer (the owner); a non-developer caller
 * raises a 404 NotFoundHttpException — never a 403 — so a probing
 * non-developer cannot even confirm the partner-creation surface exists.
 * The route that reaches this action is itself developer-gated; the
 * caller check here is a defensive second layer.
 *
 * The new account is created inside one transaction together with ten
 * hashed recovery codes, so the partner always has a recovery path. It
 * is born with `is_developer = false` and
 * `force_password_change_at_next_login = true`: the owner sets an
 * initial password the partner replaces on first sign-in.
 */
final class AddUserAction
{
    private const MINIMUM_PASSWORD_LENGTH = 12;

    private const RECOVERY_CODE_COUNT = 10;

    private const DUPLICATE_USERNAME_MESSAGE = 'That username is already in use on this device. Try another one.';

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly Hasher $hasher,
        private readonly Clock $clock,
        private readonly RecoveryCodeGenerator $codeGenerator,
    ) {}

    public function __invoke(User $caller, string $usernameInput, string $password): User
    {
        if ($caller->is_developer !== true) {
            throw new NotFoundHttpException;
        }

        $username = strtolower(trim($usernameInput));

        if ($username === '') {
            throw new InvalidArgumentException('AddUserAction: username must not be empty.');
        }

        if (strlen($password) < self::MINIMUM_PASSWORD_LENGTH) {
            throw ValidationException::withMessages([
                'password' => 'Use at least 12 characters.',
            ]);
        }

        /** @var User $partner */
        $partner = $this->db->connection()->transaction(function () use ($username, $password): User {
            try {
                $partner = User::query()->create([
                    'username' => $username,
                    'password' => $this->hasher->make($password),
                    'is_developer' => false,
                    'force_password_change_at_next_login' => true,
                ]);
            } catch (QueryException $e) {
                if (self::isUniqueViolation($e)) {
                    throw ValidationException::withMessages([
                        'username' => self::DUPLICATE_USERNAME_MESSAGE,
                    ]);
                }
                throw $e;
            }

            $now = $this->clock->now();

            // Generate distinct codes so the partner is never code-less and
            // the unique code_hash index never rejects the insert.
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
                    'user_id' => $partner->id,
                    'code_hash' => $this->hasher->make($plainCode),
                    'used_at' => null,
                    'created_at' => $now,
                ]);
            }

            return $partner;
        });

        return $partner;
    }

    private static function isUniqueViolation(QueryException $e): bool
    {
        // SQLite reports UNIQUE violations with SQLSTATE 23000 and a
        // message containing "UNIQUE constraint failed". MySQL + Postgres
        // also surface 23000 for unique-constraint violations.
        if ((string) $e->getCode() === '23000') {
            return true;
        }

        $message = $e->getMessage();

        return str_contains($message, 'UNIQUE constraint failed')
            || str_contains($message, 'Duplicate entry')
            || str_contains($message, 'duplicate key value');
    }
}
