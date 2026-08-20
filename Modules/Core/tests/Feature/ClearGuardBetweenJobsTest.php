<?php

declare(strict_types=1);

use Illuminate\Contracts\Queue\Job;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Auth;
use Modules\Core\Models\User;

uses(RefreshDatabase::class);

/*
 * No queued job inherits the user the last one was acting for.
 *
 * A job that acts for somebody binds them onto the guard. Nothing unbound
 * them: `auth` is registered as a SINGLETON, so the worker's
 * forgetScopedInstances() does not drop it, and forgetGuards() is called
 * nowhere in the queue. The binding therefore outlived the job and the next
 * one in that worker process started already signed in as somebody else —
 * and UserScope reads exactly this guard to decide which rows a query sees.
 *
 * Guarding it per-job was the obvious fix and the wrong one: a job that
 * forgets to restore looks identical to one that succeeds, and the damage
 * lands on a DIFFERENT job later. This clears the guard as each job starts,
 * so forgetting is not possible.
 */

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
