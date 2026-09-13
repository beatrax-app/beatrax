<?php

declare(strict_types=1);

use Illuminate\Cache\RateLimiter;
use Illuminate\Contracts\Routing\UrlGenerator;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Modules\Auth\Internal\Http\Livewire\LoginPage;
use Modules\Auth\Internal\Services\GuestAttemptCap;
use Modules\Auth\Models\UserRecoveryCode;
use Modules\Auth\Public\Actions\LoginAction;
use Modules\Auth\Public\Actions\ResetPasswordAction;
use Modules\Auth\Public\Actions\SignupAction;
use Modules\Auth\Public\Exceptions\SignInThrottled;
use Modules\Core\Models\SystemAlert;
use Modules\Core\Models\User;
use Modules\Core\Public\Support\Lang;

// The meter is keyed on the username a caller typed, so anyone who can reach
// the sign-in screen can spend somebody else's. Waiting was the only way out of
// that, which makes a stranger able to hold a household off its own ledger for
// as long as they keep typing. A recovery code is the credential that stranger
// does not have.

const ESCAPE_OWNER = 'lockedowner';

// Same length as the account above, so what a throttled refusal renders for a
// username nobody has can be compared against a real one with only the name
// itself taken out.
const ESCAPE_GHOST = 'lockedghost';

const ESCAPE_PASSWORD = 'a-long-password-12chars';

const ESCAPE_WRONG_CODE = 'AAAA-BBBB-CCCC-DDDD-EEEE';

/**
 * @return list<string>
 */
function escapeSignUpOwner(): array
{
    /** @var SignupAction $signup */
    $signup = app(SignupAction::class);

    /** @var list<string> $codes */
    $codes = $signup(ESCAPE_OWNER, ESCAPE_PASSWORD)['codesPlain'];

    auth()->logout();

    return $codes;
}

function escapeLoginAction(): LoginAction
{
    /** @var LoginAction $login */
    $login = app(LoginAction::class);

    return $login;
}

function escapeSpendTheMeter(string $username): void
{
    foreach (range(1, GuestAttemptCap::PER_MINUTE) as $ignored) {
        escapeLoginAction()($username, 'not-the-password', false);
    }
}

it('refuses the right password once the meter is spent, and a recovery code opens it', function (): void {
    $codes = escapeSignUpOwner();

    escapeSpendTheMeter(ESCAPE_OWNER);

    // Red without the escape: the meter is read ahead of the credential, so
    // the account's own password cannot clear the account's own meter.
    expect(fn (): bool => escapeLoginAction()(ESCAPE_OWNER, ESCAPE_PASSWORD, false))
        ->toThrow(SignInThrottled::class);

    /** @var RateLimiter $limiter */
    $limiter = app(RateLimiter::class);

    expect($limiter->attempts('auth.sign-in:'.ESCAPE_OWNER))->toBe(GuestAttemptCap::PER_MINUTE);

    expect(escapeLoginAction()(ESCAPE_OWNER, ESCAPE_PASSWORD, false, $codes[0]))->toBeTrue();

    // The meter is gone rather than merely stepped past: the sign-in that
    // followed the code cleared it, and nothing is left counting.
    expect($limiter->attempts('auth.sign-in:'.ESCAPE_OWNER))->toBe(0);
});

it('spends the code that opened the meter', function (): void {
    $codes = escapeSignUpOwner();

    escapeSpendTheMeter(ESCAPE_OWNER);
    escapeLoginAction()(ESCAPE_OWNER, ESCAPE_PASSWORD, false, $codes[0]);

    expect(UserRecoveryCode::query()->whereNotNull('used_at')->count())->toBe(1);

    auth()->logout();
    escapeSpendTheMeter(ESCAPE_OWNER);

    // Single-use here for the same reason it is single-use on the reset page:
    // what cleared the meter once is not a standing key to it.
    expect(fn (): bool => escapeLoginAction()(ESCAPE_OWNER, ESCAPE_PASSWORD, false, $codes[0]))
        ->toThrow(SignInThrottled::class);
});

