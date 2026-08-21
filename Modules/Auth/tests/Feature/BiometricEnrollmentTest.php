<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Modules\Auth\Internal\Lock\BiometricDeviceStore;
use Modules\Core\Models\User;

it('BiometricDeviceStore class exists (RED until 05-05)', function (): void {
    expect(class_exists(BiometricDeviceStore::class))->toBeTrue();
});

it('enrolling a device stores a row in user_biometric_credentials', function (): void {
    expect(class_exists(BiometricDeviceStore::class))->toBeTrue();

    $user = User::query()->create([
        'username' => 'alice',
        'password' => 'whatever-password',
        'period_start_day' => 1,
    ]);
    $this->actingAs($user);

    /** @var BiometricDeviceStore $store */
    $store = $this->app->make(BiometricDeviceStore::class);

    $store->store(
        $user->id,
        'test-credential-id',
        'Chrome on iPhone',
        str_repeat("\xAB", 32),
        null,
        'webauthn',
    );

    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $row = $db->connection()
        ->table('user_biometric_credentials')
        ->where('user_id', $user->id)
        ->first();

    expect($row)->not->toBeNull();
    /** @var stdClass $row */
    expect($row->credential_id)->toBe('test-credential-id');
    expect($row->device_label)->toBe('Chrome on iPhone');
    expect($row->platform)->toBe('webauthn');
    expect((int) $row->biometric_failed_count)->toBe(0);
    expect((int) $row->counter)->toBe(0);
});

it('findForUser returns all enrolled credentials scoped to the user', function (): void {
    $user = User::query()->create([
        'username' => 'bob',
        'password' => 'whatever-password',
        'period_start_day' => 1,
    ]);

    /** @var BiometricDeviceStore $store */
    $store = $this->app->make(BiometricDeviceStore::class);

    $store->store($user->id, 'cred-a', 'Device A', str_repeat("\x01", 32), null, 'webauthn');
    $store->store($user->id, 'cred-b', 'Device B', str_repeat("\x02", 32), null, 'webauthn');

    $credentials = $store->findForUser($user->id);
    expect($credentials)->toHaveCount(2);

    $other = User::query()->create([
        'username' => 'carol',
        'password' => 'whatever-password',
        'period_start_day' => 1,
    ]);
    $otherCreds = $store->findForUser($other->id);
    expect($otherCreds)->toHaveCount(0);
});

it('incrementFailureCount and resetFailureCount manage biometric_failed_count correctly', function (): void {
    $user = User::query()->create([
        'username' => 'dave',
        'password' => 'whatever-password',
        'period_start_day' => 1,
    ]);

    /** @var BiometricDeviceStore $store */
    $store = $this->app->make(BiometricDeviceStore::class);

    $store->store($user->id, 'cred-dave', 'Dave Device', str_repeat("\x03", 32), null, 'webauthn');
    $cred = $store->findByCredentialId($user->id, 'cred-dave');

    expect($cred)->not->toBeNull();
    /** @var stdClass $cred */
    expect((int) $cred->biometric_failed_count)->toBe(0);

    $store->incrementFailureCount($cred->id);
    $store->incrementFailureCount($cred->id);

    $afterIncrement = $store->findByCredentialId($user->id, 'cred-dave');
    /** @var stdClass $afterIncrement */
    expect((int) $afterIncrement->biometric_failed_count)->toBe(2);

    $store->resetFailureCount($cred->id);

    $afterReset = $store->findByCredentialId($user->id, 'cred-dave');
    /** @var stdClass $afterReset */
    expect((int) $afterReset->biometric_failed_count)->toBe(0);
});

it('isArmed returns false when failure count exceeds the disable threshold', function (): void {
    $user = User::query()->create([
        'username' => 'eve',
        'password' => 'whatever-password',
        'period_start_day' => 1,
    ]);

    /** @var BiometricDeviceStore $store */
    $store = $this->app->make(BiometricDeviceStore::class);

    $store->store($user->id, 'cred-eve', 'Eve Device', str_repeat("\x04", 32), null, 'webauthn');
    $cred = $store->findByCredentialId($user->id, 'cred-eve');
    /** @var stdClass $cred */
    expect($store->isArmed($cred))->toBeTrue();

    for ($i = 0; $i < BiometricDeviceStore::BIOMETRIC_DISABLE_THRESHOLD; $i++) {
        $store->incrementFailureCount($cred->id);
    }

    $disarmed = $store->findByCredentialId($user->id, 'cred-eve');
    /** @var stdClass $disarmed */
    expect($store->isArmed($disarmed))->toBeFalse();

    $store->resetAllForUser($user->id);

    $rearmed = $store->findByCredentialId($user->id, 'cred-eve');
    /** @var stdClass $rearmed */
    expect($store->isArmed($rearmed))->toBeTrue();
});
