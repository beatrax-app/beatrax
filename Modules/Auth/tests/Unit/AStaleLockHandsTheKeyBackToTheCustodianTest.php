<?php

declare(strict_types=1);

use Illuminate\Contracts\Session\Session;
use Modules\Auth\Internal\Lock\LockStateManager;
use Modules\Auth\Public\Contracts\KeyCustodian;
use Modules\Auth\Public\Enums\KeyCustody;

// On the desktop and mobile bundles DATA_KEY_SESSION holds a handle, not the
// key: dropping the handle without telling the custodian leaves the raw key in
// the Keychain with nothing left that could ever name it again.

function staleLockCustodian(): KeyCustodian
{
    return new class implements KeyCustodian
    {
        /** @var list<string> */
        public array $forgotten = [];

        public function store(string $rawKey): string
        {
            return 'handle:'.$rawKey;
        }

        public function read(string $handle): ?string
        {
            return $handle;
        }

        public function forget(string $handle): void
        {
            $this->forgotten[] = $handle;
        }

        public function custody(): KeyCustody
        {
            return KeyCustody::OperatingSystem;
        }
    };
}

it('tells the custodian to forget the key when it clears a stale lock', function (): void {
    $custodian = staleLockCustodian();
    $manager = new LockStateManager($custodian);

    /** @var Session $session */
    $session = app(Session::class);
    $session->put(LockStateManager::DATA_KEY_SESSION, 'handle:abc');

    $manager->clearStaleLock($session);

    expect($custodian->forgotten)->toBe(['handle:abc'])
        ->and($session->get(LockStateManager::DATA_KEY_SESSION))->toBeNull()
        ->and($manager->isLocked($session))->toBeFalse();
});

it('still tells the custodian to forget the key when it locks', function (): void {
    $custodian = staleLockCustodian();
    $manager = new LockStateManager($custodian);

    /** @var Session $session */
    $session = app(Session::class);
    $manager->unlock($session, 'raw-key');

    $manager->lock($session);

    expect($custodian->forgotten)->toBe(['handle:raw-key']);
});