it('escalates a wrong code rather than clearing the meter', function (): void {
    escapeSignUpOwner();

    escapeSpendTheMeter(ESCAPE_OWNER);

    /** @var RateLimiter $limiter */
    $limiter = app(RateLimiter::class);

    expect($limiter->attempts('auth.recovery-code:'.ESCAPE_OWNER))->toBe(0);

    try {
        escapeLoginAction()(ESCAPE_OWNER, ESCAPE_PASSWORD, false, ESCAPE_WRONG_CODE);
        $this->fail('A wrong recovery code opened the meter.');
    } catch (SignInThrottled $throttled) {
        expect($throttled->recoveryCodeRejected)->toBeTrue();
    }

    expect($limiter->attempts('auth.recovery-code:'.ESCAPE_OWNER))->toBe(1);
    expect($limiter->attempts('auth.sign-in:'.ESCAPE_OWNER))->toBe(GuestAttemptCap::PER_MINUTE);
});

it('caps the escape on a meter of its own', function (): void {
    escapeSignUpOwner();

    escapeSpendTheMeter(ESCAPE_OWNER);

    foreach (range(1, GuestAttemptCap::PER_MINUTE) as $ignored) {
        try {
            escapeLoginAction()(ESCAPE_OWNER, ESCAPE_PASSWORD, false, ESCAPE_WRONG_CODE);
        } catch (SignInThrottled $throttled) {
            expect($throttled->recoveryCodeRejected)->toBeTrue();
        }
    }

    // Past the cap the code is no longer read at all, so the refusal stops
    // saying anything about it -- the guessing surface is closed, not answered.
    try {
        escapeLoginAction()(ESCAPE_OWNER, ESCAPE_PASSWORD, false, ESCAPE_WRONG_CODE);
        $this->fail('The escape answered a sixth code inside one minute.');
    } catch (SignInThrottled $throttled) {
        expect($throttled->recoveryCodeRejected)->toBeFalse();
    }
});

it('shares that meter with the reset page, because both spend the same sheet', function (): void {
    escapeSignUpOwner();

    escapeSpendTheMeter(ESCAPE_OWNER);

    foreach (range(1, GuestAttemptCap::PER_MINUTE) as $ignored) {
        try {
            escapeLoginAction()(ESCAPE_OWNER, ESCAPE_PASSWORD, false, ESCAPE_WRONG_CODE);
        } catch (SignInThrottled) {
            // Counted, not caught for its message.
        }
    }

    /** @var ResetPasswordAction $reset */
    $reset = app(ResetPasswordAction::class);

    // A second surface over one sheet must not be a second allowance against
    // it: five guesses spread across two screens are five guesses.
    try {
        $reset(ESCAPE_OWNER, ESCAPE_WRONG_CODE, 'another-long-password');
        $this->fail('The reset page gave the same sheet a fresh set of attempts.');
    } catch (ValidationException $refused) {
        expect($refused->validator->errors()->first('code'))
            ->toMatch('/^'.str_replace(
                '__WAIT__',
                '\\d+s',
                preg_quote(Lang::get('auth::reset_password.error_throttled', ['wait' => '__WAIT__']), '/'),
            ).'$/u');
    }
});

it('writes an audit row for an escape attempt, and names no user for one nobody owns', function (): void {
    escapeSignUpOwner();

    escapeSpendTheMeter(ESCAPE_GHOST);

    try {
        escapeLoginAction()(ESCAPE_GHOST, ESCAPE_PASSWORD, false, ESCAPE_WRONG_CODE);
    } catch (SignInThrottled) {
        // The refusal is the subject of another case; this one reads the rows.
    }

    expect(SystemAlert::query()->where('kind', 'auth.recovery_code_failed')->count())->toBe(0);

    escapeSpendTheMeter(ESCAPE_OWNER);

    try {
        escapeLoginAction()(ESCAPE_OWNER, ESCAPE_PASSWORD, false, ESCAPE_WRONG_CODE);
    } catch (SignInThrottled) {
        // As above.
    }

    /** @var User $owner */
    $owner = User::query()->where('username', ESCAPE_OWNER)->firstOrFail();

    expect(SystemAlert::query()->where('kind', 'auth.recovery_code_failed')->where('user_id', $owner->id)->count())
        ->toBe(1);
});

