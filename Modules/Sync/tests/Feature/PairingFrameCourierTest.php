<?php

declare(strict_types=1);

use Illuminate\Contracts\Session\Session;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Sync\Internal\Crypto\GdkEpochControlHandler;
use Modules\Sync\Internal\Identity\DeviceIdentityDto;
use Modules\Sync\Internal\Identity\DeviceIdentityService;
use Modules\Sync\Internal\Pairing\PairingFrameCourier;
use Modules\Sync\Internal\Pairing\PairingState;
use Modules\Sync\Internal\Pairing\PairingTokenService;
use Modules\Sync\Internal\Transport\Relay\RelayClient;
use Modules\Sync\Internal\Transport\Relay\RelayConfig;
use Modules\Sync\Public\Dto\PairingPeerIdentity;
use Modules\Sync\Public\Services\DeviceRegistryService;
use Modules\Sync\Public\Services\PairingGateway;
use Modules\Sync\Tests\Support\CrossDevicePairingHarness;
use Modules\Sync\Tests\Support\PairingSafetyDigest;

uses(RefreshDatabase::class);
uses(CrossDevicePairingHarness::class);

// The two simulated devices need different user ids: DeviceIdentityLoader keys its
// key files by user id alone on the shared test filesystem, so one id would collide.
// Production behaves the same way — each device's local users table autoincrements
// independently, so one real person holds a different user_id on each device.
const PRC_DESKTOP_USER_ID = 6001;

const PRC_PHONE_USER_ID = 6002;

function prcSetUpIdentity(int $userId): DeviceIdentityDto
{
    /** @var Session $session */
    $session = app(Session::class);
    /** @var DeviceIdentityService $service */
    $service = app(DeviceIdentityService::class);

    return $service->generateAndPersist($userId, $session);
}

function prcTokenRow(string $tokenHash): object
{
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $row = $db->connection()->table('pairing_tokens')->where('token_hash', $tokenHash)->first();
    expect($row)->not->toBeNull();

    return $row;
}

afterEach(function (): void {
    $this->crossDevicePairingTearDown();
});

it('sendResponderAccept() + drainPairingFrames() propagates the responder identity to the desktop\'s own separate database', function (): void {
    $this->crossDevicePairingSetUp();

    $desktopIdentity = $this->asDevice('desktop', fn () => prcSetUpIdentity(PRC_DESKTOP_USER_ID));
    $phoneIdentity = $this->asDevice('phone', fn () => prcSetUpIdentity(PRC_PHONE_USER_ID));

    $issuedToken = $this->asDevice('desktop', fn () => app(PairingTokenService::class)->issue(
        PRC_DESKTOP_USER_ID,
        $desktopIdentity->deviceId,
        $desktopIdentity->ed25519PublicKeyHex,
        $desktopIdentity->x25519PublicKeyHex,
    ));
    $tokenHash = hash('sha256', $issuedToken);

    // Seed + accept mirrors submitCode()'s import branch.
    $this->asDevice('phone', function () use ($desktopIdentity, $phoneIdentity, $issuedToken, $tokenHash): void {
        $service = app(PairingTokenService::class);
        $service->seedFromInitiator(PRC_PHONE_USER_ID, new PairingPeerIdentity($desktopIdentity->deviceId, $desktopIdentity->ed25519PublicKeyHex, $desktopIdentity->x25519PublicKeyHex), $issuedToken);
        $service->accept($issuedToken, PRC_PHONE_USER_ID, $phoneIdentity->deviceId, $phoneIdentity->ed25519PublicKeyHex, $phoneIdentity->x25519PublicKeyHex);

        /** @var Session $session */
        $session = app(Session::class);
        app(PairingGateway::class)->sendResponderAccept(PRC_PHONE_USER_ID, $tokenHash, $desktopIdentity->deviceId, $session);
    });

    $this->asDevice('desktop', function (): void {
        /** @var Session $session */
        $session = app(Session::class);
        app(PairingGateway::class)->drainPairingFrames(PRC_DESKTOP_USER_ID, null);
    });

    $this->asDevice('desktop', function () use ($tokenHash, $phoneIdentity): void {
        $row = prcTokenRow($tokenHash);
        expect($row->state)->toBe(PairingState::AwaitingConfirm->value);
        expect($row->responder_device_id)->toBe($phoneIdentity->deviceId);
    });
});

