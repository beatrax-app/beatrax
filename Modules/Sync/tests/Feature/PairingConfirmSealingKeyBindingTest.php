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

// The safety words authenticate the Ed25519 identities only, and the sealing
// key is a separate keypair arriving in an unsigned frame. A relay could keep
// the real identity, so both screens still match, and swap the key every group
// key gets sealed to. Binding it into the signed confirm closes that.

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

// $responderKxAsSeenByDesktop stands in for whatever the relay delivered;
// everything else is each device's real key material. $on is a connection
// switch bound by the caller, so the harness's protected asDevice() is only
// ever invoked from the test's own closure.
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

    // The phone seeds from the physically-scanned QR, which the relay cannot
    // touch, and binds its own real identity to sign its confirm with.
    $on('phone', function () use ($desktop, $phone, $issuedToken): void {
        $service = app(PairingTokenService::class);
        $service->seedFromInitiator(PSKB_PHONE_USER_ID, $desktop->deviceId, $desktop->ed25519PublicKeyHex, $desktop->x25519PublicKeyHex, $issuedToken);
        $service->accept($issuedToken, PSKB_PHONE_USER_ID, $phone->deviceId, $phone->ed25519PublicKeyHex, $phone->x25519PublicKeyHex);
    });

    // The desktop applies the accept exactly as the relay delivered it, through
    // the same call the courier's drain makes.
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

    // A well-formed X25519 public key the attacker holds the secret for, so a
    // sealed box addressed to it would genuinely open.
    $attackerKxHex = sodium_bin2hex(sodium_crypto_box_publickey(sodium_crypto_box_keypair()));

    $desktopTokenId = pskbHandshakeUntilResponderBound($on, $desktop, $phone, $attackerKxHex);

    // The swap is invisible to the human: the words derive from the unchanged
    // Ed25519 identities alone.
    $desktopWords = $on('desktop', fn (): array => app(SafetyNumberDeriver::class)
        ->deriveWords($desktop->ed25519PublicKeyHex, $phone->ed25519PublicKeyHex));
    $phoneWords = $on('phone', fn (): array => app(SafetyNumberDeriver::class)
        ->deriveWords($desktop->ed25519PublicKeyHex, $phone->ed25519PublicKeyHex));
    expect($desktopWords)->toBe($phoneWords, 'the X25519 swap leaves the safety words identical — the human gate cannot catch it, so the confirm signature must');

    // Both humans confirm, and the phone signs over its real sealing key.
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

    // The desktop reconstructs the signed message with the key it bound while
    // the phone signed over its own, so the two diverge and it is rejected.
    $on('desktop', fn () => app(PairingGateway::class)->drainPairingFrames(PSKB_DESKTOP_USER_ID));

    // The trust decision never completes, so no group key is ever sealed to the
    // attacker's key.
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

    // The only difference from the attack case: the real key, as an honest
    // relay would deliver it.
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