// Everything the reader supplied taken back out: the name they typed, which is
// echoed at them, and the seconds the limiter had left when this render
// happened. Livewire's own component id and the checksum built over it are
// per-render and belong to neither username.
function escapeNormalizeRefusal(string $html, string $username): string
{
    $replaced = (string) preg_replace(
        [
            '/'.preg_quote($username, '/').'/',
            '/\b\d+s\b/',
            '/&quot;id&quot;:&quot;[^&]*&quot;/',
            '/&quot;checksum&quot;:&quot;[^&]*&quot;/',
            '/wire:id="[^"]*"/',
        ],
        ['USERNAME', 'WAIT', 'ID', 'CHECKSUM', 'wire:id="ID"'],
        $html,
    );

    return $replaced;
}

function escapeRefusalFor(string $username, string $password = ESCAPE_PASSWORD): string
{
    $page = Livewire::test(LoginPage::class)
        ->set('username', $username)
        ->set('password', $password)
        ->call('submit', app(LoginAction::class), app(UrlGenerator::class));

    return escapeNormalizeRefusal($page->html(), $username);
}

it('refuses a throttled known username in the bytes it refuses a throttled unknown one in', function (): void {
    escapeSignUpOwner();

    escapeSpendTheMeter(ESCAPE_OWNER);
    escapeSpendTheMeter(ESCAPE_GHOST);

    expect(escapeRefusalFor(ESCAPE_OWNER))->toBe(escapeRefusalFor(ESCAPE_GHOST));
});

// The control for the comparison above. Without it a normaliser that flattened
// the whole page would report the same equality and assert nothing.
it('still tells a metered screen from one with room after that normalising', function (): void {
    escapeSignUpOwner();

    // This render is itself the first attempt, so the loop spends what is left
    // rather than a whole cap on top of it.
    $withRoom = escapeRefusalFor(ESCAPE_OWNER, 'not-the-password');

    foreach (range(2, GuestAttemptCap::PER_MINUTE) as $ignored) {
        escapeLoginAction()(ESCAPE_OWNER, 'not-the-password', false);
    }

    expect(escapeRefusalFor(ESCAPE_OWNER))->not->toBe($withRoom);
});

it('offers the escape on the screen, and says what it costs', function (): void {
    escapeSignUpOwner();

    escapeSpendTheMeter(ESCAPE_OWNER);

    $page = Livewire::test(LoginPage::class)
        ->set('username', ESCAPE_OWNER)
        ->set('password', ESCAPE_PASSWORD)
        ->call('submit', app(LoginAction::class), app(UrlGenerator::class));

    $page->assertSet('recoveryOffered', true)
        ->assertSet('password', '')
        ->assertSee(Lang::get('auth::login.throttled_recovery'))
        ->assertSee(Lang::get('auth::reset_password.recovery_code'));
});

it('tells the reader the code was wrong rather than that they are still waiting', function (): void {
    escapeSignUpOwner();

    escapeSpendTheMeter(ESCAPE_OWNER);

    $page = Livewire::test(LoginPage::class)
        ->set('username', ESCAPE_OWNER)
        ->set('password', ESCAPE_PASSWORD)
        ->set('recoveryCode', ESCAPE_WRONG_CODE)
        ->call('submit', app(LoginAction::class), app(UrlGenerator::class));

    $page->assertSet('recoveryCode', '')
        ->assertSet('recoveryOffered', true);

    expect($page->get('flashMessage'))->toBe(Lang::get('auth::reset_password.error_wrong_code'));
});

it('signs the reader in on the screen when the code and the password are both right', function (): void {
    $codes = escapeSignUpOwner();

    escapeSpendTheMeter(ESCAPE_OWNER);

    Livewire::test(LoginPage::class)
        ->set('username', ESCAPE_OWNER)
        ->set('password', ESCAPE_PASSWORD)
        ->set('recoveryCode', $codes[0])
        ->call('submit', app(LoginAction::class), app(UrlGenerator::class))
        ->assertRedirect();

    expect(auth()->check())->toBeTrue();
});
