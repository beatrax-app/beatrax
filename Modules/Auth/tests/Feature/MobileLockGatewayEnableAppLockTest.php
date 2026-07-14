<?php

declare(strict_types=1);

use Illuminate\Contracts\Session\Session;
use Illuminate\Database\DatabaseManager;
use Modules\Auth\Public\Services\AppLockKeyService;
use Modules\Auth\Public\Services\MobileLockGateway;
use Modules\Core\Models\User;

/*
 * MobileLockGatewayEnableAppLockTest — Task 1 (Phase 15 import-join): the
 * new enableAppLock() seam MobileImportBootstrap drives to provision the
 * LOCK-04 KEK immediately after SignupAction creates the fresh device's
 * local user — a precondition every subsequent sync-identity/GDK-keyring
 * write hard-throws without.
 */

function mobileLockGatewayUser(string $username = 'mobile-lock-gateway-user'): User
{
    return User::query()->create([
        'username' => $username,
        'password' => bcrypt('a-genuinely-long-account-password'),
        'period_start_day' => 1,
    ]);
}

it('enableAppLock provisions the app-lock and leaves the session unlocked with the data key present', function (): void {
    $user = mobileLockGatewayUser();

    /** @var Session $session */
    $session = app(Session::class);

    /** @var MobileLockGateway $gateway */
    $gateway = app(MobileLockGateway::class);

    $gateway->enableAppLock((int) $user->id, '4269', 'a-genuinely-long-account-password', $session);

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    $config = $db->connection()->table('user_app_lock_configs')
        ->where('user_id', $user->id)
        ->first();

    expect($config)->not->toBeNull();
    expect((bool) $config->lock_enabled)->toBeTrue();
    expect($config->pin_hash)->not->toBeNull();

    // The session was primed unlocked (AppLockProvisioner::enable()'s own
    // documented Gap B fix) — release() must return the live data key.
    /** @var AppLockKeyService $keyService */
    $keyService = app(AppLockKeyService::class);
    expect($keyService->release($session))->not->toBeNull();
});
