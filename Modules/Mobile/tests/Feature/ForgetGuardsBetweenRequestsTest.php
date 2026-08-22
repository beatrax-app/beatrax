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

it('leaves an identity the session cannot name, rather than signing it out', function (): void {
    $user = guardStaleUser();

    /** @var AuthManager $auth */
    $auth = app(AuthManager::class);

    // Bound straight onto the guard, the way actingAs() does it and a signed-in
    // request never does. Dropping this one is no refresh — there is no session
    // key behind it to re-resolve from, so it is a sign-out, and it answered
    // every authenticated request under the mobile root as a guest.
    $auth->guard()->setUser($user);

    (new ForgetGuardsBetweenRequests($auth))
        ->handle(new Request, static fn (): Response => new Response('ok'));

    expect($auth->guard()->user()?->getAuthIdentifier())->toBe($user->id);
});

// forgetGuards() rebuilds the session driver, and rebuilding registers a
// rebound callback the container never prunes. On a runtime that boots once and
// runs for the life of the app, that list only grows — and every later request
// walks all of it.
it('does not accumulate rebound callbacks across requests', function (): void {
    $user = guardStaleUser();

    /** @var AuthManager $auth */
    $auth = app(AuthManager::class);
    $auth->guard()->loginUsingId($user->id);

    $middleware = new ForgetGuardsBetweenRequests($auth);
    $count = static function (): int {
        $property = new ReflectionProperty(app(), 'reboundCallbacks');

        return count($property->getValue(app())['request'] ?? []);
    };

    // A real request reads the user after the middleware runs, which is what
    // re-resolves the guard — measuring without that misses the growth entirely.
    $cycle = static function () use ($middleware, $auth): void {
        $middleware->handle(new Request, static fn (): Response => new Response('ok'));
        $auth->guard()->user();
    };

    $cycle();
    $afterFirst = $count();

    for ($i = 0; $i < 5; $i++) {
        $cycle();
    }

    expect($count())->toBe($afterFirst);
});
