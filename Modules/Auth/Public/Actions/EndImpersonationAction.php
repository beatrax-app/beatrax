<?php

declare(strict_types=1);

namespace Modules\Auth\Public\Actions;

use Illuminate\Auth\AuthManager;
use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Contracts\Session\Session;
use Modules\Auth\Public\Dto\ImpersonationResult;
use Modules\Core\Models\SystemAlert;
use Modules\Core\Models\User;

/**
 * Ends an active profile switch, restoring the developer to their own
 * account.
 *
 * Reads the original user id stashed by `ImpersonateUserAction`,
 * swaps the active guard back, and clears both impersonation session
 * keys. When no switch is active the action is a no-op and returns a
 * not-allowed result.
 *
 * A successful return-to-self writes an `auth.impersonation_ended`
 * (warning) `system_alerts` row.
 *
 * This action drives the guard and the session, so it sits on the
 * `noAuthFacadeOrHelper` allow-list.
 */
final class EndImpersonationAction
{
    private const SESSION_ORIGINAL_USER_ID = 'auth.impersonating.original_user_id';

    private const SESSION_ORIGINAL_USERNAME = 'auth.impersonating.original_username';

    public function __construct(
        private readonly AuthManager $auth,
        private readonly Session $session,
    ) {}

    public function __invoke(): ImpersonationResult
    {
        $originalId = $this->session->get(self::SESSION_ORIGINAL_USER_ID);

        if (! is_numeric($originalId)) {
            return ImpersonationResult::notAllowed();
        }

        /** @var User|null $original */
        $original = User::query()->find((int) $originalId);

        $this->session->forget(self::SESSION_ORIGINAL_USER_ID);
        $this->session->forget(self::SESSION_ORIGINAL_USERNAME);

        if (! $original instanceof User) {
            return ImpersonationResult::notAllowed();
        }

        /** @var StatefulGuard $guard */
        $guard = $this->auth->guard();
        $guard->loginUsingId($original->id);

        SystemAlert::query()->create([
            'user_id' => $original->id,
            'kind' => 'auth.impersonation_ended',
            'severity' => 'warning',
            'message' => "Acting-as-user mode ended; returned to {$original->username}.",
        ]);

        return ImpersonationResult::success($original);
    }
}
