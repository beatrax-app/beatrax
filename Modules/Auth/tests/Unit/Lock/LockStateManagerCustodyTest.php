<?php

declare(strict_types=1);

use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Modules\Auth\Internal\Lock\LockStateManager;
use Modules\Auth\Public\Contracts\KeyCustodian;

// Models a native out-of-session store. The handle is "handle:" . base64(key)
// so it is provably not the raw key, and forget() records what it released.
function spyCustodian(): KeyCustodian
{
    return new class implements KeyCustodian
    {
        /** @var list<string> */
        public array $forgotten = [];

        public function store(string $rawKey): string
        {
            return 'handle:'.base64_encode($rawKey);
        }

        public function read(string $handle): string
        {
            $raw = base64_decode(substr($handle, strlen('handle:')), strict: true);

            return $raw === false ? '' : $raw;
        }

        public function forget(string $handle): void
        {
            $this->forgotten[] = $handle;
        }
    };
}

function custodySession(): Store
{
    return new Store('beatrax_test', new ArraySessionHandler(120));
}

it('stores the custodian handle in the session, never the raw key', function (): void {
    $custodian = spyCustodian();
    $manager = new LockStateManager($custodian);
    $session = custodySession();

    $rawKey = str_repeat("\x2a", 32);
    $manager->unlock($session, $rawKey);

    $stored = $session->get(LockStateManager::DATA_KEY_SESSION);
    expect($stored)->toBe('handle:'.base64_encode($rawKey))
        ->and($stored)->not->toBe($rawKey);
});

it('heldKey() reads the raw key back through the custodian', function (): void {
    $manager = new LockStateManager(spyCustodian());
    $session = custodySession();

    $rawKey = random_bytes(32);
    $manager->unlock($session, $rawKey);

    expect($manager->heldKey($session))->toBe($rawKey);
});

it('heldKey() returns null when nothing is held', function (): void {
    $manager = new LockStateManager(spyCustodian());

    expect($manager->heldKey(custodySession()))->toBeNull();
});

it('lock() releases the held handle via the custodian and forgets it', function (): void {
    $custodian = spyCustodian();
    $manager = new LockStateManager($custodian);
    $session = custodySession();

    $rawKey = str_repeat("\x2a", 32);
    $manager->unlock($session, $rawKey);
    $handle = $session->get(LockStateManager::DATA_KEY_SESSION);

    $manager->lock($session);

    expect($custodian->forgotten)->toBe([$handle])
        ->and($session->get(LockStateManager::DATA_KEY_SESSION))->toBeNull()
        ->and($manager->isLocked($session))->toBeTrue()
        ->and($manager->heldKey($session))->toBeNull();
});
