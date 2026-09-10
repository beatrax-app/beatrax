<?php

declare(strict_types=1);

use Livewire\Livewire;
use Modules\Auth\Internal\Http\Livewire\LockScreen;
use Modules\Auth\Internal\Lock\AppLockProvisioner;
use Modules\Auth\Internal\Lock\LockStateManager;
use Modules\Auth\Public\Contracts\ColdStartVault;
use Modules\Auth\Public\Services\MobileLockGateway;
use Modules\Core\Models\User;
use Modules\Core\Public\Support\Lang;

// The operating system is the only thing that knows whether the durable wrap it
// holds can still be opened, and it says so on the read rather than on the
// question before it. The screen used to answer every refusal with "Could not
// unlock. Enter your PIN instead." and keep the control up, which describes a
// prompt the reader declined and not an enrolment that has gone.

function unreadableEntryUser(string $username): User
{
    $user = User::query()->create([
        'username' => $username,
        'password' => bcrypt('account-password'),
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);

    test()->actingAs($user);

    app(AppLockProvisioner::class)->enable((int) $user->id, '123456', 'account-password');
    app(MobileLockGateway::class)->markColdStartEnrolled((int) $user->id, true);

    test()->session([LockStateManager::SESSION_KEY => true]);

    return $user;
}

/**
 * @param  bool  $keepsEntry  Whether the entry survives the refusal: a prompt the
 *                            reader declined leaves it, an entry this machine can
 *                            no longer open is dropped on the way out.
 */
function unreadableEntryVault(bool $keepsEntry): ColdStartVault
{
    $vault = new class($keepsEntry) implements ColdStartVault
    {
        public bool $enrolled = true;

        public function __construct(private readonly bool $keepsEntry) {}

        public function isAvailable(): bool
        {
            return true;
        }

        public function isEnrolled(int $userId): bool
        {
            return $this->enrolled;
        }

        public function enroll(int $userId, string $dataKey): bool
        {
            $this->enrolled = true;

            return true;
        }

        public function recover(int $userId, string $reason): ?string
        {
            $this->enrolled = $this->keepsEntry;

            return null;
        }

        public function forget(int $userId): bool
        {
            $this->enrolled = false;

            return true;
        }
    };

    app()->instance(ColdStartVault::class, $vault);

    return $vault;
}

it('offers the native unlock while the entry and the flag both stand', function (): void {
    unreadableEntryUser('entry-offers');
    unreadableEntryVault(keepsEntry: true);

    Livewire::test(LockScreen::class)->assertSet('nativeUnlockAvailable', true);
});

it('tells the reader the enrolment has gone rather than that the unlock failed', function (): void {
    unreadableEntryUser('entry-gone');
    unreadableEntryVault(keepsEntry: false);

    Livewire::test(LockScreen::class)
        ->call('nativeUnlock')
        ->assertNoRedirect()
        ->assertSet('flashMessage', Lang::get('auth::lock_screen.native_unlock_reset'));
});

it('takes the control down in the same response', function (): void {
    unreadableEntryUser('entry-stands-down');
    unreadableEntryVault(keepsEntry: false);

    Livewire::test(LockScreen::class)
        ->call('nativeUnlock')
        ->assertSet('nativeUnlockAvailable', false);
});

it('records the enrolment as gone, so the settings control can arm it again', function (): void {
    $user = unreadableEntryUser('entry-clears-flag');
    unreadableEntryVault(keepsEntry: false);

    Livewire::test(LockScreen::class)->call('nativeUnlock');

    expect(app(MobileLockGateway::class)->isColdStartEnrolled((int) $user->id))->toBeFalse();
});

it('does not offer the native unlock on the next mount once the entry has gone', function (): void {
    unreadableEntryUser('entry-next-mount');
    unreadableEntryVault(keepsEntry: false);

    Livewire::test(LockScreen::class)->call('nativeUnlock');

    Livewire::test(LockScreen::class)->assertSet('nativeUnlockAvailable', false);
});

// A prompt the reader declined leaves a working enrolment behind it, so the
// control stays and the copy stays the one about trying again.

it('leaves a declined prompt enrolled and still offered', function (): void {
    $user = unreadableEntryUser('entry-declined');
    unreadableEntryVault(keepsEntry: true);

    Livewire::test(LockScreen::class)
        ->call('nativeUnlock')
        ->assertSet('nativeUnlockAvailable', true)
        ->assertSet('flashMessage', Lang::get('auth::lock_screen.native_unlock_failed'));

    expect(app(MobileLockGateway::class)->isColdStartEnrolled((int) $user->id))->toBeTrue();
});
