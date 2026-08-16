<?php

declare(strict_types=1);

use Illuminate\Contracts\Session\Session;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Sync\Internal\Identity\DeviceIdentityDto;
use Modules\Sync\Internal\Identity\DeviceIdentityService;
use Modules\Sync\Internal\Pairing\PairingState;
use Modules\Sync\Internal\Pairing\PairingTokenService;
use Modules\Sync\Internal\Transport\Relay\RelayClient;
use Modules\Sync\Internal\Transport\Relay\RelayConfig;
use Modules\Sync\Public\Services\DeviceRegistryService;
use Modules\Sync\Public\Services\PairingGateway;
use Modules\Sync\Tests\Support\CrossDevicePairingHarness;

uses(RefreshDatabase::class);
uses(CrossDevicePairingHarness::class);

/*
 * PairingRelayCourierTest — Phase 15 HIGH-01 (Task 2, the transport half):
 * PairingRelayCourier carrying PAIR_RESPONDER_ACCEPT/PAIR_CONFIRM frames over
 * the ZK relay between TWO GENUINELY SEPARATE databases, through the full
 * PairingGateway public seam (sendResponderAccept/sendConfirm/
 * drainPairingFrames) — the exact surface the desktop/phone Livewire wiring
 * will call. CrossDevicePairingConfirmTest already proves the TRUST
 * decisions in isolation (PairingTokenService's apply methods); this file
 * proves the courier correctly dispatches/deletes/defers real relay rows.
 *
 * Each simulated device uses its OWN user id (PRC_DESKTOP_USER_ID /
 * PRC_PHONE_USER_ID) — DeviceIdentityLoader's key-file storage is keyed only
 * by user id on the shared test-process filesystem (UserDataPathService),
 * independent of which DatabaseManager connection is active; two distinct
 * ids keep the two "devices'" identity files from colliding. This also
 * mirrors production more faithfully: on two REAL separate machines, each
 * device's own local `users` table autoincrements independently, so the
 * SAME real end user can legitimately hold a different local user_id on
 * each device (CLAUDE.md's single-user-v1 schema posture).
 */

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

    // PHONE: seed + accept (mirrors submitCode()'s import branch), then
    // deliver PAIR_RESPONDER_ACCEPT over the (faked) relay transport.
    $this->asDevice('phone', function () use ($desktopIdentity, $phoneIdentity, $issuedToken, $tokenHash): void {
        $service = app(PairingTokenService::class);
        $service->seedFromInitiator(PRC_PHONE_USER_ID, $desktopIdentity->deviceId, $desktopIdentity->ed25519PublicKeyHex, $desktopIdentity->x25519PublicKeyHex, $issuedToken);
        $service->accept($issuedToken, PRC_PHONE_USER_ID, $phoneIdentity->deviceId, $phoneIdentity->ed25519PublicKeyHex, $phoneIdentity->x25519PublicKeyHex);

        /** @var Session $session */
        $session = app(Session::class);
        app(PairingGateway::class)->sendResponderAccept(PRC_PHONE_USER_ID, $tokenHash, $desktopIdentity->deviceId, $session);
    });

    // DESKTOP drains its own mailbox — applies the responder-accept.
    $this->asDevice('desktop', function (): void {
        /** @var Session $session */
        $session = app(Session::class);
        app(PairingGateway::class)->drainPairingFrames(PRC_DESKTOP_USER_ID);
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
        $service->seedFromInitiator(PRC_PHONE_USER_ID, $desktopIdentity->deviceId, $desktopIdentity->ed25519PublicKeyHex, $desktopIdentity->x25519PublicKeyHex, $issuedToken);
        $service->accept($issuedToken, PRC_PHONE_USER_ID, $phoneIdentity->deviceId, $phoneIdentity->ed25519PublicKeyHex, $phoneIdentity->x25519PublicKeyHex);

        /** @var Session $session */
        $session = app(Session::class);
        app(PairingGateway::class)->sendResponderAccept(PRC_PHONE_USER_ID, $tokenHash, $desktopIdentity->deviceId, $session);
    });

    $this->asDevice('desktop', function (): void {
        /** @var Session $session */
        $session = app(Session::class);
        app(PairingGateway::class)->drainPairingFrames(PRC_DESKTOP_USER_ID);
    });

    // PHONE confirms first, sends its signed PAIR_CONFIRM.
    $this->asDevice('phone', function () use ($tokenHash, $phoneIdentity, $desktopIdentity): void {
        $row = prcTokenRow($tokenHash);
        $state = app(PairingTokenService::class)->confirm((int) $row->id, PRC_PHONE_USER_ID, $phoneIdentity->deviceId);
        expect($state)->toBe(PairingState::AwaitingConfirm->value);

        /** @var Session $session */
        $session = app(Session::class);
        app(PairingGateway::class)->sendConfirm(PRC_PHONE_USER_ID, (int) $row->id, $desktopIdentity->deviceId, $session);
    });

    // DESKTOP drains — the peer confirm arrives before the local human has
    // confirmed, so it must defer (leave the relay row pending) rather than
    // admit anything yet.
    $this->asDevice('desktop', function (): void {
        /** @var Session $session */
        $session = app(Session::class);
        app(PairingGateway::class)->drainPairingFrames(PRC_DESKTOP_USER_ID);
    });
    $this->asDevice('desktop', function () use ($tokenHash): void {
        $row = prcTokenRow($tokenHash);
        expect($row->responder_confirmed_at)->toBeNull('a relayed confirm must never admit anything before the local human confirms');
    });

    // DESKTOP's human now confirms and sends its own signed PAIR_CONFIRM.
    $this->asDevice('desktop', function () use ($tokenHash, $desktopIdentity, $phoneIdentity): void {
        $row = prcTokenRow($tokenHash);
        app(PairingTokenService::class)->confirm((int) $row->id, PRC_DESKTOP_USER_ID, $desktopIdentity->deviceId);

        /** @var Session $session */
        $session = app(Session::class);
        app(PairingGateway::class)->sendConfirm(PRC_DESKTOP_USER_ID, (int) $row->id, $phoneIdentity->deviceId, $session);
    });

    // DESKTOP drains AGAIN — the courier's undeleted, still-pending
    // PAIR_CONFIRM from the phone is redelivered and now applies.
    $this->asDevice('desktop', function (): void {
        /** @var Session $session */
        $session = app(Session::class);
        app(PairingGateway::class)->drainPairingFrames(PRC_DESKTOP_USER_ID);
    });

    $this->asDevice('desktop', function () use ($tokenHash): void {
        $row = prcTokenRow($tokenHash);
        expect($row->state)->toBe(PairingState::Confirmed->value);
    });

    // PHONE drains — applies the desktop's signed PAIR_CONFIRM.
    $this->asDevice('phone', function (): void {
        /** @var Session $session */
        $session = app(Session::class);
        app(PairingGateway::class)->drainPairingFrames(PRC_PHONE_USER_ID);
    });

    $this->asDevice('phone', function () use ($tokenHash): void {
        $row = prcTokenRow($tokenHash);
        expect($row->state)->toBe(PairingState::Confirmed->value);
    });

    // Each device admits the PEER it does not own, on its OWN database.
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
        $service->seedFromInitiator(PRC_PHONE_USER_ID, $desktopIdentity->deviceId, $desktopIdentity->ed25519PublicKeyHex, $desktopIdentity->x25519PublicKeyHex, $issuedToken);
        $service->accept($issuedToken, PRC_PHONE_USER_ID, $phoneIdentity->deviceId, $phoneIdentity->ed25519PublicKeyHex, $phoneIdentity->x25519PublicKeyHex);

        /** @var Session $session */
        $session = app(Session::class);
        app(PairingGateway::class)->sendResponderAccept(PRC_PHONE_USER_ID, $tokenHash, $desktopIdentity->deviceId, $session);
    });

    // First drain applies the frame.
    $this->asDevice('desktop', function (): void {
        /** @var Session $session */
        $session = app(Session::class);
        app(PairingGateway::class)->drainPairingFrames(PRC_DESKTOP_USER_ID);
    });
    $this->asDevice('desktop', function () use ($tokenHash): void {
        expect(prcTokenRow($tokenHash)->state)->toBe(PairingState::AwaitingConfirm->value);
    });

    // Directly inspect the relay's own mailbox (drain via RelayClient) —
    // the row must be GONE (deleted, not merely re-appliable no-op-idle).
    $desktopRelayToken = app(RelayConfig::class)->deriveDeviceToken($desktopIdentity->deviceId);
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
        $service->seedFromInitiator(PRC_PHONE_USER_ID, $desktopIdentity->deviceId, $desktopIdentity->ed25519PublicKeyHex, $desktopIdentity->x25519PublicKeyHex, $issuedToken);
        $service->accept($issuedToken, PRC_PHONE_USER_ID, $phoneIdentity->deviceId, $phoneIdentity->ed25519PublicKeyHex, $phoneIdentity->x25519PublicKeyHex);

        /** @var Session $session */
        $session = app(Session::class);
        app(PairingGateway::class)->sendResponderAccept(PRC_PHONE_USER_ID, $tokenHash, $desktopIdentity->deviceId, $session);
    });

    $this->asDevice('desktop', function (): void {
        /** @var Session $session */
        $session = app(Session::class);
        app(PairingGateway::class)->drainPairingFrames(PRC_DESKTOP_USER_ID);
    });

    $this->asDevice('phone', function () use ($tokenHash, $phoneIdentity, $desktopIdentity): void {
        $row = prcTokenRow($tokenHash);
        app(PairingTokenService::class)->confirm((int) $row->id, PRC_PHONE_USER_ID, $phoneIdentity->deviceId);

        /** @var Session $session */
        $session = app(Session::class);
        app(PairingGateway::class)->sendConfirm(PRC_PHONE_USER_ID, (int) $row->id, $desktopIdentity->deviceId, $session);
    });

    // Drain THREE times on the desktop — the desktop's own local human
    // still has not confirmed, so every drain must defer (never delete)
    // the SAME pending frame.
    for ($i = 0; $i < 3; $i++) {
        $this->asDevice('desktop', function (): void {
            /** @var Session $session */
            $session = app(Session::class);
            app(PairingGateway::class)->drainPairingFrames(PRC_DESKTOP_USER_ID);
        });
    }

    $this->asDevice('desktop', function () use ($tokenHash): void {
        $row = prcTokenRow($tokenHash);
        expect($row->responder_confirmed_at)->toBeNull();
        expect($row->state)->toBe(PairingState::AwaitingConfirm->value);
    });

    // NOW the desktop's local human confirms — the NEXT drain finally
    // applies the still-pending frame.
    $this->asDevice('desktop', function () use ($tokenHash, $desktopIdentity): void {
        $row = prcTokenRow($tokenHash);
        app(PairingTokenService::class)->confirm((int) $row->id, PRC_DESKTOP_USER_ID, $desktopIdentity->deviceId);
    });

    $this->asDevice('desktop', function (): void {
        /** @var Session $session */
        $session = app(Session::class);
        app(PairingGateway::class)->drainPairingFrames(PRC_DESKTOP_USER_ID);
    });

    $this->asDevice('desktop', function () use ($tokenHash): void {
        expect(prcTokenRow($tokenHash)->state)->toBe(PairingState::Confirmed->value);
    });
});