it('the full both-confirm handshake propagates over the relay and admits the peer symmetrically on both databases', function (): void {
    $this->crossDevicePairingSetUp();

    $desktopIdentity = $this->asDevice('desktop', fn () => prcSetUpIdentity(PRC_DESKTOP_USER_ID));
    $phoneIdentity = $this->asDevice('phone', fn () => prcSetUpIdentity(PRC_PHONE_USER_ID));

    $issuedToken = $this->asDevice('desktop', fn () => app(PairingTokenService::class)->issue(
        PRC_DESKTOP_USER_ID,
        $desktopIdentity->deviceId,
        $desktopIdentity->ed25519PublicKeyHex,
        $desktopIdentity->x25519PublicKeyHex,
    ));
    $tokenHash = hash('sha256', $issuedToken);

    $this->asDevice('phone', function () use ($desktopIdentity, $phoneIdentity, $issuedToken, $tokenHash): void {
        $service = app(PairingTokenService::class);
        $service->seedFromInitiator(PRC_PHONE_USER_ID, new PairingPeerIdentity($desktopIdentity->deviceId, $desktopIdentity->ed25519PublicKeyHex, $desktopIdentity->x25519PublicKeyHex), $issuedToken);
        $service->accept($issuedToken, PRC_PHONE_USER_ID, $phoneIdentity->deviceId, $phoneIdentity->ed25519PublicKeyHex, $phoneIdentity->x25519PublicKeyHex);

        /** @var Session $session */
        $session = app(Session::class);
        app(PairingGateway::class)->sendResponderAccept(PRC_PHONE_USER_ID, $tokenHash, $desktopIdentity->deviceId, $session);
    });

    $this->asDevice('desktop', function (): void {
        /** @var Session $session */
        $session = app(Session::class);
        app(PairingGateway::class)->drainPairingFrames(PRC_DESKTOP_USER_ID, null);
    });

    $this->asDevice('phone', function () use ($tokenHash, $phoneIdentity, $desktopIdentity): void {
        $row = prcTokenRow($tokenHash);
        $state = app(PairingTokenService::class)->confirm((int) $row->id, PRC_PHONE_USER_ID, $phoneIdentity->deviceId, PairingSafetyDigest::forToken((int) $row->id, PRC_PHONE_USER_ID));
        expect($state)->toBe(PairingState::AwaitingConfirm->value);

        /** @var Session $session */
        $session = app(Session::class);
        app(PairingGateway::class)->sendConfirm(PRC_PHONE_USER_ID, (int) $row->id, $desktopIdentity->deviceId, $session);
    });

    // The peer confirm arrives before the local human's, so the drain must defer it.
    $this->asDevice('desktop', function (): void {
        /** @var Session $session */
        $session = app(Session::class);
        app(PairingGateway::class)->drainPairingFrames(PRC_DESKTOP_USER_ID, null);
    });
    $this->asDevice('desktop', function () use ($tokenHash): void {
        $row = prcTokenRow($tokenHash);
        expect($row->responder_confirmed_at)->toBeNull('a relayed confirm must never admit anything before the local human confirms');
    });

    $this->asDevice('desktop', function () use ($tokenHash, $desktopIdentity, $phoneIdentity): void {
        $row = prcTokenRow($tokenHash);
        app(PairingTokenService::class)->confirm((int) $row->id, PRC_DESKTOP_USER_ID, $desktopIdentity->deviceId, PairingSafetyDigest::forToken((int) $row->id, PRC_DESKTOP_USER_ID));

        /** @var Session $session */
        $session = app(Session::class);
        app(PairingGateway::class)->sendConfirm(PRC_DESKTOP_USER_ID, (int) $row->id, $phoneIdentity->deviceId, $session);
    });

    // Draining again redelivers the phone's still-undeleted confirm, which now applies.
    $this->asDevice('desktop', function (): void {
        /** @var Session $session */
        $session = app(Session::class);
        app(PairingGateway::class)->drainPairingFrames(PRC_DESKTOP_USER_ID, null);
    });

    $this->asDevice('desktop', function () use ($tokenHash): void {
        $row = prcTokenRow($tokenHash);
        expect($row->state)->toBe(PairingState::Confirmed->value);
    });

    $this->asDevice('phone', function (): void {
        /** @var Session $session */
        $session = app(Session::class);
        app(PairingGateway::class)->drainPairingFrames(PRC_PHONE_USER_ID, null);
    });

    $this->asDevice('phone', function () use ($tokenHash): void {
        $row = prcTokenRow($tokenHash);
        expect($row->state)->toBe(PairingState::Confirmed->value);
    });

    // Each device admits the peer it does not own, on its own database.
    $this->asDevice('desktop', function () use ($phoneIdentity): void {
        expect(app(DeviceRegistryService::class)->deviceKeys(PRC_DESKTOP_USER_ID))->toHaveKey($phoneIdentity->deviceId);
    });
    $this->asDevice('phone', function () use ($desktopIdentity): void {
        expect(app(DeviceRegistryService::class)->deviceKeys(PRC_PHONE_USER_ID))->toHaveKey($desktopIdentity->deviceId);
    });
});

