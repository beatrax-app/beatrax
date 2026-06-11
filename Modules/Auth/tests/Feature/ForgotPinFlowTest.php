<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Modules\Auth\Internal\Lock\AppLockKdf;
use Modules\Auth\Internal\Lock\AppLockKeyWrap;
use Modules\Auth\Internal\Lock\AppLockProvisioner;
use Modules\Core\Models\User;

/*
 * Feature coverage for the forgot-PIN recovery flow:
 * after password re-auth a new PIN re-wraps the data key via the
 * password recovery wrap, and the same data key is recoverable.
 *
 * Also covers changePin() and disable() — the three new methods
 * added to AppLockProvisioner by plan 05-04.
 */

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

    // Enable the lock with PIN 1234 and the real account password.
    $provisioner->enable($user->id, '1234', 'my-secure-password');
    expect($provisioner->isEnabled($user->id))->toBeTrue();

    // Read the original data key by unwrapping the pin wrap.
    $row = app(DatabaseManager::class)
        ->connection()
        ->table('user_app_lock_configs')
        ->where('user_id', $user->id)
        ->first();
    expect($row)->not->toBeNull();

    /** @var stdClass $row */
    $kdfSalt = $row->kdf_salt;
    expect(is_string($kdfSalt))->toBeTrue();

    $pinWrapKey = $kdf->deriveWrapKey('1234', $kdfSalt);
    $originalDataKey = $keyWrap->unwrap($row->pin_wrapped_key, $pinWrapKey);
    sodium_memzero($pinWrapKey);
    expect($originalDataKey)->not->toBeFalse();

    // Perform the forgot-PIN re-wrap: wrong password returns false.
    $result = $provisioner->rewrapForNewPin($user->id, 'wrong-password', '5678');
    expect($result)->toBeFalse();

    // Correct password re-wraps under the new PIN.
    $result = $provisioner->rewrapForNewPin($user->id, 'my-secure-password', '5678');
    expect($result)->toBeTrue();

    // Verify the new PIN wrap decrypts to the SAME data key.
    $row2 = app(DatabaseManager::class)
        ->connection()
        ->table('user_app_lock_configs')
        ->where('user_id', $user->id)
        ->first();
    expect($row2)->not->toBeNull();

    /** @var stdClass $row2 */
    $kdfSalt2 = $row2->kdf_salt;
    expect(is_string($kdfSalt2))->toBeTrue();

    $newPinWrapKey = $kdf->deriveWrapKey('5678', $kdfSalt2);
    $recoveredDataKey = $keyWrap->unwrap($row2->pin_wrapped_key, $newPinWrapKey);
    sodium_memzero($newPinWrapKey);

    expect($recoveredDataKey)->not->toBeFalse();
    // The data key must be identical after re-wrap — no key loss (D-11/D-21).
    /** @var string $originalDataKey */
    /** @var string $recoveredDataKey */
    expect($recoveredDataKey)->toBe($originalDataKey);

    sodium_memzero($originalDataKey);
    sodium_memzero($recoveredDataKey);
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

    $provisioner->enable($user->id, '1111', 'bob-password');

    // Capture original data key.
    $row = app(DatabaseManager::class)
        ->connection()
        ->table('user_app_lock_configs')
        ->where('user_id', $user->id)
        ->first();
    /** @var stdClass $row */
    $kdfSalt = $row->kdf_salt;
    expect(is_string($kdfSalt))->toBeTrue();

    $pinWrapKey = $kdf->deriveWrapKey('1111', $kdfSalt);
    $originalDataKey = $keyWrap->unwrap($row->pin_wrapped_key, $pinWrapKey);
    sodium_memzero($pinWrapKey);
    expect($originalDataKey)->not->toBeFalse();

    // Wrong current PIN returns false and does NOT mutate the row.
    $result = $provisioner->changePin($user->id, 'wrong', '2222');
    expect($result)->toBeFalse();

    // The old PIN still works (row unchanged).
    $rowAfterFail = app(DatabaseManager::class)
        ->connection()
        ->table('user_app_lock_configs')
        ->where('user_id', $user->id)
        ->first();
    /** @var stdClass $rowAfterFail */
    expect($rowAfterFail->pin_wrapped_key)->toBe($row->pin_wrapped_key);

    // Correct current PIN succeeds.
    $result = $provisioner->changePin($user->id, '1111', '2222');
    expect($result)->toBeTrue();

    // New PIN decrypts to the same data key.
    $row3 = app(DatabaseManager::class)
        ->connection()
        ->table('user_app_lock_configs')
        ->where('user_id', $user->id)
        ->first();
    /** @var stdClass $row3 */
    $kdfSalt3 = $row3->kdf_salt;
    expect(is_string($kdfSalt3))->toBeTrue();

    $newPinWrapKey = $kdf->deriveWrapKey('2222', $kdfSalt3);
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

    $provisioner->enable($user->id, '9999', 'carol-pass');
    expect($provisioner->isEnabled($user->id))->toBeTrue();

    // Wrong PIN: returns false, lock stays enabled.
    $result = $provisioner->disable($user->id, 'wrong');
    expect($result)->toBeFalse();
    expect($provisioner->isEnabled($user->id))->toBeTrue();

    // Correct PIN: returns true, lock_enabled=false, PIN material cleared.
    $result = $provisioner->disable($user->id, '9999');
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
