<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Laravel\Fortify\Actions\AttemptToAuthenticate;
use Laravel\Fortify\Actions\EnsureLoginIsNotThrottled;
use Laravel\Fortify\Actions\PrepareAuthenticatedSession;
use Laravel\Fortify\Features;
use Laravel\Fortify\Fortify;
use Modules\Auth\Internal\Fortify\FortifyServiceProvider;
use Modules\Core\Models\User;

uses(RefreshDatabase::class);

it('relocates the Fortify provider into the Auth module', function (): void {
    expect(class_exists(FortifyServiceProvider::class))->toBeTrue();
    // By string, so the assertion does not pull a removed class through the
    // autoloader.
    expect(class_exists('Modules\\Core\\Internal\\Providers\\FortifyServiceProvider', false))->toBeFalse();
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

    expect($pipeline)->toContain(AttemptToAuthenticate::class);
    expect($pipeline)->toContain(PrepareAuthenticatedSession::class);
    expect($pipeline)->not->toContain(EnsureLoginIsNotThrottled::class);
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
