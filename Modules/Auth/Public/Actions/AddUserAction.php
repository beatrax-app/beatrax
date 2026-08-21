<?php

declare(strict_types=1);

namespace Modules\Auth\Public\Actions;

use Illuminate\Contracts\Hashing\Hasher;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\QueryException;
use Illuminate\Validation\ValidationException;
use Modules\Auth\Internal\Recovery\RecoveryCodeMinter;
use Modules\Auth\Internal\Support\Username;
use Modules\Auth\Public\Contracts\PasswordPolicy;
use Modules\Core\Models\User;
use Modules\Core\Public\Support\Lang;
use Modules\Core\Public\Support\QueryFailure;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

// The route that reaches this action is itself developer-gated; the
// caller check here (404, never 403) is a defensive second layer.
final class AddUserAction
{
    public function __construct(
        private readonly DatabaseManager $db,
        private readonly Hasher $hasher,
        private readonly RecoveryCodeMinter $recoveryCodes,
    ) {}

    public function __invoke(User $caller, string $usernameInput, string $password): User
    {
        if ($caller->is_developer !== true) {
            throw new NotFoundHttpException;
        }

        $username = Username::normalize($usernameInput);

        if (! Username::isValid($username)) {
            throw ValidationException::withMessages([
                'username' => Lang::get('auth::add_user.error_username_invalid'),
            ]);
        }

        if (strlen($password) < PasswordPolicy::MINIMUM_LENGTH) {
            throw ValidationException::withMessages([
                'password' => Lang::get('auth::add_user.error_min_length'),
            ]);
        }

        /** @var User $partner */
        $partner = $this->db->connection()->transaction(function () use ($username, $password): User {
            $partner = $this->createPartner($username, $password);
            $this->recoveryCodes->issueFor($partner->id);

            return $partner;
        });

        return $partner;
    }

    private function createPartner(string $username, string $password): User
    {
        try {
            return User::query()->create([
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
    }
}
