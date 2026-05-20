<?php

declare(strict_types=1);

namespace Modules\Auth\Public\Actions;

use Illuminate\Auth\AuthManager;
use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Contracts\Hashing\Hasher;
use Illuminate\Contracts\Session\Session;
use Modules\Auth\Public\Dto\ImpersonationResult;
use Modules\Core\Models\SystemAlert;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\CurrentUser;

/**
 * Password-verified profile switch — lets a developer act as another
 * user during debugging without a logout/login dance.
 *
 * The caller's own password is verified against the developer's user
 * row before the guard is swapped: a wrong password returns a
 * wrong-password result and never touches the guard. A non-developer
 * caller, a missing or self-targeting target, and a request made while
 * a switch is already active are all refused without a swap.
 *
 * On a successful switch the original user id and username are stashed
 * in the session under `auth.impersonating.original_user_id` and
 * `auth.impersonating.original_username` before `loginUsingId` swaps
 * the active guard; `EndImpersonationAction` restores them.
 *
 * Every start and every failed-password attempt writes a
 * `system_alerts` audit row — `auth.impersonation_started` (warning) on
 * a switch, `auth.impersonation_failed` (critical) on a wrong password.
 *
 * This action genuinely drives the guard and the session, so it sits
 * on the `noAuthFacadeOrHelper` allow-list.
 */
final class ImpersonateUserAction
{
    private const SESSION_ORIGINAL_USER_ID = 'auth.impersonating.original_user_id';

    private const SESSION_ORIGINAL_USERNAME = 'auth.impersonating.original_username';

    public function __construct(
        private readonly AuthManager $auth,
        private readonly Hasher $hasher,
        private readonly Session $session,
        private readonly CurrentUser $currentUser,
    ) {}

    public function __invoke(string $callerPasswordInput, int $targetUserId): ImpersonationResult
    {
        if (! $this->currentUser->isAuthenticated()) {
            return ImpersonationResult::notAllowed();
        }

        $developer = $this->currentUser->user();

        if (! $developer->is_developer) {
            return ImpersonationResult::notAllowed();
        }

        if ($this->session->get(self::SESSION_ORIGINAL_USER_ID) !== null) {
            // No nested impersonation — a switch is already active.
            return ImpersonationResult::notAllowed();
        }

        if (! $this->hasher->check($callerPasswordInput, $developer->password)) {
            $this->emitFailed($developer, $targetUserId);

            return ImpersonationResult::wrongPassword();
        }

        /** @var User|null $target */
        $target = User::query()->find($targetUserId);

        if (! $target instanceof User || $target->id === $developer->id) {
            return ImpersonationResult::invalidTarget();
        }

        $this->session->put(self::SESSION_ORIGINAL_USER_ID, $developer->id);
        $this->session->put(self::SESSION_ORIGINAL_USERNAME, $developer->username);

        /** @var StatefulGuard $guard */
        $guard = $this->auth->guard();
        $guard->loginUsingId($target->id);

        $this->emitStarted($developer, $target);

        return ImpersonationResult::success($target);
    }

    private function emitStarted(User $developer, User $target): void
    {
        SystemAlert::query()->create([
            'user_id' => $developer->id,
            'kind' => 'auth.impersonation_started',
            'severity' => 'warning',
            'message' => "Acting-as-user mode started for {$target->username} by {$developer->username}.",
        ]);
    }

    private function emitFailed(User $developer, int $targetUserId): void
    {
        /** @var User|null $target */
        $target = User::query()->find($targetUserId);
        $targetName = $target instanceof User ? $target->username : (string) $targetUserId;

        SystemAlert::query()->create([
            'user_id' => $developer->id,
            'kind' => 'auth.impersonation_failed',
            'severity' => 'critical',
            'message' => "Acting-as-user failed for {$targetName} — wrong password.",
        ]);
    }
}
