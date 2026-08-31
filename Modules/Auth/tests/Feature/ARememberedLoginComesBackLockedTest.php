<?php

declare(strict_types=1);

use Illuminate\Auth\AuthManager;
use Illuminate\Auth\SessionGuard;
use Illuminate\Contracts\Session\Session;
use Modules\Auth\Internal\Lock\AppLockProvisioner;
use Modules\Auth\Internal\Lock\LockStateManager;
use Modules\Auth\Public\Services\AppLockKeyService;
use Modules\Core\Models\User;

// Every app-lock test before this one set the guard directly with actingAs(),
// so the recaller — the branch that mints a session the lock flag never
// reached — had no coverage at all.

/**
 * @return array{name: string, value: string}
 */
function recalledLoginCookie(string $username, string $password): array
{
    /** @var AuthManager $auth */
    $auth = app(AuthManager::class);

    /** @var SessionGuard $guard */
    $guard = $auth->guard();
    $name = $guard->getRecallerName();

    $response = test()->post('/login', [
        'username' => $username,
        'password' => $password,
        'remember' => 'on',
    ]);

    $cookie = $response->getCookie($name);

    expect($cookie)->not->toBeNull('the login response issued no remember cookie');

    return ['name' => $name, 'value' => (string) $cookie?->getValue()];
}

// The session cookie expiring on its own leaves exactly this state: no server
// session, a remember cookie that outlives it by years.
function recalledLoginForgetTheSession(): void
{
    test()->flushSession();
    app(AuthManager::class)->forgetGuards();
}

function recalledLoginUser(string $username, string $password, bool $lockEnabled = true): User
{
    /** @var User $user */
    $user = User::query()->create([
        'username' => $username,
        'password' => bcrypt($password),
        'period_start_day' => 1,
    ]);

    if ($lockEnabled) {
        /** @var AppLockProvisioner $provisioner */
        $provisioner = app(AppLockProvisioner::class);
        $provisioner->enable($user->id, '123456', $password);
    }

    return $user;
}

it('sends a remember-me recaller to the lock screen rather than into the app', function (): void {
    recalledLoginUser('recalled-alice', 'recalled-password-1');

    $cookie = recalledLoginCookie('recalled-alice', 'recalled-password-1');

    recalledLoginForgetTheSession();

    test()->withCookie($cookie['name'], $cookie['value'])
        ->get(route('dashboard'))
        ->assertRedirect(route('auth.lock'));
});

it('leaves the recalled session holding no data key it never proved', function (): void {
    recalledLoginUser('recalled-bob', 'recalled-password-1');

    $cookie = recalledLoginCookie('recalled-bob', 'recalled-password-1');

    recalledLoginForgetTheSession();

    test()->withCookie($cookie['name'], $cookie['value'])->get(route('dashboard'));

    /** @var Session $session */
    $session = app(Session::class);
    /** @var LockStateManager $lockState */
    $lockState = app(LockStateManager::class);
    /** @var AppLockKeyService $keyService */
    $keyService = app(AppLockKeyService::class);

    expect($lockState->isLocked($session))->toBeTrue();
    expect($keyService->release($session))->toBeNull();
});

// The recaller is the only credential in play, and it proves no data key, so
// locking it is the whole fix — the account with no lock must be untouched.
it('lets a remember-me recaller straight in when the account has no app lock', function (): void {
    recalledLoginUser('recalled-carol', 'recalled-password-1', lockEnabled: false);

    $cookie = recalledLoginCookie('recalled-carol', 'recalled-password-1');

    recalledLoginForgetTheSession();

    $response = test()->withCookie($cookie['name'], $cookie['value'])->get(route('dashboard'));

    expect($response->headers->get('Location'))->not->toBe(route('auth.lock'));

    /** @var Session $session */
    $session = app(Session::class);
    /** @var LockStateManager $lockState */
    $lockState = app(LockStateManager::class);

    expect($lockState->isLocked($session))->toBeFalse();
});

// The password login is the other half: it proves the data key on the way in,
// so a fail-closed default must not leave it stuck behind the lock screen.
it('keeps a password login unlocked even though it also carries remember-me', function (): void {
    recalledLoginUser('recalled-dave', 'recalled-password-1');

    recalledLoginCookie('recalled-dave', 'recalled-password-1');

    /** @var Session $session */
    $session = app(Session::class);
    /** @var LockStateManager $lockState */
    $lockState = app(LockStateManager::class);
    /** @var AppLockKeyService $keyService */
    $keyService = app(AppLockKeyService::class);

    expect($lockState->isLocked($session))->toBeFalse();
    expect($keyService->release($session))->toBeString();
});
