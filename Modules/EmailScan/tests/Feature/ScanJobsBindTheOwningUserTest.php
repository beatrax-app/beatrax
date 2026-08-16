<?php

declare(strict_types=1);

use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\EmailScan\Internal\Jobs\JobUserContext;

uses(RefreshDatabase::class);

/*
 * A queued scan has to carry its own user.
 *
 * The jobs know exactly whose inbox they are reading — they take the id off
 * the inboxes row — but the services they reach do not. OAuthSecretsRepository
 * scopes every query through CurrentUser, which reads the auth guard, and a
 * queue worker has nobody bound to it. So the very first credential lookup
 * threw NotAuthenticatedException and took the whole scan down: 292 of them in
 * one desktop log, with 96 IncrementalScanJob entries in queue.failed behind
 * them. Gmail scanning was not degraded, it was not running at all.
 */
function scanJobUser(): User
{
    return User::query()->create([
        'username' => 'scan-owner-'.bin2hex(random_bytes(4)),
        'password' => 'fixture',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

it('leaves a worker with no bound user before the job starts', function (): void {
    /** @var CurrentUser $currentUser */
    $currentUser = $this->app->make(CurrentUser::class);

    // The state a queue worker genuinely starts in — this is what made the
    // repository throw, so the test is only meaningful if it holds.
    expect($currentUser->isAuthenticated())->toBeFalse();
});

it('binds the owning user so guard-scoped services resolve', function (): void {
    $user = scanJobUser();

    /** @var JobUserContext $jobUser */
    $jobUser = $this->app->make(JobUserContext::class);
    /** @var CurrentUser $currentUser */
    $currentUser = $this->app->make(CurrentUser::class);

    $jobUser->bind((int) $user->id);

    expect($currentUser->isAuthenticated())->toBeTrue()
        ->and($currentUser->id())->toBe((int) $user->id);
});

// A deleted user between dispatch and pickup must not turn a skippable scan
// into a fatal: the job's own guards already handle a missing owner.
it('leaves the guard alone when the user no longer exists', function (): void {
    /** @var JobUserContext $jobUser */
    $jobUser = $this->app->make(JobUserContext::class);
    /** @var CurrentUser $currentUser */
    $currentUser = $this->app->make(CurrentUser::class);

    $jobUser->bind(999_999);

    expect($currentUser->isAuthenticated())->toBeFalse();
});

it('binds through the same guard the repository reads', function (): void {
    $user = scanJobUser();

    /** @var JobUserContext $jobUser */
    $jobUser = $this->app->make(JobUserContext::class);
    $jobUser->bind((int) $user->id);

    /** @var AuthFactory $auth */
    $auth = $this->app->make(AuthFactory::class);

    expect($auth->guard()->user()?->getAuthIdentifier())->toBe($user->id);
});
