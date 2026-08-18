<?php

declare(strict_types=1);

use Illuminate\Contracts\Session\Session;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Sync\Internal\Identity\DeviceIdentityDto;
use Modules\Sync\Internal\Identity\DeviceIdentityService;
use Modules\Sync\Internal\Pairing\PairingState;
use Modules\Sync\Internal\Pairing\PairingTokenService;
use Modules\Sync\Internal\Pairing\SafetyNumberDeriver;
use Modules\Sync\Public\Services\DeviceRegistryService;
use Modules\Sync\Public\Services\PairingGateway;
use Modules\Sync\Tests\Support\CrossDevicePairingHarness;

uses(RefreshDatabase::class);
uses(CrossDevicePairingHarness::class);

/*
 * Sealing-key (X25519) binding in the PAIR_CONFIRM signature.
 *
 * The safety-number ceremony authenticates only the two devices' Ed25519
 * IDENTITY keys. Each device's X25519 SEALING key is an independent keypair,
 * and the responder's X25519 reaches the initiator (the GDK holder) inside an
 * UNSIGNED PAIR_RESPONDER_ACCEPT frame carried by the relay. A malicious relay
 * can therefore keep the responder's Ed25519 identity — so the safety words
 * still MATCH on both screens — while swapping only its X25519 to a key the
 * relay controls. The initiator would then seal every GDK epoch key to the
 * attacker's X25519 on the confirmed-device fan-out, defeating the
 * zero-knowledge-relay invariant that protects all encrypted financial data.
 *
 * The fix binds both devices' X25519 keys into the Ed25519-signed PAIR_CONFIRM
 * message (PairingFrame::confirmSigningMessage), transitively authenticating
 * the sealing key under the identity the human verified. These tests drive the
 * REAL courier seam (PairingGateway::sendConfirm / drainPairingFrames) over the
 * fake ZK relay across two genuinely separate databases, so they never
 * hard-code the signing-message shape — reverting the production binding flips
 * the ATTACK case from rejected back to admitted.
 *
 * The two cases are a differential: identical end to end EXCEPT the single
 * X25519 the desktop binds for the responder (attacker's vs the phone's real
 * one), isolating the sealing-key swap as the sole cause of the rejection.
 */

const PSKB_DESKTOP_USER_ID = 7101;

const PSKB_PHONE_USER_ID = 7102;

function pskbSetUpIdentity(int $userId): DeviceIdentityDto
{
    /** @var Session $session */
    $session = app(Session::class);
    /** @var DeviceIdentityService $service */
    $service = app(DeviceIdentityService::class);

    return $service->generateAndPersist($userId, $session);
}

/**
 * Drive the shared handshake up to the point the desktop binds the responder's
 * X25519, with $responderKxAsSeenByDesktop standing in for whatever the
 * (possibly malicious) relay delivered in the accept frame; everything else
 * uses each device's real key material. Returns the desktop-side token id.
 *
 * $on is a test-bound connection switch — `fn ($conn, $fn) => $this->asDevice(
 * $conn, $fn)` — so this stays a plain helper while the harness's protected
 * asDevice() is only ever invoked from the test's own bound closure.
 */
function pskbHandshakeUntilResponderBound(
    Closure $on,
    DeviceIdentityDto $desktop,
    DeviceIdentityDto $phone,
    string $responderKxAsSeenByDesktop,
): string {
    $issuedToken = $on('desktop', fn () => app(PairingTokenService::class)->issue(
        PSKB_DESKTOP_USER_ID,
        $desktop->deviceId,
        $desktop->ed25519PublicKeyHex,
        $desktop->x25519PublicKeyHex,
    ));
    $tokenHash = hash('sha256', $issuedToken);

    // PHONE seeds its own row from the physically-scanned QR (the real desktop
    // identity, which the relay cannot alter) and accepts, binding its OWN real
    // responder identity — what it will later sign its confirm with.
    $on('phone', function () use ($desktop, $phone, $issuedToken): void {
        $service = app(PairingTokenService::class);
        $service->seedFromInitiator(PSKB_PHONE_USER_ID, $desktop->deviceId, $desktop->ed25519PublicKeyHex, $desktop->x25519PublicKeyHex, $issuedToken);
        $service->accept($issuedToken, PSKB_PHONE_USER_ID, $phone->deviceId, $phone->ed25519PublicKeyHex, $phone->x25519PublicKeyHex);
    });

    // DESKTOP applies the responder-accept AS THE RELAY DELIVERED IT: a
    // malicious relay keeps the phone's real Ed25519 (so the safety words match)
    // but substitutes the X25519; an honest relay passes the phone's real one.
    // applyResponderAccept() is exactly what the courier's drain calls — this
    // mirrors CrossDevicePairingConfirmTest's convention for a relay MITM.
    $on('desktop', fn () => app(PairingTokenService::class)->applyResponderAccept(
        PSKB_DESKTOP_USER_ID,
        $tokenHash,
        $phone->deviceId,
        $phone->ed25519PublicKeyHex,
        $responderKxAsSeenByDesktop,
    ));

    return $on('desktop', function () use ($tokenHash): string {
        /** @var DatabaseManager $db */
        $db = app(DatabaseManager::class);
        $id = $db->connection()->table('pairing_tokens')->where('token_hash', $tokenHash)->value('id');

        return (string) (is_numeric($id) ? (int) $id : 0);
    });
}

afterEach(function (): void {
    $this->crossDevicePairingTearDown();
});

