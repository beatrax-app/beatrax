<?php

declare(strict_types=1);

use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Modules\Auth\Internal\Lock\LockStateManager;
use Modules\Auth\Public\Services\AppLockKeyService;

function makeKeyServiceSession(): Store
{
    return new Store('beatrax_test', new ArraySessionHandler(120));
}

it('admitDataKey() unlocks the session and release() then returns exactly the admitted key', function (): void {
    $manager = new LockStateManager;
    $service = new AppLockKeyService($manager);
    $session = makeKeyServiceSession();
    $manager->lock($session);

    $key = str_repeat("\x2a", 32);
    $service->admitDataKey($session, $key);

    expect($service->release($session))->toBe($key);
});

it('admitDataKey() is the inverse of withhold()', function (): void {
    $manager = new LockStateManager;
    $service = new AppLockKeyService($manager);
    $session = makeKeyServiceSession();

    $service->admitDataKey($session, 'recovered-key');
    expect($service->release($session))->toBe('recovered-key');

    $service->withhold($session);
    expect($service->release($session))->toBeNull();
});

it('release() returns null when the session is locked', function (): void {
    $manager = new LockStateManager;
    $service = new AppLockKeyService($manager);
    $session = makeKeyServiceSession();

    $manager->lock($session);

    expect($service->release($session))->toBeNull();
});

it('release() returns the held key when the session is unlocked', function (): void {
    $manager = new LockStateManager;
    $service = new AppLockKeyService($manager);
    $session = makeKeyServiceSession();

    $manager->unlock($session, 'data-key-value');

    expect($service->release($session))->toBe('data-key-value');
});

it('release() returns null before any unlock() call', function (): void {
    $manager = new LockStateManager;
    $service = new AppLockKeyService($manager);
    $session = makeKeyServiceSession();

    // Neither locked nor unlocked: isLocked defaults false, but with no key
    // stored release() still has nothing to hand back.
    expect($service->release($session))->toBeNull();
});

it('withhold() delegates to LockStateManager->lock() and the session becomes locked', function (): void {
    $manager = new LockStateManager;
    $service = new AppLockKeyService($manager);
    $session = makeKeyServiceSession();

    $manager->unlock($session, 'key');
    $service->withhold($session);

    expect($manager->isLocked($session))->toBeTrue();
    expect($service->release($session))->toBeNull();
});
