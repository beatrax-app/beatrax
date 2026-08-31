<?php

declare(strict_types=1);

namespace Modules\Auth\Public\Actions;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Hashing\Hasher;
use Illuminate\Database\QueryException;
use Illuminate\Validation\ValidationException;
use Modules\Auth\Internal\Services\AccountOwner;
use Modules\Auth\Public\Contracts\PasswordPolicy;
use Modules\Auth\Public\Support\Username;
use Modules\Core\Models\User;
use Modules\Core\Public\Events\UserInstalled;
use Modules\Core\Public\Support\Lang;
use Modules\Core\Public\Support\QueryFailure;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

// The route that reaches this action is developer-gated; the owner check here
// (404, never 403) is what actually holds, because developer mode is
// self-settable.
final readonly class AddUserAction
{
    public function __construct(
        private Hasher $hasher,
        private AccountOwner $owner,
        private Dispatcher $events,
    ) {}

    public function __invoke(User $caller, string $usernameInput, string $password): User
    {
        if (! $this->owner->isOwner($caller)) {
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

        // No recovery sheet here: codes issued now are credentials nobody
        // holds, since the owner never sees them and the partner is not
        // present. ChangePasswordPage mints it at the partner's forced first
        // password change instead.
        return $this->createPartner($username, $password);
    }

    // The same event signup and install fire, because the partner is a reader
    // in their own right: every listener on it writes per-user rows, and a
    // partner created without one had no categorization rules, no starting
    // wizard and no envelope genesis of their own.
    private function createPartner(string $username, string $password): User
    {
        try {
            $partner = User::query()->create([
                'username' => $username,
                'password' => $this->hasher->make($password),
                'is_developer' => false,
                'force_password_change_at_next_login' => true,
            ]);

            $this->events->dispatch(new UserInstalled($partner->id));

            return $partner;
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
