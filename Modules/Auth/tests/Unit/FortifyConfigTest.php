<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Laravel\Fortify\Features;
use Laravel\Fortify\Fortify;
use Modules\Core\Models\User;

/*
 * Locks the Fortify wiring for the username-based, localhost-only
 * authentication surface: the authenticator closure resolves a user by
 * `username`, the throttle middleware is absent from the pipeline, and
 * every email-related Fortify feature stays disabled.
 */

it('relocates the Fortify provider into the Auth module', function (): void {
    expect(class_exists(\Modules\Auth\Internal\Fortify\FortifyServiceProvider::class))->toBeTrue();
    expect(class_exists(\Modules\Core\Internal\Providers\FortifyServiceProvider::class, false))->toBeFalse();
});

it('registers an authenticator closure that resolves a user by username', function (): void {
    $callback = Fortify::$authenticateUsingCallback;

    expect($callback)->not->toBeNull();

    $user = User::query()->create([
        'username' => 'closure-user',
        'password' => 'a-very-long-password',
        'period_start_day' => 1,
    ]);

    $request = Request::create('/login', 'POST', [
        'username' => 'closure-user',
        'password' => 'a-very-long-password',
    ]);

    $resolved = $callback($request);

    expect($resolved)->toBeInstanceOf(User::class);
    expect($resolved->id)->toBe($user->id);
});

it('returns null from the authenticator closure on a wrong password', function (): void {
    $callback = Fortify::$authenticateUsingCallback;

    User::query()->create([
        'username' => 'closure-wrong-pw',
        'password' => 'the-real-password',
        'period_start_day' => 1,
    ]);

    $request = Request::create('/login', 'POST', [
        'username' => 'closure-wrong-pw',
        'password' => 'not-the-password',
    ]);

    expect($callback($request))->toBeNull();
});

it('omits the throttle middleware from the authentication pipeline', function (): void {
    $through = Fortify::$authenticateThroughCallback;

    expect($through)->not->toBeNull();

    $pipeline = $through(Request::create('/login', 'POST'));

    expect($pipeline)->toContain(\Laravel\Fortify\Actions\AttemptToAuthenticate::class);
    expect($pipeline)->toContain(\Laravel\Fortify\Actions\PrepareAuthenticatedSession::class);
    expect($pipeline)->not->toContain(\Laravel\Fortify\Actions\EnsureLoginIsNotThrottled::class);
});

it('disables every email-related Fortify feature', function (): void {
    /** @var array<int, string> $features */
    $features = config('fortify.features');

    expect($features)->not->toContain(Features::resetPasswords());
    expect($features)->not->toContain(Features::emailVerification());
    expect($features)->not->toContain(Features::registration());
    expect($features)->not->toContain(Features::twoFactorAuthentication());
});

it('uses the database session driver', function (): void {
    expect(config('session.driver'))->toBe('database');
});
