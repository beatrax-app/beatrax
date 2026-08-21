<?php

declare(strict_types=1);

use Illuminate\Auth\AuthManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Modules\Core\Models\User;
use Modules\Mobile\Internal\Http\Middleware\ForgetGuardsBetweenRequests;

uses(RefreshDatabase::class);

// The mobile runtime boots once per process, so the `auth` singleton keeps the
// User model it resolved at sign-in for the life of the app. Every preference
// lives on that model, which is why saving one wrote the new value and then went
// on reading the old: the language switched, the next screen switched back, and
// only a force-quit made it stick.

function guardStaleUser(): User
{
    return User::query()->create([
        'username' => 'stale-guard-user',
        'password' => bcrypt('whatever-password'),
        'period_start_day' => 1,
        'locale' => 'en',
    ]);
}

it('still reads the value the previous request resolved, until the guard is dropped', function (): void {
    $user = guardStaleUser();
    test()->actingAs($user);

    /** @var AuthManager $auth */
    $auth = app(AuthManager::class);

    // Resolve once, the way a request does.
    expect($auth->guard()->user()?->locale)->toBe('en');

    // Saved by a later request — the write itself was never the problem.
    User::query()->whereKey($user->id)->update(['locale' => 'nl']);

    // Proof the defect is real and not an artefact of the fix: the guard still
    // hands back the instance it memoised, so the new value is invisible.
    expect($auth->guard()->user()?->locale)->toBe('en');
});

it('reads the saved value once the stale guard is dropped', function (): void {
    $user = guardStaleUser();

    /** @var AuthManager $auth */
    $auth = app(AuthManager::class);

    // Signed in through the SESSION, the way a real request is, so that
    // dropping the guard leaves something to rebuild from — actingAs() binds
    // the instance directly and there would be nothing behind it.
    $auth->guard()->loginUsingId($user->id);

    expect($auth->guard()->user()?->locale)->toBe('en');

    User::query()->whereKey($user->id)->update(['locale' => 'nl']);

    $response = (new ForgetGuardsBetweenRequests($auth))
        ->handle(new Request, static fn (): Response => new Response('ok'));

    expect($response->getStatusCode())->toBe(200);

    // Re-resolved from the session, so it is the row as it stands now.
    expect($auth->guard()->user()?->locale)->toBe('nl');
});
