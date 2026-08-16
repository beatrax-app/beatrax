<?php

declare(strict_types=1);

namespace Modules\EmailScan\Internal\Jobs;

use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Modules\Core\Models\User;

// Binds the owning user onto the guard for the length of a queued scan, so
// the guard-scoped services a job reaches can resolve who it is acting for.
/**
 * @link ../../../../.docs/features/email-scan/architecture.md
 */
final readonly class JobUserContext
{
    public function __construct(
        private AuthFactory $auth,
    ) {}

    // The job knows whose inbox it reads; the services it reaches scope
    // through the guard, which a worker has nobody bound to — so the first
    // credential lookup threw and took the scan down. setUser(), never
    // login(): a worker has no session to persist into.
    public function bind(int $userId): void
    {
        $user = User::query()->find($userId);

        if ($user instanceof User) {
            $this->auth->guard()->setUser($user);
        }
    }
}