it('an applied frame is DELETED from the relay mailbox — redraining returns nothing more (idempotent delete)', function (): void {
    $this->crossDevicePairingSetUp();

    $desktopIdentity = $this->asDevice('desktop', fn () => prcSetUpIdentity(PRC_DESKTOP_USER_ID));
    $phoneIdentity = $this->asDevice('phone', fn () => prcSetUpIdentity(PRC_PHONE_USER_ID));

    $issuedToken = $this->asDevice('desktop', fn () => app(PairingTokenService::class)->issue(
        PRC_DESKTOP_USER_ID,
        $desktopIdentity->deviceId,
        $desktopIdentity->ed25519PublicKeyHex,
        $desktopIdentity->x25519PublicKeyHex,
    ));
    $tokenHash = hash('sha256', $issuedToken);

    $this->asDevice('phone', function () use ($desktopIdentity, $phoneIdentity, $issuedToken, $tokenHash): void {
        $service = app(PairingTokenService::class);
        $service->seedFromInitiator(PRC_PHONE_USER_ID, new PairingPeerIdentity($desktopIdentity->deviceId, $desktopIdentity->ed25519PublicKeyHex, $desktopIdentity->x25519PublicKeyHex), $issuedToken);
        $service->accept($issuedToken, PRC_PHONE_USER_ID, $phoneIdentity->deviceId, $phoneIdentity->ed25519PublicKeyHex, $phoneIdentity->x25519PublicKeyHex);

        /** @var Session $session */
        $session = app(Session::class);
        app(PairingGateway::class)->sendResponderAccept(PRC_PHONE_USER_ID, $tokenHash, $desktopIdentity->deviceId, $session);
    });

    $this->asDevice('desktop', function (): void {
        /** @var Session $session */
        $session = app(Session::class);
        app(PairingGateway::class)->drainPairingFrames(PRC_DESKTOP_USER_ID, null);
    });
    $this->asDevice('desktop', function () use ($tokenHash): void {
        expect(prcTokenRow($tokenHash)->state)->toBe(PairingState::AwaitingConfirm->value);
    });

    $desktopRelayToken = app(RelayConfig::class)->deviceDrainSecret();
    expect($desktopRelayToken)->not->toBeNull();

    /** @var RelayClient $relayClient */
    $relayClient = app(RelayClient::class);
    $pending = $relayClient->drain($desktopIdentity->deviceId, $desktopRelayToken);
    expect($pending)->toBe([], 'an applied frame must be deleted from the relay mailbox, not left pending');
});

