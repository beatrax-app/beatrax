<?php

declare(strict_types=1);

use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Modules\Auth\Internal\Lock\LockStateManager;

function makeSession(): Store
{
    return new Store('beatrax_test', new ArraySessionHandler(120));
}

it('reports unlocked when the session has no lock state set', function (): void {
    $manager = new LockStateManager;
    $session = makeSession();

    expect($manager->isLocked($session))->toBeFalse();
});

it('reports locked after lock() is called', function (): void {
    $manager = new LockStateManager;
    $session = makeSession();

    $manager->lock($session);

    expect($manager->isLocked($session))->toBeTrue();
});

it('reports unlocked after unlock() is called', function (): void {
    $manager = new LockStateManager;
    $session = makeSession();

    $manager->lock($session);
    $manager->unlock($session, 'some-data-key');

    expect($manager->isLocked($session))->toBeFalse();
});

it('forgets the data key when lock() is called', function (): void {
    $manager = new LockStateManager;
    $session = makeSession();

    $manager->unlock($session, 'my-data-key');
    expect($session->get(LockStateManager::DATA_KEY_SESSION))->toBe('my-data-key');

    $manager->lock($session);
    expect($session->get(LockStateManager::DATA_KEY_SESSION))->toBeNull();
});

it('stores the data key when unlock() is called', function (): void {
    $manager = new LockStateManager;
    $session = makeSession();

    $manager->unlock($session, 'test-key-bytes');

    expect($session->get(LockStateManager::DATA_KEY_SESSION))->toBe('test-key-bytes');
});

it('exposes SESSION_KEY and DATA_KEY_SESSION constants', function (): void {
    expect(LockStateManager::SESSION_KEY)->toBe('beatrax_locked');
    expect(LockStateManager::DATA_KEY_SESSION)->toBe('beatrax_data_key');
});
