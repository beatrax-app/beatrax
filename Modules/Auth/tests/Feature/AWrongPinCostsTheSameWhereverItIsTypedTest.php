<?php

declare(strict_types=1);

// The lock screen meters the PIN; three panels in Settings used to check the
// same secret with nothing counting. A correct guess at any of them took the
// lock down, set a PIN the guesser chose, or simply answered "yes" — which is
// enough on its own, because the answer can then be carried to the lock screen
// the meter does guard.

use Carbon\CarbonImmutable;
use Illuminate\Auth\AuthManager;
use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Modules\Auth\Internal\Http\Livewire\LockScreen;
use Modules\Auth\Internal\Lock\AppLockProvisioner;
use Modules\Auth\Internal\Lock\LockStateManager;
use Modules\Auth\Internal\Lock\PinVerificationService;
use Modules\Auth\Public\Http\Livewire\AppLockSettingsSection;
use Modules\Core\Models\SystemAlert;
use Modules\Core\Models\User;

function meteredPinUser(string $username): User
{
    $user = User::query()->create([
        'username' => $username,
        'password' => bcrypt('settings-pass'),
        'period_start_day' => 1,
    ]);

    test()->actingAs($user);

    /** @var AppLockProvisioner $provisioner */
    $provisioner = app(AppLockProvisioner::class);
    $provisioner->enable((int) $user->id, '123456', 'settings-pass');

    return $user;
}

function meteredPinConfig(int $userId, string $column): mixed
{
    return DB::connection()->table('user_app_lock_configs')
        ->where('user_id', $userId)
        ->value($column);
}

function meteredPinFailures(int $userId): int
{
    return (int) meteredPinConfig($userId, 'failed_attempts');
}

// Past the longest window the escalation reaches, so each guess is counted
// rather than turned away by the backoff the one before it armed. Waiting is
// what the backoff costs a guesser; it is not what these are measuring.
function meteredPinGuess(callable $panel): void
{
    test()->travel(301)->seconds();

    $panel();
}

function meteredPinDisablePanel(): void
{
    Livewire::test(AppLockSettingsSection::class)->call('disable', '999999');
}

function meteredPinChangePanel(): void
{
    Livewire::test(AppLockSettingsSection::class)->call('changePin', '999999', '654321', '654321');
}

function meteredPinDeenrollPanel(): void
{
    Livewire::test(AppLockSettingsSection::class)->call('deenroll', '999999');
}

function meteredPinLockScreen(): void
{
    Livewire::test(LockScreen::class)->call('submit', '999999');
}

it('spends a metered attempt for every wrong PIN the three settings panels take', function (): void {
    $user = meteredPinUser('metered-each-panel');

    $panels = ['meteredPinDisablePanel', 'meteredPinChangePanel', 'meteredPinDeenrollPanel'];

    foreach ($panels as $index => $panel) {
        meteredPinGuess($panel);

        expect(meteredPinFailures((int) $user->id))->toBe(
            $index + 1,
            $panel.'() checked the PIN without the meter counting it.',
        );
    }
});

// One counter, keyed on the account rather than on the surface: a guesser who
// spreads ten attempts over four screens has still had ten.
it('reaches the hard cap on guesses spread across the panels and the lock screen', function (): void {
    $user = meteredPinUser('metered-shared-counter');

    $panels = [
        'meteredPinDisablePanel',
        'meteredPinChangePanel',
        'meteredPinDeenrollPanel',
        'meteredPinLockScreen',
    ];

    for ($guess = 0; $guess < PinVerificationService::HARD_CAP; $guess++) {
        meteredPinGuess($panels[$guess % count($panels)]);
    }

    expect(meteredPinFailures((int) $user->id))->toBe(PinVerificationService::HARD_CAP);

    $alert = SystemAlert::query()
        ->where('user_id', $user->id)
        ->where('kind', 'auth.lock.hard_cap_reached')
        ->first();

    expect($alert)->not->toBeNull('The cap raises an alert, whichever screen took the last guess.')
        ->and($alert->severity)->toBe('critical');

    expect(app(AuthManager::class)->guard()->check())
        ->toBeFalse('The cap signs the session out — that is what ends a borrowed one.');
});

// The escalation is the half of the rule that costs a guesser time rather than
// the account. Reaching it in Settings means the panel refuses a PIN it has
// not looked at, so the copy has to say so rather than say "incorrect".
it('escalates a backoff in Settings that refuses even the correct PIN', function (): void {
    $user = meteredPinUser('metered-backoff');
    $originalHash = meteredPinConfig((int) $user->id, 'pin_hash');

    for ($guess = 0; $guess < 5; $guess++) {
        meteredPinGuess('meteredPinChangePanel');
    }

    /** @var PinVerificationService $verifier */
    $verifier = app(PinVerificationService::class);

    expect($verifier->lockedUntil((int) $user->id))->not->toBeNull('Five wrong PINs arm the backoff window.');

    Livewire::test(AppLockSettingsSection::class)
        ->call('changePin', '123456', '654321', '654321')
        ->assertSee('Too many attempts')
        ->assertDontSee('Incorrect PIN');

    expect(meteredPinConfig((int) $user->id, 'pin_hash'))
        ->toBe($originalHash, 'A PIN change inside the backoff window is a change the window was there to refuse.');
});

// The same thing a successful sign-in does to the sign-in limiter: proving the
// credential is what the meter was counting the absence of.
it('clears the meter when a settings panel takes the correct PIN', function (): void {
    $user = meteredPinUser('metered-cleared-on-success');

    meteredPinGuess('meteredPinDisablePanel');
    meteredPinGuess('meteredPinDeenrollPanel');

    expect(meteredPinFailures((int) $user->id))->toBe(2);

    meteredPinGuess(function (): void {
        Livewire::test(AppLockSettingsSection::class)->call('deenroll', '123456');
    });

    expect(meteredPinFailures((int) $user->id))->toBe(0)
        ->and(meteredPinConfig((int) $user->id, 'locked_until'))->toBeNull();
});

// The one unlock the session actually needs: a lock screen that still opens to
// the PIN after all of this proves the panels above refused rather than that
// the fixture had drifted off the code they were typing.
it('still unlocks the lock screen with the PIN the panels refused', function (): void {
    $user = meteredPinUser('metered-still-unlocks');

    meteredPinGuess('meteredPinDisablePanel');

    /** @var Session $session */
    $session = app(Session::class);
    app(LockStateManager::class)->lock($session);

    test()->travel(301)->seconds();

    Livewire::test(LockScreen::class)
        ->call('submit', '123456')
        ->assertRedirect();

    expect(app(LockStateManager::class)->isLocked($session))->toBeFalse();
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});
