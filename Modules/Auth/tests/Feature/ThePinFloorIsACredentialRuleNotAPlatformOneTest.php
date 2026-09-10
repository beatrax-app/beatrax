<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Modules\Auth\Internal\Http\Livewire\LockScreen;
use Modules\Auth\Internal\Lock\AppLockProvisioner;
use Modules\Auth\Internal\Lock\LockStateManager;
use Modules\Auth\Public\Contracts\ColdStartVault;
use Modules\Auth\Public\Services\MobileLockGateway;
use Modules\Core\Models\User;

// Biometric unlocks in the steady state; the PIN is what the reader has to
// produce every so often, so that a face or a finger alone is never the whole
// of what the data key ever costs again. The phone enforced that at both its
// boundaries and the desktop at neither, on no stated reason -- the floor is a
// property of the credential, and Touch ID is the same bargain as Face ID.

function coldStartPinAgeUser(int $daysSincePin): User
{
    $user = User::query()->create([
        'username' => 'pin-floor-'.bin2hex(random_bytes(4)),
        'password' => bcrypt('account-password'),
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);

    test()->actingAs($user);

    app(AppLockProvisioner::class)->enable((int) $user->id, '123456', 'account-password');
    app(MobileLockGateway::class)->markColdStartEnrolled((int) $user->id, true);

    DB::connection()->table('user_app_lock_configs')
        ->where('user_id', $user->id)
        ->update(['last_pin_unlock_at' => CarbonImmutable::now()->subDays($daysSincePin)->toDateTimeString()]);

    test()->session([LockStateManager::SESSION_KEY => true]);

    return $user;
}

function coldStartPinAgeVault(string $dataKey): ColdStartVault
{
    $vault = new class($dataKey) implements ColdStartVault
    {
        public int $prompts = 0;

        public function __construct(private readonly string $dataKey) {}

        public function isAvailable(): bool
        {
            return true;
        }

        public function isEnrolled(int $userId): bool
        {
            return true;
        }

        public function enroll(int $userId, string $dataKey): bool
        {
            return true;
        }

        public function recover(int $userId, string $reason): ?string
        {
            $this->prompts++;

            return $this->dataKey;
        }

        public function forget(int $userId): bool
        {
            return true;
        }
    };

    app()->instance(ColdStartVault::class, $vault);

    return $vault;
}

it('offers the native unlock inside the floor', function (): void {
    coldStartPinAgeUser(daysSincePin: MobileLockGateway::PIN_FLOOR_DAYS - 1);
    coldStartPinAgeVault(random_bytes(32));

    Livewire::test(LockScreen::class)->assertSet('nativeUnlockAvailable', true);
});

it('stops offering the native unlock once the floor is due', function (): void {
    coldStartPinAgeUser(daysSincePin: MobileLockGateway::PIN_FLOOR_DAYS);
    coldStartPinAgeVault(random_bytes(32));

    Livewire::test(LockScreen::class)->assertSet('nativeUnlockAvailable', false);
});

it('admits no key past the floor, whatever the render offered', function (): void {
    coldStartPinAgeUser(daysSincePin: MobileLockGateway::PIN_FLOOR_DAYS);
    $vault = coldStartPinAgeVault(random_bytes(32));

    Livewire::test(LockScreen::class)
        ->call('nativeUnlock')
        ->assertNoRedirect();

    expect($vault->prompts)->toBe(0)
        ->and(session(LockStateManager::SESSION_KEY))->toBeTrue()
        ->and(session(LockStateManager::DATA_KEY_SESSION))->toBeNull();
});

// The floor is overdue on an account that has never unlocked with a PIN on this
// device, which is every install that predates the column.

it('treats an account that never unlocked with a PIN as overdue', function (): void {
    $user = coldStartPinAgeUser(daysSincePin: 0);
    coldStartPinAgeVault(random_bytes(32));

    DB::connection()->table('user_app_lock_configs')
        ->where('user_id', $user->id)
        ->update(['last_pin_unlock_at' => null]);

    Livewire::test(LockScreen::class)->assertSet('nativeUnlockAvailable', false);
});

it('leaves the enrolment standing, because a floor is not a lost entry', function (): void {
    $user = coldStartPinAgeUser(daysSincePin: MobileLockGateway::PIN_FLOOR_DAYS);
    coldStartPinAgeVault(random_bytes(32));

    Livewire::test(LockScreen::class)->call('nativeUnlock');

    expect(app(MobileLockGateway::class)->isColdStartEnrolled((int) $user->id))->toBeTrue();
});