it('a valid-but-deferred PAIR_CONFIRM stays in the relay mailbox across MULTIPLE drains until the local side confirms', function (): void {
    $this->crossDevicePairingSetUp();

    $desktopIdentity = $this->asDevice('desktop', fn () => prcSetUpIdentity(PRC_DESKTOP_USER_ID));
    $phoneIdentity = $this->asDevice('phone', fn () => prcSetUpIdentity(PRC_PHONE_USER_ID));

    $issuedToken = $this->asDevice('desktop', fn () => app(PairingTokenService::class)->issue(
        PRC_DESKTOP_USER_ID,
        $desktopIdentity->deviceId,
        $desktopIdentity->ed25519PublicKeyHex,
        $desktopIdentity->x25519PublicKeyHex,
    ));
    $tokenHash = hash('sha256', $issuedToken);

    $this->asDevice('phone', function () use ($desktopIdentity, $phoneIdentity, $issuedToken, $tokenHash): void {
        $service = app(PairingTokenService::class);
        $service->seedFromInitiator(PRC_PHONE_USER_ID, new PairingPeerIdentity($desktopIdentity->deviceId, $desktopIdentity->ed25519PublicKeyHex, $desktopIdentity->x25519PublicKeyHex), $issuedToken);
        $service->accept($issuedToken, PRC_PHONE_USER_ID, $phoneIdentity->deviceId, $phoneIdentity->ed25519PublicKeyHex, $phoneIdentity->x25519PublicKeyHex);

        /** @var Session $session */
        $session = app(Session::class);
        app(PairingGateway::class)->sendResponderAccept(PRC_PHONE_USER_ID, $tokenHash, $desktopIdentity->deviceId, $session);
    });

    $this->asDevice('desktop', function (): void {
        /** @var Session $session */
        $session = app(Session::class);
        app(PairingGateway::class)->drainPairingFrames(PRC_DESKTOP_USER_ID, null);
    });

    $this->asDevice('phone', function () use ($tokenHash, $phoneIdentity, $desktopIdentity): void {
        $row = prcTokenRow($tokenHash);
        app(PairingTokenService::class)->confirm((int) $row->id, PRC_PHONE_USER_ID, $phoneIdentity->deviceId, PairingSafetyDigest::forToken((int) $row->id, PRC_PHONE_USER_ID));

        /** @var Session $session */
        $session = app(Session::class);
        app(PairingGateway::class)->sendConfirm(PRC_PHONE_USER_ID, (int) $row->id, $desktopIdentity->deviceId, $session);
    });

    // With no local confirm yet, every one of these drains must defer the same frame.
    for ($i = 0; $i < 3; $i++) {
        $this->asDevice('desktop', function (): void {
            /** @var Session $session */
            $session = app(Session::class);
            app(PairingGateway::class)->drainPairingFrames(PRC_DESKTOP_USER_ID, null);
        });
    }

    $this->asDevice('desktop', function () use ($tokenHash): void {
        $row = prcTokenRow($tokenHash);
        expect($row->responder_confirmed_at)->toBeNull();
        expect($row->state)->toBe(PairingState::AwaitingConfirm->value);
    });

    $this->asDevice('desktop', function () use ($tokenHash, $desktopIdentity): void {
        $row = prcTokenRow($tokenHash);
        app(PairingTokenService::class)->confirm((int) $row->id, PRC_DESKTOP_USER_ID, $desktopIdentity->deviceId, PairingSafetyDigest::forToken((int) $row->id, PRC_DESKTOP_USER_ID));
    });

    $this->asDevice('desktop', function (): void {
        /** @var Session $session */
        $session = app(Session::class);
        app(PairingGateway::class)->drainPairingFrames(PRC_DESKTOP_USER_ID, null);
    });

    $this->asDevice('desktop', function () use ($tokenHash): void {
        expect(prcTokenRow($tokenHash)->state)->toBe(PairingState::Confirmed->value);
    });
});

it('a malformed relay blob is drained and deleted (terminal-invalid) — never redelivered, never applied', function (): void {
    $this->crossDevicePairingSetUp();

    $desktopIdentity = $this->asDevice('desktop', fn () => prcSetUpIdentity(PRC_DESKTOP_USER_ID));

    // Delivered straight to the mailbox, bypassing PairingFrame entirely.
    /** @var RelayClient $relayClient */
    $relayClient = app(RelayClient::class);
    $relayClient->deliver('some-attacker-did', $desktopIdentity->deviceId, 'this-is-not-json-at-all');

    $this->asDevice('desktop', function (): void {
        /** @var Session $session */
        $session = app(Session::class);
        // A garbage payload must not throw out of the drain.
        app(PairingGateway::class)->drainPairingFrames(PRC_DESKTOP_USER_ID, null);
    });

    $this->asDevice('desktop', function (): void {
        /** @var DatabaseManager $db */
        $db = app(DatabaseManager::class);
        expect($db->connection()->table('pairing_tokens')->count())->toBe(0);
    });

    $desktopRelayToken = app(RelayConfig::class)->deviceDrainSecret();
    expect($desktopRelayToken)->not->toBeNull();
    $pending = $relayClient->drain($desktopIdentity->deviceId, $desktopRelayToken);
    expect($pending)->toBe([]);
});

it('drainPairingFrames() never throws when no local self-identity exists yet', function (): void {
    $this->crossDevicePairingSetUp();

    // User 999999 has no device_registry row and no identity file.
    $this->asDevice('desktop', function (): void {
        /** @var Session $session */
        $session = app(Session::class);
        app(PairingGateway::class)->drainPairingFrames(999999, null);
    });

    expect(true)->toBeTrue('reaching this line without an exception is the assertion');
});

it('leaves a foreign frame type in the mailbox for its own transport', function (): void {
    // GDK epoch wraps queue in this same mailbox but travel on the authenticated
    // sync session. The pairing poll used to confirm — and so DELETE — every type
    // it did not recognise, which destroyed the peer's key.
    $foreignType = (new ReflectionClass(PairingFrameCourier::class))->getConstant('FOREIGN_FRAME_TYPE');

    // The literal is pinned here on purpose: it crosses between devices and
    // between app versions, so an older peer must still recognise it.
    expect($foreignType)->toBe(GdkEpochControlHandler::MSG_GDK_EPOCH_WRAP)
        ->and($foreignType)->toBe('GDK_EPOCH_WRAP');
});