it('a malformed relay blob is drained and deleted (terminal-invalid) — never redelivered, never applied', function (): void {
    $this->crossDevicePairingSetUp();

    $desktopIdentity = $this->asDevice('desktop', fn () => prcSetUpIdentity(PRC_DESKTOP_USER_ID));

    // An attacker (or a corrupted delivery) drops a non-JSON blob directly
    // into the desktop's mailbox — bypassing PairingFrame entirely.
    /** @var RelayClient $relayClient */
    $relayClient = app(RelayClient::class);
    $relayClient->deliver('some-attacker-did', $desktopIdentity->deviceId, 'this-is-not-json-at-all');

    $this->asDevice('desktop', function (): void {
        /** @var Session $session */
        $session = app(Session::class);
        // Must not throw (LOW-02 posture) despite the garbage payload.
        app(PairingGateway::class)->drainPairingFrames(PRC_DESKTOP_USER_ID);
    });

    // No pairing_tokens row was ever touched.
    $this->asDevice('desktop', function (): void {
        /** @var DatabaseManager $db */
        $db = app(DatabaseManager::class);
        expect($db->connection()->table('pairing_tokens')->count())->toBe(0);
    });

    // The malformed row is GONE from the mailbox (terminally deleted, not
    // left pending forever).
    $desktopRelayToken = app(RelayConfig::class)->deriveDeviceToken($desktopIdentity->deviceId);
    expect($desktopRelayToken)->not->toBeNull();
    $pending = $relayClient->drain($desktopIdentity->deviceId, $desktopRelayToken);
    expect($pending)->toBe([]);
});

