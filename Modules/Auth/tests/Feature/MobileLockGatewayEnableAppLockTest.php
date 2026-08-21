<?php

declare(strict_types=1);

use Illuminate\Contracts\Session\Session;
use Illuminate\Database\DatabaseManager;
use Modules\Auth\Public\Services\AppLockKeyService;
use Modules\Auth\Public\Services\MobileLockGateway;
use Modules\Core\Models\User;

// The seam the mobile bootstrap drives to provision the KEK right after signup
// creates the device's local user: every later sync-identity and keyring write
// hard-throws without it.

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

    $gateway->enableAppLock((int) $user->id, '426900', 'a-genuinely-long-account-password', $session);

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    $config = $db->connection()->table('user_app_lock_configs')
        ->where('user_id', $user->id)
        ->first();

    expect($config)->not->toBeNull();
    expect((bool) $config->lock_enabled)->toBeTrue();
    expect($config->pin_hash)->not->toBeNull();

    // enable() primes the session unlocked, so the key is live immediately.
    /** @var AppLockKeyService $keyService */
    $keyService = app(AppLockKeyService::class);
    expect($keyService->release($session))->not->toBeNull();
});
