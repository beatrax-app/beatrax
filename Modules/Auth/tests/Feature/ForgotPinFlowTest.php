<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Livewire\Livewire;
use Modules\Auth\Internal\Http\Livewire\AppLockSettingsSection;
use Modules\Auth\Internal\Lock\AppLockKdf;
use Modules\Auth\Internal\Lock\AppLockKeyWrap;
use Modules\Auth\Internal\Lock\AppLockProvisioner;
use Modules\Auth\Internal\Lock\BiometricDeviceStore;
use Modules\Core\Models\User;

it('AppLockProvisioner has rewrapForNewPin, changePin, and disable methods', function (): void {
    expect(method_exists(AppLockProvisioner::class, 'rewrapForNewPin'))->toBeTrue();
    expect(method_exists(AppLockProvisioner::class, 'changePin'))->toBeTrue();
    expect(method_exists(AppLockProvisioner::class, 'disable'))->toBeTrue();
});

it('after password re-auth a new PIN re-wraps the data key and the same key is recoverable', function (): void {
    $user = User::query()->create([
        'username' => 'alice',
        'password' => bcrypt('my-secure-password'),
        'period_start_day' => 1,
    ]);
    $this->actingAs($user);

    /** @var AppLockProvisioner $provisioner */
    $provisioner = $this->app->make(AppLockProvisioner::class);
    /** @var AppLockKdf $kdf */
    $kdf = $this->app->make(AppLockKdf::class);
    /** @var AppLockKeyWrap $keyWrap */
    $keyWrap = $this->app->make(AppLockKeyWrap::class);

    $provisioner->enable($user->id, '123456', 'my-secure-password');
    expect($provisioner->isEnabled($user->id))->toBeTrue();

    $row = app(DatabaseManager::class)
        ->connection()
        ->table('user_app_lock_configs')
        ->where('user_id', $user->id)
        ->first();
    expect($row)->not->toBeNull();

    /** @var stdClass $row */
    $kdfSalt = $row->kdf_salt;
    expect(is_string($kdfSalt))->toBeTrue();

    $pinWrapKey = $kdf->deriveWrapKey('123456', $kdfSalt);
    $originalDataKey = $keyWrap->unwrap($row->pin_wrapped_key, $pinWrapKey);
    sodium_memzero($pinWrapKey);
    expect($originalDataKey)->not->toBeFalse();

    $result = $provisioner->rewrapForNewPin($user->id, 'wrong-password', '567890');
    expect($result)->toBeFalse();

    $result = $provisioner->rewrapForNewPin($user->id, 'my-secure-password', '567890');
    expect($result)->toBeTrue();

    $row2 = app(DatabaseManager::class)
        ->connection()
        ->table('user_app_lock_configs')
        ->where('user_id', $user->id)
        ->first();
    expect($row2)->not->toBeNull();

    /** @var stdClass $row2 */
    $kdfSalt2 = $row2->kdf_salt;
    expect(is_string($kdfSalt2))->toBeTrue();

    $newPinWrapKey = $kdf->deriveWrapKey('567890', $kdfSalt2);
    $recoveredDataKey = $keyWrap->unwrap($row2->pin_wrapped_key, $newPinWrapKey);
    sodium_memzero($newPinWrapKey);

    expect($recoveredDataKey)->not->toBeFalse();
    // Identical after the re-wrap: a changed PIN must never lose the key.
    /** @var string $originalDataKey */
    /** @var string $recoveredDataKey */
    expect($recoveredDataKey)->toBe($originalDataKey);

    sodium_memzero($originalDataKey);
    sodium_memzero($recoveredDataKey);
});

it('settings Forgot PIN flow resets the PIN via the account password (WR-02)', function (): void {
    $user = User::query()->create([
        'username' => 'forgot-ui-user',
        'password' => bcrypt('forgot-ui-pass'),
        'period_start_day' => 1,
    ]);
    $this->actingAs($user);

    /** @var AppLockProvisioner $provisioner */
    $provisioner = $this->app->make(AppLockProvisioner::class);
    $provisioner->enable($user->id, '123456', 'forgot-ui-pass');

    Livewire::test(AppLockSettingsSection::class)
        ->call('confirmForgotPin')
        ->assertSet('confirmingForgotPin', true)
        ->set('accountPassword', 'wrong-password')
        ->set('newPin', '567890')
        ->set('confirmPin', '567890')
        ->call('resetForgottenPin')
        ->assertSee('Incorrect account password.');

    expect($provisioner->verifyPin($user->id, '123456'))->toBeTrue();

    Livewire::test(AppLockSettingsSection::class)
        ->call('confirmForgotPin')
        ->set('accountPassword', 'forgot-ui-pass')
        ->set('newPin', '567890')
        ->set('confirmPin', '567890')
        ->call('resetForgottenPin')
        ->assertSet('confirmingForgotPin', false)
        ->assertSet('accountPassword', '')
        ->assertSet('newPin', '');

    expect($provisioner->verifyPin($user->id, '567890'))->toBeTrue();
    expect($provisioner->verifyPin($user->id, '123456'))->toBeFalse();
    expect($provisioner->isEnabled($user->id))->toBeTrue();
});

