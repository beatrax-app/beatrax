<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Sync\Internal\Pairing\PairingFrame;
use Modules\Sync\Internal\Pairing\PairingState;
use Modules\Sync\Internal\Pairing\PairingTokenService;
use Modules\Sync\Internal\Pairing\SafetyNumberDeriver;
use Modules\Sync\Internal\Signing\DeviceKeySigner;
use Modules\Sync\Public\Services\DeviceRegistryService;
use Modules\Sync\Tests\Support\CrossDevicePairingHarness;
use Modules\Sync\Tests\Support\PairingSafetyDigest;

uses(RefreshDatabase::class);
uses(CrossDevicePairingHarness::class);

/**
 * @link ../../../../.docs/features/sync/cross-device-pairing-confirm.md
 */
const CDP_USER_ID = 4242;

/**
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

it('propagates PAIR_RESPONDER_ACCEPT so the desktop row transitions PENDING -> AWAITING_CONFIRM and both sides derive the SAME safety words', function (): void {
    $this->crossDevicePairingSetUp();

    $desktop = cdpDevice('desktop-1');
    $phone = cdpDevice('phone-1');

    $issuedToken = $this->asDevice('desktop', fn () => app(PairingTokenService::class)
        ->issue(CDP_USER_ID, $desktop['deviceId'], $desktop['edPub'], $desktop['kxPub']));
    $tokenHash = hash('sha256', $issuedToken);

    // The phone seeds its own local row from the scanned QR before accepting;
    // on a genuinely separate database there is nothing to accept otherwise.
    $this->asDevice('phone', function () use ($desktop, $phone, $issuedToken): void {
        $service = app(PairingTokenService::class);
        $service->seedFromInitiator(CDP_USER_ID, $desktop['deviceId'], $desktop['edPub'], $desktop['kxPub'], $issuedToken);
        $accepted = $service->accept($issuedToken, CDP_USER_ID, $phone['deviceId'], $phone['edPub'], $phone['kxPub']);
        expect($accepted)->not->toBeFalse();
    });

    $this->asDevice('desktop', function () use ($tokenHash, $phone): void {
        $applied = app(PairingTokenService::class)
            ->applyResponderAccept(CDP_USER_ID, $tokenHash, $phone['deviceId'], $phone['edPub'], $phone['kxPub']);
        expect($applied)->not->toBeFalse();
    });

    $desktopWords = $this->asDevice('desktop', function () use ($tokenHash): array {
        $row = cdpTokenRow($tokenHash);
        expect($row->state)->toBe(PairingState::AwaitingConfirm->value);

        return app(SafetyNumberDeriver::class)->deriveWords($row->initiator_ed25519_pub_hex, $row->responder_ed25519_pub_hex);
    });

    $phoneWords = $this->asDevice('phone', function () use ($tokenHash): array {
        $row = cdpTokenRow($tokenHash);

        return app(SafetyNumberDeriver::class)->deriveWords($row->initiator_ed25519_pub_hex, $row->responder_ed25519_pub_hex);
    });

    expect($desktopWords)->toBe($phoneWords);
});

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
        $state = app(PairingTokenService::class)->confirm((int) $row->id, CDP_USER_ID, $phone['deviceId'], PairingSafetyDigest::forToken((int) $row->id, CDP_USER_ID));
        expect($state)->toBe(PairingState::AwaitingConfirm->value);
    });

    // PHONE sends its signed PAIR_CONFIRM to the DESKTOP — the desktop has
    // not confirmed locally yet, so this must DEFER, not admit.
    $sigFromPhone = cdpSign($phone, PairingFrame::confirmSigningMessage($tokenHash, $phone['deviceId'], $desktop['deviceId'], $phone['kxPub'], $desktop['kxPub']));
    $deferredState = $this->asDevice('desktop', fn () => app(PairingTokenService::class)
        ->applyPeerConfirm(CDP_USER_ID, $tokenHash, $phone['deviceId'], $desktop['deviceId'], $sigFromPhone));
    expect($deferredState)->toBe('deferred');

    // DESKTOP's human now confirms too. The deferred frame is held on this
    // row, so the tap is the last input the gate was waiting for and nothing
    // has to arrive from the phone a second time.
    $this->asDevice('desktop', function () use ($tokenHash, $desktop): void {
        $row = cdpTokenRow($tokenHash);
        $state = app(PairingTokenService::class)->confirm((int) $row->id, CDP_USER_ID, $desktop['deviceId'], PairingSafetyDigest::forToken((int) $row->id, CDP_USER_ID));
        expect($state)->toBe(PairingState::Confirmed->value, 'the held peer confirm is replayed by the local tap');
    });

    // A relay that redelivers the same PAIR_CONFIRM afterwards finds the work
    // already done and says so, rather than reopening anything.
    $desktopFinal = $this->asDevice('desktop', fn () => app(PairingTokenService::class)
        ->applyPeerConfirm(CDP_USER_ID, $tokenHash, $phone['deviceId'], $desktop['deviceId'], $sigFromPhone));
    expect($desktopFinal)->toBe(PairingState::Confirmed->value);

    // DESKTOP sends its own signed PAIR_CONFIRM to the PHONE.
    $sigFromDesktop = cdpSign($desktop, PairingFrame::confirmSigningMessage($tokenHash, $desktop['deviceId'], $phone['deviceId'], $desktop['kxPub'], $phone['kxPub']));
    $phoneFinal = $this->asDevice('phone', fn () => app(PairingTokenService::class)
        ->applyPeerConfirm(CDP_USER_ID, $tokenHash, $desktop['deviceId'], $phone['deviceId'], $sigFromDesktop));
    expect($phoneFinal)->toBe(PairingState::Confirmed->value);

    // Each device admits the PEER it does not own, on its OWN database.
    $this->asDevice('desktop', function () use ($phone): void {
        expect(app(DeviceRegistryService::class)->deviceKeys(CDP_USER_ID))->toHaveKey($phone['deviceId']);
    });
    $this->asDevice('phone', function () use ($desktop): void {
        expect(app(DeviceRegistryService::class)->deviceKeys(CDP_USER_ID))->toHaveKey($desktop['deviceId']);
    });
});

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

    // The phone binds the real desktop identity from the physically-scanned
    // QR, which never crosses the relay.
    $this->asDevice('phone', function () use ($desktop, $phone, $issuedToken): void {
        $service = app(PairingTokenService::class);
        $service->seedFromInitiator(CDP_USER_ID, $desktop['deviceId'], $desktop['edPub'], $desktop['kxPub'], $issuedToken);
        $service->accept($issuedToken, CDP_USER_ID, $phone['deviceId'], $phone['edPub'], $phone['kxPub']);
    });

    // A MITM on the relay substitutes the ATTACKER's identity into the
    // PAIR_RESPONDER_ACCEPT delivered to the desktop.
    $this->asDevice('desktop', fn () => app(PairingTokenService::class)
        ->applyResponderAccept(CDP_USER_ID, $tokenHash, $attacker['deviceId'], $attacker['edPub'], $attacker['kxPub']));

    $desktopWords = $this->asDevice('desktop', function () use ($tokenHash): array {
        $row = cdpTokenRow($tokenHash);

        return app(SafetyNumberDeriver::class)->deriveWords($row->initiator_ed25519_pub_hex, $row->responder_ed25519_pub_hex);
    });
    $phoneWords = $this->asDevice('phone', function () use ($tokenHash): array {
        $row = cdpTokenRow($tokenHash);

        return app(SafetyNumberDeriver::class)->deriveWords($row->initiator_ed25519_pub_hex, $row->responder_ed25519_pub_hex);
    });
    expect($desktopWords)->not->toBe($phoneWords, 'a MITM-substituted identity must yield different safety words on the two screens — the human-catches-it invariant');

    // A human who ignores the mismatched words lets the attacker complete
    // this one row — an accepted human gate. What follows is the narrower
    // invariant: the attacker can never complete the real phone's row too.
    $this->asDevice('desktop', function () use ($tokenHash, $desktop): void {
        $row = cdpTokenRow($tokenHash);
        app(PairingTokenService::class)->confirm((int) $row->id, CDP_USER_ID, $desktop['deviceId'], PairingSafetyDigest::forToken((int) $row->id, CDP_USER_ID));
    });

    $sigFromAttacker = cdpSign($attacker, PairingFrame::confirmSigningMessage($tokenHash, $attacker['deviceId'], $desktop['deviceId'], $attacker['kxPub'], $desktop['kxPub']));
    $desktopState = $this->asDevice('desktop', fn () => app(PairingTokenService::class)
        ->applyPeerConfirm(CDP_USER_ID, $tokenHash, $attacker['deviceId'], $desktop['deviceId'], $sigFromAttacker));
    expect($desktopState)->toBe(PairingState::Confirmed->value);

    // The REAL phone's human also confirms (a real user tapping confirm).
    $this->asDevice('phone', function () use ($tokenHash, $phone): void {
        $row = cdpTokenRow($tokenHash);
        app(PairingTokenService::class)->confirm((int) $row->id, CDP_USER_ID, $phone['deviceId'], PairingSafetyDigest::forToken((int) $row->id, CDP_USER_ID));
    });

    // The attacker does not hold the desktop's Ed25519 secret, so a confirm
    // claiming to be the desktop fails against the phone-bound desktop key.
    $forgedSigClaimingDesktop = cdpSign($attacker, PairingFrame::confirmSigningMessage($tokenHash, $desktop['deviceId'], $phone['deviceId'], $desktop['kxPub'], $phone['kxPub']));
    $phoneState = $this->asDevice('phone', fn () => app(PairingTokenService::class)
        ->applyPeerConfirm(CDP_USER_ID, $tokenHash, $desktop['deviceId'], $phone['deviceId'], $forgedSigClaimingDesktop));
    expect($phoneState)->toBeNull();

    $this->asDevice('phone', function () use ($desktop): void {
        expect(app(DeviceRegistryService::class)->deviceKeys(CDP_USER_ID))->not->toHaveKey($desktop['deviceId']);
    });
});

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
        app(PairingTokenService::class)->confirm((int) $row->id, CDP_USER_ID, $desktop['deviceId'], PairingSafetyDigest::forToken((int) $row->id, CDP_USER_ID));
    });

    // Correct token hash and device ids, signed by an unrelated key: what a
    // relay that never held the phone's Ed25519 secret can actually produce.
    $randomKeypair = sodium_crypto_sign_keypair();
    $forgedSig = sodium_bin2hex(sodium_crypto_sign_detached(
        PairingFrame::confirmSigningMessage($tokenHash, $phone['deviceId'], $desktop['deviceId'], $phone['kxPub'], $desktop['kxPub']),
        sodium_crypto_sign_secretkey($randomKeypair),
    ));

    $result = $this->asDevice('desktop', fn () => app(PairingTokenService::class)
        ->applyPeerConfirm(CDP_USER_ID, $tokenHash, $phone['deviceId'], $desktop['deviceId'], $forgedSig));
    expect($result)->toBeNull();

    $this->asDevice('desktop', function () use ($tokenHash): void {
        $row = cdpTokenRow($tokenHash);
        expect($row->responder_confirmed_at)->toBeNull();
        expect($row->state)->not->toBe(PairingState::Confirmed->value);
    });
});

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
    $sigFromPhone = cdpSign($phone, PairingFrame::confirmSigningMessage($tokenHash, $phone['deviceId'], $desktop['deviceId'], $phone['kxPub'], $desktop['kxPub']));

    $deferred = $this->asDevice('desktop', fn () => app(PairingTokenService::class)
        ->applyPeerConfirm(CDP_USER_ID, $tokenHash, $phone['deviceId'], $desktop['deviceId'], $sigFromPhone));
    expect($deferred)->toBe('deferred');

    $this->asDevice('desktop', function () use ($tokenHash): void {
        $row = cdpTokenRow($tokenHash);
        expect($row->responder_confirmed_at)->toBeNull();
        expect($row->state)->toBe(PairingState::AwaitingConfirm->value);
    });

    // Once the desktop's local human confirms, the SAME frame (redelivered
    // by the courier's poll) completes the gate.
    $this->asDevice('desktop', function () use ($tokenHash, $desktop): void {
        $row = cdpTokenRow($tokenHash);
        app(PairingTokenService::class)->confirm((int) $row->id, CDP_USER_ID, $desktop['deviceId'], PairingSafetyDigest::forToken((int) $row->id, CDP_USER_ID));
    });

    $applied = $this->asDevice('desktop', fn () => app(PairingTokenService::class)
        ->applyPeerConfirm(CDP_USER_ID, $tokenHash, $phone['deviceId'], $desktop['deviceId'], $sigFromPhone));
    expect($applied)->toBe(PairingState::Confirmed->value);
});

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
        app(PairingTokenService::class)->confirm((int) $row->id, CDP_USER_ID, $phone['deviceId'], PairingSafetyDigest::forToken((int) $row->id, CDP_USER_ID));
    });
    $this->asDevice('desktop', function () use ($tokenHash, $desktop): void {
        $row = cdpTokenRow($tokenHash);
        app(PairingTokenService::class)->confirm((int) $row->id, CDP_USER_ID, $desktop['deviceId'], PairingSafetyDigest::forToken((int) $row->id, CDP_USER_ID));
    });

    $sigFromPhone = cdpSign($phone, PairingFrame::confirmSigningMessage($tokenHash, $phone['deviceId'], $desktop['deviceId'], $phone['kxPub'], $desktop['kxPub']));

    // Simulates the courier re-draining an undeleted or duplicated relay row.
    $this->asDevice('desktop', function () use ($tokenHash, $phone, $desktop, $sigFromPhone): void {
        $service = app(PairingTokenService::class);
        expect($service->applyPeerConfirm(CDP_USER_ID, $tokenHash, $phone['deviceId'], $desktop['deviceId'], $sigFromPhone))
            ->toBe(PairingState::Confirmed->value);
        expect($service->applyPeerConfirm(CDP_USER_ID, $tokenHash, $phone['deviceId'], $desktop['deviceId'], $sigFromPhone))
            ->toBe(PairingState::Confirmed->value);
        expect($service->applyPeerConfirm(CDP_USER_ID, $tokenHash, $phone['deviceId'], $desktop['deviceId'], $sigFromPhone))
            ->toBe(PairingState::Confirmed->value);
    });

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
        app(PairingTokenService::class)->confirm((int) $row->id, CDP_USER_ID, $desktop['deviceId'], PairingSafetyDigest::forToken((int) $row->id, CDP_USER_ID));
    });

    // Cancel the handshake before the peer confirm arrives.
    $this->asDevice('desktop', function () use ($tokenHash): void {
        $row = cdpTokenRow($tokenHash);
        app(PairingTokenService::class)->expire((int) $row->id, CDP_USER_ID);
    });

    $sigFromPhone = cdpSign($phone, PairingFrame::confirmSigningMessage($tokenHash, $phone['deviceId'], $desktop['deviceId'], $phone['kxPub'], $desktop['kxPub']));

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
        app(PairingTokenService::class)->confirm((int) $row->id, CDP_USER_ID, $desktop['deviceId'], PairingSafetyDigest::forToken((int) $row->id, CDP_USER_ID));
    });

    // Let the TTL genuinely lapse (grace window is 5 minutes; jump 30).
    CarbonImmutable::setTestNow('2026-07-14 09:30:00');

    $sigFromPhone = cdpSign($phone, PairingFrame::confirmSigningMessage($tokenHash, $phone['deviceId'], $desktop['deviceId'], $phone['kxPub'], $desktop['kxPub']));

    $result = $this->asDevice('desktop', fn () => app(PairingTokenService::class)
        ->applyPeerConfirm(CDP_USER_ID, $tokenHash, $phone['deviceId'], $desktop['deviceId'], $sigFromPhone));
    expect($result)->toBeNull();
});
