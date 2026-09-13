<?php

declare(strict_types=1);

use Illuminate\Config\Repository;
use Illuminate\Http\Client\Factory;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Modules\Auth\Internal\Lock\LockStateManager;
use Modules\Auth\Public\Exceptions\KeyCustodyRefused;
use Modules\Desktop\Internal\Native\DesktopKeyCustodian;
use Modules\Desktop\Internal\Native\SafeStorageBackendProbe;
use Modules\Desktop\Tests\Support\StubElectronApi;
use Native\Desktop\System;

// Lives at the root rather than in either module because it is the seam between
// them that carries the defect: what DesktopKeyCustodian::store() returns is put
// into the session by LockStateManager, unread, on the next line. Neither
// module's own suite can see both halves.

function sessionProofCustodian(?string $encrypted): DesktopKeyCustodian
{
    $system = Mockery::mock(System::class);
    $system->shouldReceive('canEncrypt')->andReturn(true);
    $system->shouldReceive('encrypt')->andReturn($encrypted);

    return new DesktopKeyCustodian(
        new Repository(['nativephp-internal' => ['running' => true]]),
        $system,
        new SafeStorageBackendProbe(
            new StubElectronApi(new Factory, (string) json_encode(['result' => 'gnome_libsecret'])),
            'Linux',
        ),
    );
}

function sessionProofStore(): Store
{
    return new Store('beatrax_session', new ArraySessionHandler(120));
}

it('leaves no raw data key in the session when the key store refuses the write', function (): void {
    $session = sessionProofStore();
    $raw = random_bytes(32);

    $manager = new LockStateManager(sessionProofCustodian(null));

    // Asserted after the call rather than around it, so that a regression fails
    // on the key being in the payload -- the defect -- rather than on the
    // absence of a throw, which is only how this one is prevented.
    $refused = false;

    try {
        $manager->unlock($session, $raw);
    } catch (KeyCustodyRefused) {
        $refused = true;
    }

    // Searched in the serialized payload, not only at the key the manager would
    // have used: the payload is what the session driver writes to disk, and the
    // bytes being anywhere in it is the whole defect.
    expect(str_contains(serialize($session->all()), $raw))->toBeFalse(
        'The app-lock data key reached the session payload after the platform key store refused to '
        .'hold it. That payload outlives the process, beside the ledger this key is the root wrap of.'
    );

    expect($refused)->toBeTrue('A refused write has to reach the caller as a refusal it can act on.');

    expect($session->get(LockStateManager::DATA_KEY_SESSION))->toBeNull()
        ->and($session->has(LockStateManager::SESSION_KEY))->toBeFalse(
            'The lock flag was cleared by an unlock that then failed, leaving a session that reads '
            .'unlocked with no key behind it.'
        );
});

// The positive control: without it, an unlock() that silently wrote nothing at
// all would pass the case above and read as a fix.
it('still puts an opaque handle in the session when the key store accepts the write', function (): void {
    $session = sessionProofStore();
    $raw = random_bytes(32);

    (new LockStateManager(sessionProofCustodian('cipher-the-shell-returned')))->unlock($session, $raw);

    $handle = $session->get(LockStateManager::DATA_KEY_SESSION);

    expect($handle)->toBeString()
        ->and($handle)->not->toBe($raw)
        ->and($handle)->toStartWith('nativephp:safestorage:v1:')
        ->and(str_contains(serialize($session->all()), $raw))->toBeFalse()
        ->and($session->get(LockStateManager::SESSION_KEY))->toBeFalse();
});
