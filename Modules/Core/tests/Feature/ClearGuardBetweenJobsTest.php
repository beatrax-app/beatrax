<?php

declare(strict_types=1);

use Illuminate\Contracts\Queue\Job;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Auth;
use Modules\Core\Models\User;

uses(RefreshDatabase::class);

// `auth` is a singleton, so the worker's forgetScopedInstances() never drops a
// user a job bound and the next job in that process starts signed in as somebody
// else — the same guard UserScope reads to decide which rows a query sees.
// Clearing per job beats restoring per job, which a job can silently forget.

function guardTestUser(string $username): User
{
    return User::query()->create([
        'username' => $username,
        'password' => 'fixture',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

function fireJobProcessing(string $connection = 'database'): void
{
    // Our listener never reads the job, but the framework's own log-context
    // listener on this event does, so payload() has to answer.
    $job = Mockery::mock(Job::class);
    $job->shouldReceive('payload')->andReturn([]);

    event(new JobProcessing($connection, $job));
}

it('starts a queued job with nobody signed in, even when the previous job left a user bound', function (): void {
    $first = guardTestUser('guard-first');
    Auth::guard()->setUser($first);

    expect(Auth::guard()->user()?->getAuthIdentifier())->toBe($first->id);

    fireJobProcessing();

    expect(Auth::guard()->user())->toBeNull('a job must not inherit the previous job\'s user');
});

it('leaves the guard clear when no user was bound at all', function (): void {
    fireJobProcessing();

    expect(Auth::guard()->user())->toBeNull();
});

// The sync driver runs a job inside the caller's request and shares its
// authentication deliberately. Clearing there signs the caller out mid-request,
// which is how this listener first announced itself: the cash book wrote an
// entry, its dispatch ran inline, and the page lost its own user.
it('leaves the guard alone for a job running inline on the sync driver', function (): void {
    $user = guardTestUser('guard-inline');
    Auth::guard()->setUser($user);

    fireJobProcessing('sync');

    expect(Auth::guard()->user()?->getAuthIdentifier())->toBe($user->id);
});