it('changePin preserves the data key under the new PIN', function (): void {
    $user = User::query()->create([
        'username' => 'bob',
        'password' => bcrypt('bob-password'),
        'period_start_day' => 1,
    ]);

    /** @var AppLockProvisioner $provisioner */
    $provisioner = $this->app->make(AppLockProvisioner::class);
    /** @var AppLockKdf $kdf */
    $kdf = $this->app->make(AppLockKdf::class);
    /** @var AppLockKeyWrap $keyWrap */
    $keyWrap = $this->app->make(AppLockKeyWrap::class);

    $provisioner->enable($user->id, '111111', 'bob-password');

    $row = app(DatabaseManager::class)
        ->connection()
        ->table('user_app_lock_configs')
        ->where('user_id', $user->id)
        ->first();
    /** @var stdClass $row */
    $kdfSalt = $row->kdf_salt;
    expect(is_string($kdfSalt))->toBeTrue();

    $pinWrapKey = $kdf->deriveWrapKey('111111', $kdfSalt);
    $originalDataKey = $keyWrap->unwrap($row->pin_wrapped_key, $pinWrapKey);
    sodium_memzero($pinWrapKey);
    expect($originalDataKey)->not->toBeFalse();

    $result = $provisioner->changePin($user->id, 'wrong', '222222');
    expect($result)->toBeFalse();

    $rowAfterFail = app(DatabaseManager::class)
        ->connection()
        ->table('user_app_lock_configs')
        ->where('user_id', $user->id)
        ->first();
    /** @var stdClass $rowAfterFail */
    expect($rowAfterFail->pin_wrapped_key)->toBe($row->pin_wrapped_key);

    $result = $provisioner->changePin($user->id, '111111', '222222');
    expect($result)->toBeTrue();

    $row3 = app(DatabaseManager::class)
        ->connection()
        ->table('user_app_lock_configs')
        ->where('user_id', $user->id)
        ->first();
    /** @var stdClass $row3 */
    $kdfSalt3 = $row3->kdf_salt;
    expect(is_string($kdfSalt3))->toBeTrue();

    $newPinWrapKey = $kdf->deriveWrapKey('222222', $kdfSalt3);
    $recoveredDataKey = $keyWrap->unwrap($row3->pin_wrapped_key, $newPinWrapKey);
    sodium_memzero($newPinWrapKey);
    expect($recoveredDataKey)->not->toBeFalse();

    /** @var string $originalDataKey */
    /** @var string $recoveredDataKey */
    expect($recoveredDataKey)->toBe($originalDataKey);

    sodium_memzero($originalDataKey);
    sodium_memzero($recoveredDataKey);
});

it('disable requires the correct PIN and clears all lock material on success', function (): void {
    $user = User::query()->create([
        'username' => 'carol',
        'password' => bcrypt('carol-pass'),
        'period_start_day' => 1,
    ]);

    /** @var AppLockProvisioner $provisioner */
    $provisioner = $this->app->make(AppLockProvisioner::class);

    $provisioner->enable($user->id, '999999', 'carol-pass');
    expect($provisioner->isEnabled($user->id))->toBeTrue();

    $result = $provisioner->disable($user->id, 'wrong');
    expect($result)->toBeFalse();
    expect($provisioner->isEnabled($user->id))->toBeTrue();

    $result = $provisioner->disable($user->id, '999999');
    expect($result)->toBeTrue();
    expect($provisioner->isEnabled($user->id))->toBeFalse();

    $row = app(DatabaseManager::class)
        ->connection()
        ->table('user_app_lock_configs')
        ->where('user_id', $user->id)
        ->first();
    /** @var stdClass $row */
    expect($row->pin_hash)->toBeNull();
    expect($row->pin_wrapped_key)->toBeNull();
    expect($row->password_wrapped_key)->toBeNull();
    expect((int) $row->failed_attempts)->toBe(0);
});

it('disable() and re-enable() both delete stale biometric credentials (WR-06)', function (): void {
    $user = User::query()->create([
        'username' => 'stale-cred-user',
        'password' => bcrypt('stale-pass'),
        'period_start_day' => 1,
    ]);

    /** @var AppLockProvisioner $provisioner */
    $provisioner = $this->app->make(AppLockProvisioner::class);
    /** @var BiometricDeviceStore $store */
    $store = $this->app->make(BiometricDeviceStore::class);
    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);

    $credCount = static fn (): int => $db->connection()
        ->table('user_biometric_credentials')
        ->where('user_id', $user->id)
        ->count();

    // The credential must not survive disable(): its wrap holds the very data
    // key disable() destroys.
    $provisioner->enable($user->id, '123456', 'stale-pass');
    $store->store($user->id, base64_encode('stale-cred-1'), 'Old Device', str_repeat("\x05", 32), 'fake-cbor', 'webauthn');
    expect($credCount())->toBe(1);

    expect($provisioner->disable($user->id, '123456'))->toBeTrue();
    expect($credCount())->toBe(0);

    // Re-enabling mints a new data key, so a credential wrapping the old one
    // has to go the same way.
    $provisioner->enable($user->id, '123456', 'stale-pass');
    $store->store($user->id, base64_encode('stale-cred-2'), 'Old Device 2', str_repeat("\x06", 32), 'fake-cbor', 'webauthn');
    expect($credCount())->toBe(1);

    $provisioner->enable($user->id, '567890', 'stale-pass');
    expect($credCount())->toBe(0);
});
