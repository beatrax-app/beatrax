<?php

declare(strict_types=1);

use Illuminate\Contracts\Session\Session;
use Livewire\Livewire;
use Modules\Auth\Internal\Http\Livewire\LockScreen;
use Modules\Auth\Public\Contracts\ColdStartVault;
use Modules\Auth\Public\Http\Livewire\AppLockSettingsSection;
use Modules\Auth\Public\Services\AppLockKeyService;
use Modules\Auth\Public\Services\MobileLockGateway;
use Modules\Core\Models\User;

// enable() already deletes the WebAuthn enrolments, because "a leftover
// enrollment wraps whatever key the previous provisioning held". The OS vault
// holds the same thing and neither enable() nor disable() touched it: on the
// desktop, whose isEnrolled() answers from a file on disk, turning the app lock
// off left a safeStorage copy of the data key behind, and turning it back on
// found that file still there -- so the lock screen never re-enrolled and Touch
// ID unlock never came back, with nothing on screen to say why.

// Stands in for the desktop vault: enrolment is durable material that outlives
// the row, which is the whole reason the stale copy went unnoticed.
final class DurableColdStartVault implements ColdStartVault
{
    /** @var array<int, string> */
    public array $keys = [];

    public function isAvailable(): bool
    {
        return true;
    }

    public function isEnrolled(int $userId): bool
    {
        return array_key_exists($userId, $this->keys);
    }

    public function enroll(int $userId, string $dataKey): bool
    {
        $this->keys[$userId] = $dataKey;

        return true;
    }

    public function recover(int $userId, string $reason): ?string
    {
        return $this->keys[$userId] ?? null;
    }

    public function forget(int $userId): void
    {
        unset($this->keys[$userId]);
    }
}

function vaultKeptUser(string $username): User
{
    /** @var User $user */
    $user = User::query()->create([
        'username' => $username,
        'password' => bcrypt('vault-account-pass'),
        'period_start_day' => 1,
    ]);

    test()->actingAs($user);

    return $user;
}

function vaultKeptEnableAndEnroll(string $pin): void
{
    Livewire::test(AppLockSettingsSection::class)
        ->set('newPin', $pin)
        ->set('confirmPin', $pin)
        ->set('accountPassword', 'vault-account-pass')
        ->call('setPin')
        ->assertSet('lockEnabled', true)
        ->call('startEnroll')
        ->assertSet('biometricEnrolled', true);
}

it('drops the OS-vault copy of the data key when the lock is turned off', function (): void {
    $vault = new DurableColdStartVault;
    $this->app->instance(ColdStartVault::class, $vault);

    $user = vaultKeptUser('vault-disable');
    vaultKeptEnableAndEnroll('135790');

    expect($vault->isEnrolled($user->id))->toBeTrue('the fixture must have enrolled, or the assertion below proves nothing');

    Livewire::test(AppLockSettingsSection::class)
        ->call('confirmDisable')
        ->set('currentPin', '135790')
        ->call('disable')
        ->assertSet('lockEnabled', false);

    expect($vault->keys)->toBe([], 'disable() clears every durable wrap of the data key, and the OS vault holds one');
});

it('offers the native unlock again after the lock is turned off and back on', function (): void {
    $vault = new DurableColdStartVault;
    $this->app->instance(ColdStartVault::class, $vault);

    $user = vaultKeptUser('vault-recycle');
    vaultKeptEnableAndEnroll('135790');

    $firstKey = $vault->keys[$user->id];

    Livewire::test(AppLockSettingsSection::class)
        ->call('confirmDisable')
        ->set('currentPin', '135790')
        ->call('disable')
        ->assertSet('lockEnabled', false);

    Livewire::test(AppLockSettingsSection::class)
        ->set('newPin', '246802')
        ->set('confirmPin', '246802')
        ->set('accountPassword', 'vault-account-pass')
        ->call('setPin')
        ->assertSet('lockEnabled', true);

    /** @var Session $session */
    $session = $this->app->make(Session::class);
    $this->app->make(AppLockKeyService::class)->withhold($session);

    Livewire::test(LockScreen::class)->call('submit', '246802');

    // Read before anything else touches the vault: the unlock is the moment the
    // lock screen re-enrols, and it only does so when nothing is enrolled.
    expect($vault->keys[$user->id] ?? null)
        ->not->toBe($firstKey, 'the re-enrolled blob must wrap the key the new PIN provisioned, not the one it replaced');

    Livewire::test(LockScreen::class)->assertSet('nativeUnlockAvailable', true);
});

// The settings screen is not the only way in. The mobile first-run import
// provisions the lock through MobileLockGateway, which carried no forget of its
// own and was safe only while the mobile vault happened to answer isEnrolled()
// from the very column enable() resets.
it('drops the OS-vault copy of the data key when the mobile import path enables the lock', function (): void {
    $vault = new DurableColdStartVault;
    $this->app->instance(ColdStartVault::class, $vault);

    $user = vaultKeptUser('vault-mobile-import');
    $carriedOver = random_bytes(32);
    $vault->enroll($user->id, $carriedOver);

    /** @var Session $session */
    $session = $this->app->make(Session::class);

    $this->app->make(MobileLockGateway::class)
        ->enableAppLock($user->id, '135790', 'vault-account-pass', $session);

    expect($vault->keys)->toBe([], 'enable() mints a key the enrolment predating it cannot wrap, whichever caller ran it');
});
