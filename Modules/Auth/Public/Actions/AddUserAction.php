<?php

declare(strict_types=1);

namespace Modules\Auth\Public\Actions;

use Illuminate\Contracts\Hashing\Hasher;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\QueryException;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Modules\Auth\Internal\Recovery\RecoveryCodeGenerator;
use Modules\Auth\Internal\Support\Username;
use Modules\Auth\Models\UserRecoveryCode;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Support\Lang;
use Modules\Core\Public\Support\QueryFailure;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

// The route that reaches this action is itself developer-gated; the
// caller check here (404, never 403) is a defensive second layer.
final class AddUserAction
{
    private const MINIMUM_PASSWORD_LENGTH = 12;

    private const RECOVERY_CODE_COUNT = 10;

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

        $username = Username::normalize($usernameInput);

        if ($username === '') {
            throw new InvalidArgumentException('AddUserAction: username must not be empty.');
        }

        if (! Username::isValid($username)) {
            throw ValidationException::withMessages([
                'username' => Lang::get('auth::add_user.error_username_invalid'),
            ]);
        }

        if (strlen($password) < self::MINIMUM_PASSWORD_LENGTH) {
            throw ValidationException::withMessages([
                'password' => Lang::get('auth::add_user.error_min_length'),
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
                if (QueryFailure::isUniqueViolation($e)) {
                    throw ValidationException::withMessages([
                        'username' => Lang::get('auth::add_user.error_duplicate'),
                    ]);
                }
                throw $e;
            }

            $now = $this->clock->now();

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
}
