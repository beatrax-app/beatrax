<?php

declare(strict_types=1);

use Illuminate\Contracts\Session\Session;
use Illuminate\Database\DatabaseManager;
use Illuminate\Validation\ValidationException;
use Modules\Auth\Internal\Lock\AppLockProvisioner;
use Modules\Auth\Public\Services\MobileLockGateway;
use Modules\Core\Models\User;

/*
 * The 6-digit app-lock PIN floor (spec F3-R36) is enforced CENTRALLY in the
 * provisioner, so it holds for every caller — the desktop UI, the mobile
 * gateway, or a future direct caller — not merely the UI-layer validators. A
 * short PIN's low entropy is the whole finding: it is offline-brute-forceable
 * from a stolen database. These tests fail if the central assertPinMeetsFloor()
 * guard is removed — without it the provisioner would mint a data key from a
 * five-digit PIN.
 */

function pinFloorUser(string $username): User
{
    return User::query()->create([
        'username' => $username,
        'password' => 'account-password',
        'period_start_day' => 1,
    ]);
}

function pinFloorConfigExists(int $userId): bool
{
    return app(DatabaseManager::class)->connection()
        ->table('user_app_lock_configs')
        ->where('user_id', $userId)
        ->exists();
}

it('enable() refuses a PIN shorter than six digits and mints no key', function (): void {
    $user = pinFloorUser('pin-floor-enable');
    test()->actingAs($user);

    /** @var AppLockProvisioner $provisioner */
    $provisioner = app(AppLockProvisioner::class);

    expect(fn () => $provisioner->enable($user->id, '12345', 'account-password'))
        ->toThrow(ValidationException::class);

    // The guard runs before any write, so no config row (and no data key) exists.
    expect(pinFloorConfigExists($user->id))->toBeFalse();
});

it('changePin() refuses a new PIN shorter than six digits', function (): void {
    $user = pinFloorUser('pin-floor-change');
    test()->actingAs($user);

    /** @var AppLockProvisioner $provisioner */
    $provisioner = app(AppLockProvisioner::class);
    $provisioner->enable($user->id, '654321', 'account-password');

    expect(fn () => $provisioner->changePin($user->id, '654321', '12345'))
        ->toThrow(ValidationException::class);
});

it('rewrapForNewPin() refuses a new PIN shorter than six digits', function (): void {
    $user = pinFloorUser('pin-floor-rewrap');
    test()->actingAs($user);

    /** @var AppLockProvisioner $provisioner */
    $provisioner = app(AppLockProvisioner::class);
    $provisioner->enable($user->id, '654321', 'account-password');

    expect(fn () => $provisioner->rewrapForNewPin($user->id, 'account-password', '12345'))
        ->toThrow(ValidationException::class);
});

it('the mobile gateway refuses a short PIN too (it delegates to the provisioner)', function (): void {
    $user = pinFloorUser('pin-floor-mobile');
    test()->actingAs($user);

    /** @var MobileLockGateway $gateway */
    $gateway = app(MobileLockGateway::class);
    /** @var Session $session */
    $session = app(Session::class);

    expect(fn () => $gateway->enableAppLock($user->id, '12345', 'account-password', $session))
        ->toThrow(ValidationException::class);

    expect(pinFloorConfigExists($user->id))->toBeFalse();
});

it('a six-digit PIN is still accepted', function (): void {
    $user = pinFloorUser('pin-floor-ok');
    test()->actingAs($user);

    /** @var AppLockProvisioner $provisioner */
    $provisioner = app(AppLockProvisioner::class);
    $provisioner->enable($user->id, '123456', 'account-password');

    expect(pinFloorConfigExists($user->id))->toBeTrue();
});