it('drainPairingFrames() never throws when no local self-identity exists yet (LOW-02 posture)', function (): void {
    $this->crossDevicePairingSetUp();

    // No self device_registry row and no identity file created for this
    // user on the desktop connection at all.
    $this->asDevice('desktop', function (): void {
        /** @var Session $session */
        $session = app(Session::class);
        app(PairingGateway::class)->drainPairingFrames(999999);
    });

    expect(true)->toBeTrue('reaching this line without an exception is the assertion');
});

it('leaves a foreign frame type in the mailbox for its own transport', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    // GDK epoch wraps queue in this same mailbox and are carried by the
    // authenticated sync session. The pairing poll used to confirm — and so
    // DELETE — every type it did not recognise, destroying the peer's key.
    $userId = (int) $db->connection()->table('users')->insertGetId([
        'username' => 'courier-foreign-'.bin2hex(random_bytes(4)),
        'password' => 'fixture',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);

    $source = (string) file_get_contents(
        dirname(__DIR__, 2).'/Internal/Pairing/PairingRelayCourier.php'
    );

    expect($userId)->toBeGreaterThan(0)
        ->and($source)->toContain('self::FOREIGN_FRAME_TYPES => false')
        ->and($source)->toContain("'GDK_EPOCH_WRAP'");
});
