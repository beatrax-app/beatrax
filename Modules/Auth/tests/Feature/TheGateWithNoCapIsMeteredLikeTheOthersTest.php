<?php

declare(strict_types=1);

use Illuminate\Cache\RateLimiter;
use Illuminate\Contracts\Routing\UrlGenerator;
use Livewire\Livewire;
use Modules\Auth\Internal\Http\Livewire\LoginPage;
use Modules\Auth\Internal\Services\GuestAttemptCap;
use Modules\Auth\Internal\Services\SignInThrottle;
use Modules\Auth\Public\Actions\LoginAction;
use Modules\Auth\Public\Actions\SignupAction;
use Modules\Auth\Public\Exceptions\SignInThrottled;
use Modules\Core\Models\User;
use Modules\Core\Public\Enums\Duration;
use Modules\Core\Public\Support\Lang;

// Recovery is capped at five a minute and a wrong app-lock code escalates a
// backoff to a hard cap. The account password had one bcrypt check and no
// counter at all, on a deployment the product documents as reachable off the
// machine.

const METERED_ACCOUNT = 'meteredowner';

const METERED_PASSWORD = 'a-long-password-12chars';

function meteredOwner(): User
{
    /** @var SignupAction $signup */
    $signup = app(SignupAction::class);

    /** @var User $user */
    $user = $signup(METERED_ACCOUNT, METERED_PASSWORD)['user'];

    return $user;
}

function meteredLogin(): LoginAction
{
    /** @var LoginAction $login */
    $login = app(LoginAction::class);

    return $login;
}

function spendTheMeter(string $username): void
{
    foreach (range(1, GuestAttemptCap::PER_MINUTE) as $ignored) {
        meteredLogin()($username, 'not-the-password', false);
    }
}

it('refuses the attempt after the meter is spent, rather than checking again', function (): void {
    meteredOwner();

    spendTheMeter(METERED_ACCOUNT);

    // The right password, refused: proof the meter is consulted ahead of the
    // credential and not merely counting alongside it.
    expect(fn (): bool => meteredLogin()(METERED_ACCOUNT, METERED_PASSWORD, false))
        ->toThrow(SignInThrottled::class);
});

// The positive control. Without it a meter set to zero would read the same way.
it('lets every attempt up to the cap through', function (): void {
    meteredOwner();

    foreach (range(1, GuestAttemptCap::PER_MINUTE) as $ignored) {
        expect(meteredLogin()(METERED_ACCOUNT, 'not-the-password', false))->toBeFalse();
    }
});

it('meters an unknown username exactly like a known one', function (): void {
    meteredOwner();

    spendTheMeter('nobody-has-this-name');

    expect(fn (): bool => meteredLogin()('nobody-has-this-name', 'anything', false))
        ->toThrow(SignInThrottled::class);
});

// The counter is keyed on the normalised name, so the spelling a reader types
// cannot buy them a fresh set of attempts against the same account.
it('counts two spellings of one username against one meter', function (): void {
    meteredOwner();

    spendTheMeter(METERED_ACCOUNT);

    expect(fn (): bool => meteredLogin()('  MeteredOwner  ', METERED_PASSWORD, false))
        ->toThrow(SignInThrottled::class);
});

it('releases the whole meter on a sign-in that succeeds', function (): void {
    meteredOwner();

    foreach (range(1, GuestAttemptCap::PER_MINUTE - 1) as $ignored) {
        meteredLogin()(METERED_ACCOUNT, 'not-the-password', false);
    }

    /** @var RateLimiter $limiter */
    $limiter = app(RateLimiter::class);

    // Read before the success, or a meter that never counted at all would
    // report the same zero afterwards and this would assert nothing.
    expect($limiter->attempts('auth.sign-in:'.METERED_ACCOUNT))
        ->toBe(GuestAttemptCap::PER_MINUTE - 1);

    expect(meteredLogin()(METERED_ACCOUNT, METERED_PASSWORD, false))->toBeTrue();

    expect($limiter->attempts('auth.sign-in:'.METERED_ACCOUNT))->toBe(0);
});

// Fortify serves a form posted before Livewire booted, and a limit written into
// only one of the two paths is a limit the other walks around.
it('meters the pipeline a pre-Livewire post takes as well', function (): void {
    meteredOwner();

    // Signup leaves the new account signed in, and POST /login is a guest
    // route: without this the request is redirected before the pipeline runs
    // and the test would pass on a guard that never asked anything.
    auth()->logout();

    spendTheMeter(METERED_ACCOUNT);

    $this->post('/login', [
        'username' => METERED_ACCOUNT,
        'password' => METERED_PASSWORD,
    ])->assertSessionHasErrors('username');

    expect(auth()->check())->toBeFalse(
        'A spent meter let the right password through on the pipeline the '.
        'Livewire form is not the only way to reach.',
    );
});

// The positive control for the pair above: the same post, the same route, one
// attempt short of the cap, authenticates.
it('lets that pipeline through while the meter has room', function (): void {
    meteredOwner();

    auth()->logout();

    $this->post('/login', [
        'username' => METERED_ACCOUNT,
        'password' => METERED_PASSWORD,
    ]);

    expect(auth()->check())->toBeTrue();
});

// The screen, not the action. A reader who is waiting and a reader who is wrong
// take different actions, and the catch that tells them apart is only reachable
// through the component.
it('tells the reader they are waiting rather than that they are wrong', function (): void {
    meteredOwner();

    spendTheMeter(METERED_ACCOUNT);

    $page = Livewire::test(LoginPage::class)
        ->set('username', METERED_ACCOUNT)
        ->set('password', METERED_PASSWORD)
        ->call('submit', app(LoginAction::class), app(UrlGenerator::class));

    $page->assertSet('password', '');

    $flash = $page->get('flashMessage');

    // The wait is whatever the limiter has left, counted in real seconds off
    // the cache's own timer, so pinning it to 60 fails the moment one elapses
    // between spending the meter and reading the flash -- which is why this
    // failed under coverage, where instrumentation makes that second likely,
    // while the uninstrumented shards passed. The template is asserted whole so
    // a reader still cannot be told they are wrong when they are waiting; only
    // the digits are free.
    $anyWait = '/^'.str_replace(
        '__WAIT__',
        '\d+s',
        preg_quote(Lang::get('auth::login.error_throttled', ['wait' => '__WAIT__']), '/'),
    ).'$/u';

    expect($flash)
        ->toBeString()
        ->not->toBe(Lang::get('auth::login.error_invalid'))
        ->toMatch($anyWait);
});

// The positive control for the case above: the same screen, a meter with room,
// a wrong password -- the reader is told they are wrong.
it('still says wrong where the meter has room', function (): void {
    meteredOwner();

    $page = Livewire::test(LoginPage::class)
        ->set('username', METERED_ACCOUNT)
        ->set('password', 'not-the-password')
        ->call('submit', app(LoginAction::class), app(UrlGenerator::class));

    expect($page->get('flashMessage'))->toBe(Lang::get('auth::login.error_invalid'));
});

it('carries the wait the limiter answers rather than a constant', function (): void {
    meteredOwner();

    spendTheMeter(METERED_ACCOUNT);

    /** @var SignInThrottle $throttle */
    $throttle = app(SignInThrottle::class);

    expect($throttle->availableIn(METERED_ACCOUNT))->toBeGreaterThan(0)
        ->and($throttle->availableIn(METERED_ACCOUNT))->toBeLessThanOrEqual(Duration::Minute->seconds());
});
