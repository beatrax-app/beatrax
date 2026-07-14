<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Sync\Internal\Pairing\PairingFrame;
use Modules\Sync\Internal\Pairing\PairingStateMachine;
use Modules\Sync\Internal\Pairing\PairingTokenService;
use Modules\Sync\Internal\Pairing\SafetyNumberDeriver;
use Modules\Sync\Internal\Signing\DeviceKeySigner;
use Modules\Sync\Public\Services\DeviceRegistryService;
use Modules\Sync\Tests\Support\CrossDevicePairingHarness;

uses(RefreshDatabase::class);
uses(CrossDevicePairingHarness::class);

/*
 * CrossDevicePairingConfirmTest — Phase 15 HIGH-01 (`15-crossdevice-pairing-
 * PLAN.md` §4, cases 1-7 + case 9). Drives PairingTokenService's cross-device
 * apply methods (`applyResponderAccept()`/`applyPeerConfirm()`) directly
 * against TWO GENUINELY SEPARATE `pairing_tokens`/`device_registry`
 * databases (`CrossDevicePairingHarness`) — the single-DB test convention
 * elsewhere in this suite is exactly what masked this gap (one DB, distinct
 * device_ids on the SAME row can never prove real cross-device propagation).
 *
 * The transport layer (PairingRelayCourier, which delivers these exact
 * frames over the ZK relay) is exercised separately once it lands — this
 * file proves the TRUST DECISIONS the courier will dispatch into are
 * correct in isolation first.
 */

const CDP_USER_ID = 4242;

/**
 * A real Ed25519 (signing) + X25519 (routing-only stub) keypair for one
 * simulated device — real keys so signature verification genuinely
 * succeeds/fails rather than being asserted by construction.
 *
 * @return array{deviceId: string, edPub: string, edSec: string, kxPub: string}
 */
function cdpDevice(string $deviceId): array
{
    $sign = sodium_crypto_sign_keypair();

    return [
        'deviceId' => $deviceId,
        'edPub' => sodium_bin2hex(sodium_crypto_sign_publickey($sign)),
        'edSec' => sodium_bin2hex(sodium_crypto_sign_secretkey($sign)),
        'kxPub' => sodium_bin2hex(sodium_crypto_box_publickey(sodium_crypto_box_keypair())),
    ];
}

/**
 * @param  array{deviceId: string, edPub: string, edSec: string, kxPub: string}  $device
 */
