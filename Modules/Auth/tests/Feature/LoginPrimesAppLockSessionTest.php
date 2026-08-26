<?php

declare(strict_types=1);

use Illuminate\Contracts\Session\Session;
use Illuminate\Database\DatabaseManager;
use Modules\Auth\Internal\Lock\AppLockProvisioner;
use Modules\Auth\Internal\Lock\LockStateManager;
use Modules\Auth\Public\Actions\LoginAction;
use Modules\Auth\Public\Services\AppLockKeyService;
use Modules\Core\Models\User;

// A lock-enabled session must never be unlocked without a data key: login
// recovers the key from the password wrap, or starts locked instead.

function loginPrimeUser(string $username, string $password): User
{
    return User::query()->create([
        'username' => $username,
        'password' => bcrypt($password),
        'period_start_day' => 1,
    ]);
}

it('login with the lock enabled primes the session with the data key (no key-less unlocked state)', function (): void {
    $user = loginPrimeUser('prime-alice', 'prime-pass');

    /** @var AppLockProvisioner $provisioner */
    $provisioner = $this->app->make(AppLockProvisioner::class);
    $provisioner->enable($user->id, '123456', 'prime-pass');

    /** @var LoginAction $login */
    $login = $this->app->make(LoginAction::class);
    expect($login('prime-alice', 'prime-pass', false))->toBeTrue();

    /** @var Session $session */
    $session = $this->app->make(Session::class);
    /** @var LockStateManager $lockState */
    $lockState = $this->app->make(LockStateManager::class);
    /** @var AppLockKeyService $keyService */
    $keyService = $this->app->make(AppLockKeyService::class);

    expect($lockState->isLocked($session))->toBeFalse();

    $key = $keyService->release($session);
    expect($key)->toBeString()
        ->and(strlen((string) $key))->toBe(SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
});

it('login starts the session LOCKED when the password recovery wrap cannot be unwrapped', function (): void {
    $user = loginPrimeUser('prime-bob', 'prime-pass');

    /** @var AppLockProvisioner $provisioner */
    $provisioner = $this->app->make(AppLockProvisioner::class);
    $provisioner->enable($user->id, '123456', 'prime-pass');

    // A stale wrap, as an account-password change that failed to re-wrap would
    // leave behind.
    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $db->connection()->table('user_app_lock_configs')
        ->where('user_id', $user->id)
        ->update(['password_wrapped_key' => base64_encode(random_bytes(56))]);

    /** @var LoginAction $login */
    $login = $this->app->make(LoginAction::class);
    expect($login('prime-bob', 'prime-pass', false))->toBeTrue();

    /** @var Session $session */
    $session = $this->app->make(Session::class);
    /** @var LockStateManager $lockState */
    $lockState = $this->app->make(LockStateManager::class);
    /** @var AppLockKeyService $keyService */
    $keyService = $this->app->make(AppLockKeyService::class);

    // Fails closed: the PIN wrap restores the key on the lock screen instead.
    expect($lockState->isLocked($session))->toBeTrue();
    expect($keyService->release($session))->toBeNull();
});

it('login without the lock enabled does not touch lock state', function (): void {
    loginPrimeUser('prime-carol', 'prime-pass');

    /** @var LoginAction $login */
    $login = $this->app->make(LoginAction::class);
    expect($login('prime-carol', 'prime-pass', false))->toBeTrue();

    /** @var Session $session */
    $session = $this->app->make(Session::class);
    /** @var LockStateManager $lockState */
    $lockState = $this->app->make(LockStateManager::class);

    expect($lockState->isLocked($session))->toBeFalse();
    expect($session->get(LockStateManager::DATA_KEY_SESSION))->toBeNull();
});

it('the login route primes the session too, not only the action behind it', function (): void {
    $user = loginPrimeUser('prime-dave', 'prime-pass');

    /** @var AppLockProvisioner $provisioner */
    $provisioner = $this->app->make(AppLockProvisioner::class);
    $provisioner->enable($user->id, '123456', 'prime-pass');

    $this->post('/login', [
        'username' => 'prime-dave',
        'password' => 'prime-pass',
    ])->assertRedirect();

    /** @var Session $session */
    $session = $this->app->make(Session::class);
    /** @var LockStateManager $lockState */
    $lockState = $this->app->make(LockStateManager::class);
    /** @var AppLockKeyService $keyService */
    $keyService = $this->app->make(AppLockKeyService::class);

    // Unlocked with no key is the one state that must not exist: every
    // encrypted read then fails while the app reports itself open, and an
    // import refuses every row with a lock message the reader cannot act on.
    expect($lockState->isLocked($session))->toBeFalse();
    expect($keyService->release($session))->toBeString();
});
