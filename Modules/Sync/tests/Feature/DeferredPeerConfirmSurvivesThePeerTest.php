<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Sync\Internal\Pairing\PairingFrame;
use Modules\Sync\Internal\Pairing\PairingState;
use Modules\Sync\Internal\Pairing\PairingTokenService;
use Modules\Sync\Internal\Pairing\PeerConfirmResult;
use Modules\Sync\Internal\Signing\DeviceKeySigner;
use Modules\Sync\Public\Services\DeviceRegistryService;
use Modules\Sync\Tests\Support\CrossDevicePairingHarness;
use Modules\Sync\Tests\Support\PairingSafetyDigest;

uses(RefreshDatabase::class);
uses(CrossDevicePairingHarness::class);

// Two genuinely separate databases, in the order hardware produced: the phone
// confirms while the desktop is still showing its code, the desktop's daemon
// answers 202 and keeps nothing, and the desktop's own tap then arrives with
// no peer confirmation left to pair it with.

const DPC_USER_ID = 5150;

/**
 * @return array{deviceId: string, edPub: string, edSec: string, kxPub: string}
 */
function dpcDevice(string $deviceId): array
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
function dpcSelfRow(array $device): void
{
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $now = '2026-08-22T09:00:00Z';

    $db->connection()->table('device_registry')->insert([
        'user_id' => DPC_USER_ID,
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
 * @param  array{deviceId: string, edPub: string, edSec: string, kxPub: string}  $signer
 * @param  array{deviceId: string, edPub: string, edSec: string, kxPub: string}  $recipient
 */
function dpcConfirmSignature(array $signer, array $recipient, string $tokenHash): string
{
    return (new DeviceKeySigner)->sign(
        PairingFrame::confirmSigningMessage($tokenHash, $signer['deviceId'], $recipient['deviceId'], $signer['kxPub'], $recipient['kxPub']),
        sodium_hex2bin($signer['edSec']),
    );
}

function dpcRow(string $tokenHash): object
{
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $row = $db->connection()->table('pairing_tokens')->where('token_hash', $tokenHash)->first();
    expect($row)->not->toBeNull();

    return (object) $row;
}

/**
 * @param  array{deviceId: string, edPub: string, edSec: string, kxPub: string}  $desktop
 * @param  array{deviceId: string, edPub: string, edSec: string, kxPub: string}  $phone
 * @param  Closure(string, Closure): mixed  $asDevice
 */
function dpcHandshakeUpToBothScreens(Closure $asDevice, array $desktop, array $phone): string
{
    $issuedToken = $asDevice('desktop', fn () => app(PairingTokenService::class)
        ->issue(DPC_USER_ID, $desktop['deviceId'], $desktop['edPub'], $desktop['kxPub']));

    $asDevice('desktop', fn () => dpcSelfRow($desktop));
    $asDevice('phone', fn () => dpcSelfRow($phone));

    $asDevice('phone', function () use ($desktop, $phone, $issuedToken): void {
        $service = app(PairingTokenService::class);
        $service->seedFromInitiator(DPC_USER_ID, $desktop['deviceId'], $desktop['edPub'], $desktop['kxPub'], $issuedToken);
        $service->accept($issuedToken, DPC_USER_ID, $phone['deviceId'], $phone['edPub'], $phone['kxPub']);
    });

    $tokenHash = hash('sha256', $issuedToken);

    $asDevice('desktop', fn () => app(PairingTokenService::class)
        ->applyResponderAccept(DPC_USER_ID, $tokenHash, $phone['deviceId'], $phone['edPub'], $phone['kxPub']));

    return $tokenHash;
}

afterEach(function (): void {
    $this->crossDevicePairingTearDown();
    CarbonImmutable::setTestNow();
});

it('completes on the initiator from its own tap alone, with the phone never sending its confirm again', function (): void {
    $this->crossDevicePairingSetUp();

    $desktop = dpcDevice('desktop-held-1');
    $phone = dpcDevice('phone-held-1');
    $tokenHash = dpcHandshakeUpToBothScreens($this->asDevice(...), $desktop, $phone);

    $this->asDevice('phone', function () use ($tokenHash, $phone): void {
        $row = dpcRow($tokenHash);
        app(PairingTokenService::class)->confirm((int) $row->id, DPC_USER_ID, $phone['deviceId'], PairingSafetyDigest::forToken((int) $row->id, DPC_USER_ID));
    });

    // The one delivery the phone makes while the desktop is still on its code
    // screen. The desktop answers 202 and the phone considers it delivered.
    $sigFromPhone = dpcConfirmSignature($phone, $desktop, $tokenHash);
    $deferred = $this->asDevice('desktop', fn () => app(PairingTokenService::class)
        ->applyPeerConfirm(DPC_USER_ID, $tokenHash, $phone['deviceId'], $desktop['deviceId'], $sigFromPhone));
    expect($deferred)->toEqual(PeerConfirmResult::deferred());

    // The desktop's human finally compares the words and taps. Nothing else
    // arrives from the phone after this point.
    $desktopState = $this->asDevice('desktop', function () use ($tokenHash, $desktop): ?string {
        $row = dpcRow($tokenHash);

        return app(PairingTokenService::class)->confirm((int) $row->id, DPC_USER_ID, $desktop['deviceId'], PairingSafetyDigest::forToken((int) $row->id, DPC_USER_ID));
    });

    expect($desktopState)->toBe(PairingState::Confirmed->value);

    $this->asDevice('desktop', function () use ($phone, $tokenHash): void {
        $row = dpcRow($tokenHash);
        expect($row->responder_confirmed_at)->not->toBeNull();
        expect($row->deferred_peer_confirm)->toBeNull();
        expect(app(DeviceRegistryService::class)->deviceKeys(DPC_USER_ID))->toHaveKey($phone['deviceId']);
    });
});

it('holds nothing a rebound responder could inherit — the replaced peer\'s signature is refused, not applied', function (): void {
    $this->crossDevicePairingSetUp();

    $desktop = dpcDevice('desktop-held-2');
    $phone = dpcDevice('phone-held-2');
    $imposter = dpcDevice('imposter-held-2');
    $tokenHash = dpcHandshakeUpToBothScreens($this->asDevice(...), $desktop, $phone);

    $this->asDevice('phone', function () use ($tokenHash, $phone): void {
        $row = dpcRow($tokenHash);
        app(PairingTokenService::class)->confirm((int) $row->id, DPC_USER_ID, $phone['deviceId'], PairingSafetyDigest::forToken((int) $row->id, DPC_USER_ID));
    });

    $sigFromPhone = dpcConfirmSignature($phone, $desktop, $tokenHash);
    $this->asDevice('desktop', fn () => app(PairingTokenService::class)
        ->applyPeerConfirm(DPC_USER_ID, $tokenHash, $phone['deviceId'], $desktop['deviceId'], $sigFromPhone));

    // Somebody else on the network takes the responder slot before the desktop
    // has confirmed anything, which the handshake deliberately allows.
    $this->asDevice('desktop', function () use ($tokenHash, $imposter): void {
        $rebound = app(PairingTokenService::class)
            ->applyResponderAccept(DPC_USER_ID, $tokenHash, $imposter['deviceId'], $imposter['edPub'], $imposter['kxPub']);
        expect($rebound)->not->toBeFalse();
        expect(dpcRow($tokenHash)->deferred_peer_confirm)->toBeNull();
    });

    $desktopState = $this->asDevice('desktop', function () use ($tokenHash, $desktop): ?string {
        $row = dpcRow($tokenHash);

        return app(PairingTokenService::class)->confirm((int) $row->id, DPC_USER_ID, $desktop['deviceId'], PairingSafetyDigest::forToken((int) $row->id, DPC_USER_ID));
    });

    expect($desktopState)->toBe(PairingState::AwaitingConfirm->value);

    $this->asDevice('desktop', function () use ($imposter, $phone, $tokenHash): void {
        expect(dpcRow($tokenHash)->responder_confirmed_at)->toBeNull();

        $admitted = app(DeviceRegistryService::class)->deviceKeys(DPC_USER_ID);
        expect($admitted)->not->toHaveKey($imposter['deviceId']);
        expect($admitted)->not->toHaveKey($phone['deviceId']);
    });
});

it('still refuses to complete on a held confirm alone — the local human remains the gate', function (): void {
    $this->crossDevicePairingSetUp();

    $desktop = dpcDevice('desktop-held-3');
    $phone = dpcDevice('phone-held-3');
    $tokenHash = dpcHandshakeUpToBothScreens($this->asDevice(...), $desktop, $phone);

    $this->asDevice('phone', function () use ($tokenHash, $phone): void {
        $row = dpcRow($tokenHash);
        app(PairingTokenService::class)->confirm((int) $row->id, DPC_USER_ID, $phone['deviceId'], PairingSafetyDigest::forToken((int) $row->id, DPC_USER_ID));
    });

    $sigFromPhone = dpcConfirmSignature($phone, $desktop, $tokenHash);

    // Redelivered as often as the courier likes: holding it must not become a
    // second road to CONFIRMED that skips the safety-number comparison.
    $this->asDevice('desktop', function () use ($tokenHash, $phone, $desktop, $sigFromPhone): void {
        $service = app(PairingTokenService::class);

        foreach (range(1, 3) as $ignored) {
            expect($service->applyPeerConfirm(DPC_USER_ID, $tokenHash, $phone['deviceId'], $desktop['deviceId'], $sigFromPhone))
                ->toEqual(PeerConfirmResult::deferred());
        }

        $row = dpcRow($tokenHash);
        expect($row->state)->toBe(PairingState::AwaitingConfirm->value);
        expect($row->responder_confirmed_at)->toBeNull();
        expect(app(DeviceRegistryService::class)->deviceKeys(DPC_USER_ID))->not->toHaveKey($phone['deviceId']);
    });
});

it('answers with a type rather than a string in the pairing states own space, so a deferral can never be read as one', function (): void {
    $this->crossDevicePairingSetUp();

    $desktop = dpcDevice('desktop-held-4');
    $phone = dpcDevice('phone-held-4');
    $tokenHash = dpcHandshakeUpToBothScreens($this->asDevice(...), $desktop, $phone);

    // The union this replaced returned 'deferred' out of the same string space
    // as 'confirmed', so nothing but a reader's care told a control answer
    // from a state. The declared type is what tells them apart now.
    $applyPeerConfirm = new ReflectionMethod(PairingTokenService::class, 'applyPeerConfirm');
    expect((string) $applyPeerConfirm->getReturnType())->toBe('?'.PeerConfirmResult::class);

    $this->asDevice('phone', function () use ($tokenHash, $phone): void {
        $row = dpcRow($tokenHash);
        app(PairingTokenService::class)->confirm((int) $row->id, DPC_USER_ID, $phone['deviceId'], PairingSafetyDigest::forToken((int) $row->id, DPC_USER_ID));
    });

    $sigFromPhone = dpcConfirmSignature($phone, $desktop, $tokenHash);
    $deferred = $this->asDevice('desktop', fn () => app(PairingTokenService::class)
        ->applyPeerConfirm(DPC_USER_ID, $tokenHash, $phone['deviceId'], $desktop['deviceId'], $sigFromPhone));

    expect($deferred->isDeferred())->toBeTrue()
        ->and($deferred->stateApplied())->toBeNull();

    $this->asDevice('desktop', function () use ($tokenHash, $desktop): void {
        $row = dpcRow($tokenHash);
        app(PairingTokenService::class)->confirm((int) $row->id, DPC_USER_ID, $desktop['deviceId'], PairingSafetyDigest::forToken((int) $row->id, DPC_USER_ID));
    });

    $applied = $this->asDevice('desktop', fn () => app(PairingTokenService::class)
        ->applyPeerConfirm(DPC_USER_ID, $tokenHash, $phone['deviceId'], $desktop['deviceId'], $sigFromPhone));

    expect($applied->isDeferred())->toBeFalse()
        ->and($applied->stateApplied())->toBe(PairingState::Confirmed)
        ->and($applied)->not->toEqual($deferred);
});