it('rejects the both-confirm when a malicious relay keeps the responder Ed25519 but swaps its X25519 — the swapped device is never admitted or sealed to', function (): void {
    $this->crossDevicePairingSetUp();

    $on = fn (string $conn, Closure $fn): mixed => $this->asDevice($conn, $fn);

    $desktop = $on('desktop', fn () => pskbSetUpIdentity(PSKB_DESKTOP_USER_ID));
    $phone = $on('phone', fn () => pskbSetUpIdentity(PSKB_PHONE_USER_ID));

    // The relay-controlled sealing key: a real, well-formed X25519 public key
    // the attacker holds the secret for (so box_seal to it would be openable).
    $attackerKxHex = sodium_bin2hex(sodium_crypto_box_publickey(sodium_crypto_box_keypair()));

    $desktopTokenId = pskbHandshakeUntilResponderBound($on, $desktop, $phone, $attackerKxHex);

    // The attack's whole point: the swap is INVISIBLE to the human, because the
    // safety words derive from the (unchanged) Ed25519 identities alone.
    $desktopWords = $on('desktop', fn (): array => app(SafetyNumberDeriver::class)
        ->deriveWords($desktop->ed25519PublicKeyHex, $phone->ed25519PublicKeyHex));
    $phoneWords = $on('phone', fn (): array => app(SafetyNumberDeriver::class)
        ->deriveWords($desktop->ed25519PublicKeyHex, $phone->ed25519PublicKeyHex));
    expect($desktopWords)->toBe($phoneWords, 'the X25519 swap leaves the safety words identical — the human gate cannot catch it, so the confirm signature must');

    // Both humans confirm (words matched). The PHONE signs and sends its real
    // PAIR_CONFIRM over the courier — committing to its REAL X25519.
    $on('desktop', fn () => app(PairingTokenService::class)
        ->confirm((int) $desktopTokenId, PSKB_DESKTOP_USER_ID, $desktop->deviceId));
    $on('phone', function () use ($desktop, $phone): void {
        /** @var DatabaseManager $db */
        $db = app(DatabaseManager::class);
        $phoneTokenId = (int) $db->connection()->table('pairing_tokens')->value('id');
        app(PairingTokenService::class)->confirm($phoneTokenId, PSKB_PHONE_USER_ID, $phone->deviceId);

        /** @var Session $session */
        $session = app(Session::class);
        app(PairingGateway::class)->sendConfirm(PSKB_PHONE_USER_ID, $phoneTokenId, $desktop->deviceId, $session);
    });

    // DESKTOP drains and applies the phone's confirm. The desktop reconstructs
    // the signed message with the ATTACKER X25519 it bound, while the phone
    // signed over its REAL X25519 — the signatures diverge, so it is rejected.
    $on('desktop', fn () => app(PairingGateway::class)->drainPairingFrames(PSKB_DESKTOP_USER_ID));

    // The trust decision never completes: the row stays AWAITING_CONFIRM, the
    // peer column is unset, and the phone is NOT admitted — so no GDK epoch key
    // is ever sealed to the attacker's X25519.
    $on('desktop', function () use ($phone): void {
        /** @var DatabaseManager $db */
        $db = app(DatabaseManager::class);
        $row = $db->connection()->table('pairing_tokens')->first();

        expect($row->responder_confirmed_at)->toBeNull()
            ->and($row->state)->toBe(PairingState::AwaitingConfirm->value);
        expect(app(DeviceRegistryService::class)->deviceKeys(PSKB_DESKTOP_USER_ID))
            ->not->toHaveKey($phone->deviceId);
    });
});

it('control: the identical handshake with the responder X25519 UNCHANGED completes and admits the peer — proving the binding does not break honest pairing', function (): void {
    $this->crossDevicePairingSetUp();

    $on = fn (string $conn, Closure $fn): mixed => $this->asDevice($conn, $fn);

    $desktop = $on('desktop', fn () => pskbSetUpIdentity(PSKB_DESKTOP_USER_ID));
    $phone = $on('phone', fn () => pskbSetUpIdentity(PSKB_PHONE_USER_ID));

    // The ONLY difference from the attack case: the desktop binds the phone's
    // REAL X25519, exactly as an honest relay would deliver it.
    $desktopTokenId = pskbHandshakeUntilResponderBound($on, $desktop, $phone, $phone->x25519PublicKeyHex);

    $on('desktop', fn () => app(PairingTokenService::class)
        ->confirm((int) $desktopTokenId, PSKB_DESKTOP_USER_ID, $desktop->deviceId));
    $on('phone', function () use ($desktop, $phone): void {
        /** @var DatabaseManager $db */
        $db = app(DatabaseManager::class);
        $phoneTokenId = (int) $db->connection()->table('pairing_tokens')->value('id');
        app(PairingTokenService::class)->confirm($phoneTokenId, PSKB_PHONE_USER_ID, $phone->deviceId);

        /** @var Session $session */
        $session = app(Session::class);
        app(PairingGateway::class)->sendConfirm(PSKB_PHONE_USER_ID, $phoneTokenId, $desktop->deviceId, $session);
    });

    $on('desktop', fn () => app(PairingGateway::class)->drainPairingFrames(PSKB_DESKTOP_USER_ID));

    $on('desktop', function () use ($phone): void {
        /** @var DatabaseManager $db */
        $db = app(DatabaseManager::class);
        $row = $db->connection()->table('pairing_tokens')->first();

        expect($row->state)->toBe(PairingState::Confirmed->value);
        expect(app(DeviceRegistryService::class)->deviceKeys(PSKB_DESKTOP_USER_ID))
            ->toHaveKey($phone->deviceId);
    });
});