function cdpInsertSelfRow(DatabaseManager $db, array $device): void
{
    $now = '2026-07-14T09:00:00Z';
    $db->connection()->table('device_registry')->insert([
        'user_id' => CDP_USER_ID,
        'device_id' => $device['deviceId'],
        'name' => $device['deviceId'],
        'ed25519_public_key_hex' => $device['edPub'],
        'x25519_public_key_hex' => $device['kxPub'],
        'safety_number_words' => 'self words',
        'is_self' => 1,
        'paired_at' => $now,
        'confirmed_at' => $now,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
}

/**
 * @param  array{deviceId: string, edPub: string, edSec: string, kxPub: string}  $device
 */
function cdpSign(array $device, string $message): string
{
    return (new DeviceKeySigner)->sign($message, sodium_hex2bin($device['edSec']));
}

function cdpTokenRow(string $tokenHash): object
{
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $row = $db->connection()->table('pairing_tokens')->where('token_hash', $tokenHash)->first();
    expect($row)->not->toBeNull();

    return $row;
}

afterEach(function (): void {
    $this->crossDevicePairingTearDown();
    CarbonImmutable::setTestNow();
});

// ---------------------------------------------------------------------
// Case 1 — responder-accept propagation lands on the desktop row.
// ---------------------------------------------------------------------

it('propagates PAIR_RESPONDER_ACCEPT so the desktop row transitions PENDING -> AWAITING_CONFIRM and both sides derive the SAME safety words', function (): void {
    $this->crossDevicePairingSetUp();

    $desktop = cdpDevice('desktop-1');
    $phone = cdpDevice('phone-1');

    $issuedToken = $this->asDevice('desktop', fn () => app(PairingTokenService::class)
        ->issue(CDP_USER_ID, $desktop['deviceId'], $desktop['edPub'], $desktop['kxPub']));
    $tokenHash = hash('sha256', $issuedToken);

    // PHONE: seeds a LOCAL row from the scanned QR's initiator identity, then
    // accepts — binding its OWN responder identity. Mirrors submitCode()'s
    // import branch on a genuinely fresh, separate database.
    $this->asDevice('phone', function () use ($desktop, $phone, $issuedToken): void {
        $service = app(PairingTokenService::class);
        $service->seedFromInitiator(CDP_USER_ID, $desktop['deviceId'], $desktop['edPub'], $desktop['kxPub'], $issuedToken);
        $accepted = $service->accept($issuedToken, CDP_USER_ID, $phone['deviceId'], $phone['edPub'], $phone['kxPub']);
        expect($accepted)->not->toBeFalse();
    });

    // DESKTOP applies the (frame-carried) responder-accept fields.
    $this->asDevice('desktop', function () use ($tokenHash, $phone): void {
        $applied = app(PairingTokenService::class)
            ->applyResponderAccept(CDP_USER_ID, $tokenHash, $phone['deviceId'], $phone['edPub'], $phone['kxPub']);
        expect($applied)->not->toBeFalse();
    });

    $desktopWords = $this->asDevice('desktop', function () use ($tokenHash): array {
        $row = cdpTokenRow($tokenHash);
        expect($row->state)->toBe(PairingStateMachine::AWAITING_CONFIRM);

        return app(SafetyNumberDeriver::class)->deriveWords($row->initiator_ed25519_pub_hex, $row->responder_ed25519_pub_hex);
    });

    $phoneWords = $this->asDevice('phone', function () use ($tokenHash): array {
        $row = cdpTokenRow($tokenHash);

        return app(SafetyNumberDeriver::class)->deriveWords($row->initiator_ed25519_pub_hex, $row->responder_ed25519_pub_hex);
    });

    expect($desktopWords)->toBe($phoneWords);
});

// ---------------------------------------------------------------------
// Case 2 — both-confirm reached only when BOTH sides confirm; symmetric
// admission on each device's OWN database.
// ---------------------------------------------------------------------

it('reaches CONFIRMED on both separate databases only once BOTH sides confirm, admitting the peer symmetrically', function (): void {
    $this->crossDevicePairingSetUp();

    $desktop = cdpDevice('desktop-2');
    $phone = cdpDevice('phone-2');

    $issuedToken = $this->asDevice('desktop', fn () => app(PairingTokenService::class)
        ->issue(CDP_USER_ID, $desktop['deviceId'], $desktop['edPub'], $desktop['kxPub']));
    $tokenHash = hash('sha256', $issuedToken);

    $this->asDevice('desktop', fn () => cdpInsertSelfRow(app(DatabaseManager::class), $desktop));
    $this->asDevice('phone', fn () => cdpInsertSelfRow(app(DatabaseManager::class), $phone));

    $this->asDevice('phone', function () use ($desktop, $phone, $issuedToken): void {
        $service = app(PairingTokenService::class);
        $service->seedFromInitiator(CDP_USER_ID, $desktop['deviceId'], $desktop['edPub'], $desktop['kxPub'], $issuedToken);
        $service->accept($issuedToken, CDP_USER_ID, $phone['deviceId'], $phone['edPub'], $phone['kxPub']);
    });

    $this->asDevice('desktop', fn () => app(PairingTokenService::class)
        ->applyResponderAccept(CDP_USER_ID, $tokenHash, $phone['deviceId'], $phone['edPub'], $phone['kxPub']));

    // PHONE's human confirms first — awaits the peer.
    $this->asDevice('phone', function () use ($tokenHash, $phone): void {
        $row = cdpTokenRow($tokenHash);
        $state = app(PairingTokenService::class)->confirm((int) $row->id, CDP_USER_ID, $phone['deviceId']);
        expect($state)->toBe(PairingStateMachine::AWAITING_CONFIRM);
    });

    // PHONE sends its signed PAIR_CONFIRM to the DESKTOP — the desktop has
    // not confirmed locally yet, so this must DEFER, not admit.
    $sigFromPhone = cdpSign($phone, PairingFrame::confirmSigningMessage($tokenHash, $phone['deviceId'], $desktop['deviceId']));
    $deferredState = $this->asDevice('desktop', fn () => app(PairingTokenService::class)
        ->applyPeerConfirm(CDP_USER_ID, $tokenHash, $phone['deviceId'], $desktop['deviceId'], $sigFromPhone));
    expect($deferredState)->toBe('deferred');

    // DESKTOP's human now confirms too.
    $this->asDevice('desktop', function () use ($tokenHash, $desktop): void {
        $row = cdpTokenRow($tokenHash);
        $state = app(PairingTokenService::class)->confirm((int) $row->id, CDP_USER_ID, $desktop['deviceId']);
        expect($state)->toBe(PairingStateMachine::AWAITING_CONFIRM, 'the peer column is still unset on this row — only the local side confirmed so far');
    });

    // The relay redelivers the SAME (still-pending) PAIR_CONFIRM now that
    // the desktop's local side is confirmed — this is what completes it.
    $desktopFinal = $this->asDevice('desktop', fn () => app(PairingTokenService::class)
        ->applyPeerConfirm(CDP_USER_ID, $tokenHash, $phone['deviceId'], $desktop['deviceId'], $sigFromPhone));
    expect($desktopFinal)->toBe(PairingStateMachine::CONFIRMED);

    // DESKTOP sends its own signed PAIR_CONFIRM to the PHONE.
    $sigFromDesktop = cdpSign($desktop, PairingFrame::confirmSigningMessage($tokenHash, $desktop['deviceId'], $phone['deviceId']));
    $phoneFinal = $this->asDevice('phone', fn () => app(PairingTokenService::class)
        ->applyPeerConfirm(CDP_USER_ID, $tokenHash, $desktop['deviceId'], $phone['deviceId'], $sigFromDesktop));
    expect($phoneFinal)->toBe(PairingStateMachine::CONFIRMED);

    // Each device admits the PEER it does not own, on its OWN database.
    $this->asDevice('desktop', function () use ($phone): void {
        expect(app(DeviceRegistryService::class)->deviceKeys(CDP_USER_ID))->toHaveKey($phone['deviceId']);
    });
    $this->asDevice('phone', function () use ($desktop): void {
        expect(app(DeviceRegistryService::class)->deviceKeys(CDP_USER_ID))->toHaveKey($desktop['deviceId']);
    });
});

// ---------------------------------------------------------------------
// Case 3 — MITM-substituted responder identity: caught by mismatched
// safety words; the REAL phone can never be driven to CONFIRMED.
// ---------------------------------------------------------------------

it('a relay-substituted responder identity yields mismatched safety words; the REAL phone can never reach CONFIRMED via the attacker', function (): void {
    $this->crossDevicePairingSetUp();

    $desktop = cdpDevice('desktop-3');
    $phone = cdpDevice('phone-3');
    $attacker = cdpDevice('attacker-3');

    $issuedToken = $this->asDevice('desktop', fn () => app(PairingTokenService::class)
        ->issue(CDP_USER_ID, $desktop['deviceId'], $desktop['edPub'], $desktop['kxPub']));
    $tokenHash = hash('sha256', $issuedToken);

    $this->asDevice('desktop', fn () => cdpInsertSelfRow(app(DatabaseManager::class), $desktop));
    $this->asDevice('phone', fn () => cdpInsertSelfRow(app(DatabaseManager::class), $phone));

    // The REAL phone accepts normally — its row binds the REAL desktop
    // identity as `initiator` from the out-of-band, physically-scanned QR,
    // which the relay cannot alter.
    $this->asDevice('phone', function () use ($desktop, $phone, $issuedToken): void {
        $service = app(PairingTokenService::class);
        $service->seedFromInitiator(CDP_USER_ID, $desktop['deviceId'], $desktop['edPub'], $desktop['kxPub'], $issuedToken);
        $service->accept($issuedToken, CDP_USER_ID, $phone['deviceId'], $phone['edPub'], $phone['kxPub']);
    });

    // A MITM on the relay substitutes the ATTACKER's identity into the
    // PAIR_RESPONDER_ACCEPT delivered to the desktop.
    $this->asDevice('desktop', fn () => app(PairingTokenService::class)
        ->applyResponderAccept(CDP_USER_ID, $tokenHash, $attacker['deviceId'], $attacker['edPub'], $attacker['kxPub']));

    // T1: the two sides now derive DIFFERENT safety words.
    $desktopWords = $this->asDevice('desktop', function () use ($tokenHash): array {
        $row = cdpTokenRow($tokenHash);

        return app(SafetyNumberDeriver::class)->deriveWords($row->initiator_ed25519_pub_hex, $row->responder_ed25519_pub_hex);
    });
    $phoneWords = $this->asDevice('phone', function () use ($tokenHash): array {
        $row = cdpTokenRow($tokenHash);

        return app(SafetyNumberDeriver::class)->deriveWords($row->initiator_ed25519_pub_hex, $row->responder_ed25519_pub_hex);
    });
    expect($desktopWords)->not->toBe($phoneWords, 'a MITM-substituted identity must yield different safety words on the two screens — the human-catches-it invariant');

    // Even in the worst case where the desktop human confirms WITHOUT
    // checking the (now-mismatched) words, the attacker — who legitimately
    // owns the key it substituted — CAN complete that one row. This is the
    // documented human gate, not a crypto failure: the narrower invariant
    // under test is that the attacker can NEVER also complete the REAL
    // phone's row.
    $this->asDevice('desktop', function () use ($tokenHash, $desktop): void {
        $row = cdpTokenRow($tokenHash);
        app(PairingTokenService::class)->confirm((int) $row->id, CDP_USER_ID, $desktop['deviceId']);
    });

    $sigFromAttacker = cdpSign($attacker, PairingFrame::confirmSigningMessage($tokenHash, $attacker['deviceId'], $desktop['deviceId']));
    $desktopState = $this->asDevice('desktop', fn () => app(PairingTokenService::class)
        ->applyPeerConfirm(CDP_USER_ID, $tokenHash, $attacker['deviceId'], $desktop['deviceId'], $sigFromAttacker));
    expect($desktopState)->toBe(PairingStateMachine::CONFIRMED);

    // The REAL phone's human also confirms (a real user tapping confirm).
    $this->asDevice('phone', function () use ($tokenHash, $phone): void {
        $row = cdpTokenRow($tokenHash);
        app(PairingTokenService::class)->confirm((int) $row->id, CDP_USER_ID, $phone['deviceId']);
    });

    // The attacker attempts to forge a PAIR_CONFIRM claiming to be the
    // desktop, addressed to the phone — it does NOT hold the REAL desktop's
    // Ed25519 secret key, so the signature fails verification against the
    // phone-bound REAL desktop public key.
    $forgedSigClaimingDesktop = cdpSign($attacker, PairingFrame::confirmSigningMessage($tokenHash, $desktop['deviceId'], $phone['deviceId']));
    $phoneState = $this->asDevice('phone', fn () => app(PairingTokenService::class)
        ->applyPeerConfirm(CDP_USER_ID, $tokenHash, $desktop['deviceId'], $phone['deviceId'], $forgedSigClaimingDesktop));
    expect($phoneState)->toBeNull();

    // Zero keys ever reach the phone: no confirmed desktop peer in ITS
    // device_registry.
    $this->asDevice('phone', function () use ($desktop): void {
        expect(app(DeviceRegistryService::class)->deviceKeys(CDP_USER_ID))->not->toHaveKey($desktop['deviceId']);
    });
});

// ---------------------------------------------------------------------
// Case 4 — a bare forged PAIR_CONFIRM (correct ids, random signing key).
// ---------------------------------------------------------------------

it('rejects a PAIR_CONFIRM with correct device ids but a signature from a random, unrelated key', function (): void {
    $this->crossDevicePairingSetUp();

    $desktop = cdpDevice('desktop-4');
    $phone = cdpDevice('phone-4');

    $issuedToken = $this->asDevice('desktop', fn () => app(PairingTokenService::class)
        ->issue(CDP_USER_ID, $desktop['deviceId'], $desktop['edPub'], $desktop['kxPub']));
    $tokenHash = hash('sha256', $issuedToken);

    $this->asDevice('desktop', fn () => cdpInsertSelfRow(app(DatabaseManager::class), $desktop));

    $this->asDevice('phone', function () use ($desktop, $phone, $issuedToken): void {
        $service = app(PairingTokenService::class);
        $service->seedFromInitiator(CDP_USER_ID, $desktop['deviceId'], $desktop['edPub'], $desktop['kxPub'], $issuedToken);
        $service->accept($issuedToken, CDP_USER_ID, $phone['deviceId'], $phone['edPub'], $phone['kxPub']);
    });

    $this->asDevice('desktop', fn () => app(PairingTokenService::class)
        ->applyResponderAccept(CDP_USER_ID, $tokenHash, $phone['deviceId'], $phone['edPub'], $phone['kxPub']));

    $this->asDevice('desktop', function () use ($tokenHash, $desktop): void {
        $row = cdpTokenRow($tokenHash);
        app(PairingTokenService::class)->confirm((int) $row->id, CDP_USER_ID, $desktop['deviceId']);
    });

    // A forged frame: correct token_hash + correct claimed device ids, but
    // signed by a totally unrelated random key — neither the relay nor an
    // attacker holds the phone's real Ed25519 secret key.
    $randomKeypair = sodium_crypto_sign_keypair();
    $forgedSig = sodium_bin2hex(sodium_crypto_sign_detached(
        PairingFrame::confirmSigningMessage($tokenHash, $phone['deviceId'], $desktop['deviceId']),
        sodium_crypto_sign_secretkey($randomKeypair),
    ));

    $result = $this->asDevice('desktop', fn () => app(PairingTokenService::class)
        ->applyPeerConfirm(CDP_USER_ID, $tokenHash, $phone['deviceId'], $desktop['deviceId'], $forgedSig));
    expect($result)->toBeNull();

    $this->asDevice('desktop', function () use ($tokenHash): void {
        $row = cdpTokenRow($tokenHash);
        expect($row->responder_confirmed_at)->toBeNull();
        expect($row->state)->not->toBe(PairingStateMachine::CONFIRMED);
    });
});

// ---------------------------------------------------------------------
// Case 5 — the local-confirm precondition: a valid peer confirm delivered
// BEFORE the local human confirms must defer, never complete alone.
// ---------------------------------------------------------------------

it('defers a valid, correctly-signed PAIR_CONFIRM delivered before the local side has confirmed — never completes alone', function (): void {
    $this->crossDevicePairingSetUp();

    $desktop = cdpDevice('desktop-5');
    $phone = cdpDevice('phone-5');

    $issuedToken = $this->asDevice('desktop', fn () => app(PairingTokenService::class)
        ->issue(CDP_USER_ID, $desktop['deviceId'], $desktop['edPub'], $desktop['kxPub']));
    $tokenHash = hash('sha256', $issuedToken);

    $this->asDevice('desktop', fn () => cdpInsertSelfRow(app(DatabaseManager::class), $desktop));

    $this->asDevice('phone', function () use ($desktop, $phone, $issuedToken): void {
        $service = app(PairingTokenService::class);
        $service->seedFromInitiator(CDP_USER_ID, $desktop['deviceId'], $desktop['edPub'], $desktop['kxPub'], $issuedToken);
        $service->accept($issuedToken, CDP_USER_ID, $phone['deviceId'], $phone['edPub'], $phone['kxPub']);
    });

    $this->asDevice('desktop', fn () => app(PairingTokenService::class)
        ->applyResponderAccept(CDP_USER_ID, $tokenHash, $phone['deviceId'], $phone['edPub'], $phone['kxPub']));

    // The phone's human confirms and signs immediately — but the desktop's
    // human has NOT confirmed yet.
    $sigFromPhone = cdpSign($phone, PairingFrame::confirmSigningMessage($tokenHash, $phone['deviceId'], $desktop['deviceId']));

    $deferred = $this->asDevice('desktop', fn () => app(PairingTokenService::class)
        ->applyPeerConfirm(CDP_USER_ID, $tokenHash, $phone['deviceId'], $desktop['deviceId'], $sigFromPhone));
    expect($deferred)->toBe('deferred');

    // Nothing was written: the peer column is still unset and the row is
    // NOT confirmed — a valid frame alone can never drive this.
    $this->asDevice('desktop', function () use ($tokenHash): void {
        $row = cdpTokenRow($tokenHash);
        expect($row->responder_confirmed_at)->toBeNull();
        expect($row->state)->toBe(PairingStateMachine::AWAITING_CONFIRM);
    });

    // Once the desktop's local human confirms, the SAME frame (redelivered
    // by the courier's poll) completes the gate.
    $this->asDevice('desktop', function () use ($tokenHash, $desktop): void {
        $row = cdpTokenRow($tokenHash);
        app(PairingTokenService::class)->confirm((int) $row->id, CDP_USER_ID, $desktop['deviceId']);
    });

    $applied = $this->asDevice('desktop', fn () => app(PairingTokenService::class)
        ->applyPeerConfirm(CDP_USER_ID, $tokenHash, $phone['deviceId'], $desktop['deviceId'], $sigFromPhone));
    expect($applied)->toBe(PairingStateMachine::CONFIRMED);
});

// ---------------------------------------------------------------------
// Case 6 — replay / duplicate idempotency.
// ---------------------------------------------------------------------

it('re-delivering an already-applied PAIR_RESPONDER_ACCEPT and an already-applied PAIR_CONFIRM is idempotent — no duplicate rows, no exception', function (): void {
    $this->crossDevicePairingSetUp();

    $desktop = cdpDevice('desktop-6');
    $phone = cdpDevice('phone-6');

    $issuedToken = $this->asDevice('desktop', fn () => app(PairingTokenService::class)
        ->issue(CDP_USER_ID, $desktop['deviceId'], $desktop['edPub'], $desktop['kxPub']));
    $tokenHash = hash('sha256', $issuedToken);

    $this->asDevice('desktop', fn () => cdpInsertSelfRow(app(DatabaseManager::class), $desktop));
    $this->asDevice('phone', fn () => cdpInsertSelfRow(app(DatabaseManager::class), $phone));

    $this->asDevice('phone', function () use ($desktop, $phone, $issuedToken): void {
        $service = app(PairingTokenService::class);
        $service->seedFromInitiator(CDP_USER_ID, $desktop['deviceId'], $desktop['edPub'], $desktop['kxPub'], $issuedToken);
        $service->accept($issuedToken, CDP_USER_ID, $phone['deviceId'], $phone['edPub'], $phone['kxPub']);
    });

    // Deliver the SAME PAIR_RESPONDER_ACCEPT TWICE.
    $this->asDevice('desktop', function () use ($tokenHash, $phone): void {
        $service = app(PairingTokenService::class);
        $first = $service->applyResponderAccept(CDP_USER_ID, $tokenHash, $phone['deviceId'], $phone['edPub'], $phone['kxPub']);
        $second = $service->applyResponderAccept(CDP_USER_ID, $tokenHash, $phone['deviceId'], $phone['edPub'], $phone['kxPub']);
        expect($first)->not->toBeFalse();
        expect($second)->not->toBeFalse();
        expect($first->id)->toBe($second->id);
    });

    $this->asDevice('phone', function () use ($tokenHash, $phone): void {
        $row = cdpTokenRow($tokenHash);
        app(PairingTokenService::class)->confirm((int) $row->id, CDP_USER_ID, $phone['deviceId']);
    });
    $this->asDevice('desktop', function () use ($tokenHash, $desktop): void {
        $row = cdpTokenRow($tokenHash);
        app(PairingTokenService::class)->confirm((int) $row->id, CDP_USER_ID, $desktop['deviceId']);
    });

    $sigFromPhone = cdpSign($phone, PairingFrame::confirmSigningMessage($tokenHash, $phone['deviceId'], $desktop['deviceId']));

    // Deliver the SAME PAIR_CONFIRM THREE TIMES (simulates the courier
    // re-draining an undeleted or duplicated relay row).
    $this->asDevice('desktop', function () use ($tokenHash, $phone, $desktop, $sigFromPhone): void {
        $service = app(PairingTokenService::class);
        expect($service->applyPeerConfirm(CDP_USER_ID, $tokenHash, $phone['deviceId'], $desktop['deviceId'], $sigFromPhone))
            ->toBe(PairingStateMachine::CONFIRMED);
        expect($service->applyPeerConfirm(CDP_USER_ID, $tokenHash, $phone['deviceId'], $desktop['deviceId'], $sigFromPhone))
            ->toBe(PairingStateMachine::CONFIRMED);
        expect($service->applyPeerConfirm(CDP_USER_ID, $tokenHash, $phone['deviceId'], $desktop['deviceId'], $sigFromPhone))
            ->toBe(PairingStateMachine::CONFIRMED);
    });

    // No duplicate device_registry row for the phone on the desktop's DB.
    $this->asDevice('desktop', function () use ($phone): void {
        /** @var DatabaseManager $db */
        $db = app(DatabaseManager::class);
        $count = $db->connection()->table('device_registry')
            ->where('user_id', CDP_USER_ID)
            ->where('device_id', $phone['deviceId'])
            ->count();
        expect($count)->toBe(1);
    });
});

// ---------------------------------------------------------------------
// Case 7 — expired / cancelled propagates nothing.
// ---------------------------------------------------------------------

it('an expired local row rejects a PAIR_CONFIRM — no admission, propagates nothing', function (): void {
    CarbonImmutable::setTestNow('2026-07-14 09:00:00');

    $this->crossDevicePairingSetUp();

    $desktop = cdpDevice('desktop-7');
    $phone = cdpDevice('phone-7');

    $issuedToken = $this->asDevice('desktop', fn () => app(PairingTokenService::class)
        ->issue(CDP_USER_ID, $desktop['deviceId'], $desktop['edPub'], $desktop['kxPub']));
    $tokenHash = hash('sha256', $issuedToken);

    $this->asDevice('desktop', fn () => cdpInsertSelfRow(app(DatabaseManager::class), $desktop));

    $this->asDevice('phone', function () use ($desktop, $phone, $issuedToken): void {
        $service = app(PairingTokenService::class);
        $service->seedFromInitiator(CDP_USER_ID, $desktop['deviceId'], $desktop['edPub'], $desktop['kxPub'], $issuedToken);
        $service->accept($issuedToken, CDP_USER_ID, $phone['deviceId'], $phone['edPub'], $phone['kxPub']);
    });

    $this->asDevice('desktop', fn () => app(PairingTokenService::class)
        ->applyResponderAccept(CDP_USER_ID, $tokenHash, $phone['deviceId'], $phone['edPub'], $phone['kxPub']));

    $this->asDevice('desktop', function () use ($tokenHash, $desktop): void {
        $row = cdpTokenRow($tokenHash);
        app(PairingTokenService::class)->confirm((int) $row->id, CDP_USER_ID, $desktop['deviceId']);
    });

    // Cancel the desktop's in-flight handshake (mirrors cancelPairing()'s
    // expire() call) BEFORE the peer confirm arrives.
    $this->asDevice('desktop', function () use ($tokenHash): void {
        $row = cdpTokenRow($tokenHash);
        app(PairingTokenService::class)->expire((int) $row->id, CDP_USER_ID);
    });

    $sigFromPhone = cdpSign($phone, PairingFrame::confirmSigningMessage($tokenHash, $phone['deviceId'], $desktop['deviceId']));

    $result = $this->asDevice('desktop', fn () => app(PairingTokenService::class)
        ->applyPeerConfirm(CDP_USER_ID, $tokenHash, $phone['deviceId'], $desktop['deviceId'], $sigFromPhone));
    expect($result)->toBeNull();

    $this->asDevice('desktop', function () use ($phone): void {
        expect(app(DeviceRegistryService::class)->deviceKeys(CDP_USER_ID))->not->toHaveKey($phone['deviceId']);
    });
});

it('a TTL-lapsed local row (natural expiry) rejects a PAIR_CONFIRM the same way', function (): void {
    CarbonImmutable::setTestNow('2026-07-14 09:00:00');

    $this->crossDevicePairingSetUp();

    $desktop = cdpDevice('desktop-7b');
    $phone = cdpDevice('phone-7b');

    $issuedToken = $this->asDevice('desktop', fn () => app(PairingTokenService::class)
        ->issue(CDP_USER_ID, $desktop['deviceId'], $desktop['edPub'], $desktop['kxPub']));
    $tokenHash = hash('sha256', $issuedToken);

    $this->asDevice('desktop', fn () => cdpInsertSelfRow(app(DatabaseManager::class), $desktop));

    $this->asDevice('phone', function () use ($desktop, $phone, $issuedToken): void {
        $service = app(PairingTokenService::class);
        $service->seedFromInitiator(CDP_USER_ID, $desktop['deviceId'], $desktop['edPub'], $desktop['kxPub'], $issuedToken);
        $service->accept($issuedToken, CDP_USER_ID, $phone['deviceId'], $phone['edPub'], $phone['kxPub']);
    });

    $this->asDevice('desktop', fn () => app(PairingTokenService::class)
        ->applyResponderAccept(CDP_USER_ID, $tokenHash, $phone['deviceId'], $phone['edPub'], $phone['kxPub']));

    $this->asDevice('desktop', function () use ($tokenHash, $desktop): void {
        $row = cdpTokenRow($tokenHash);
        app(PairingTokenService::class)->confirm((int) $row->id, CDP_USER_ID, $desktop['deviceId']);
    });

    // Let the TTL genuinely lapse (grace window is 5 minutes; jump 30).
    CarbonImmutable::setTestNow('2026-07-14 09:30:00');

    $sigFromPhone = cdpSign($phone, PairingFrame::confirmSigningMessage($tokenHash, $phone['deviceId'], $desktop['deviceId']));

    $result = $this->asDevice('desktop', fn () => app(PairingTokenService::class)
        ->applyPeerConfirm(CDP_USER_ID, $tokenHash, $phone['deviceId'], $desktop['deviceId'], $sigFromPhone));
    expect($result)->toBeNull();
});
