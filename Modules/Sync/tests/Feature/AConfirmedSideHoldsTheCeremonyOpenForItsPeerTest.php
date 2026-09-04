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
use Modules\Sync\Public\Dto\PairingPeerIdentity;
use Modules\Sync\Public\Services\DeviceRegistryService;
use Modules\Sync\Tests\Support\CrossDevicePairingHarness;
use Modules\Sync\Tests\Support\PairingSafetyDigest;

uses(RefreshDatabase::class);
uses(CrossDevicePairingHarness::class);

/**
 * Measured on real hardware: a Mac and an iPhone whose app locks both fired
 * mid-ceremony. The desktop confirmed inside the window, then sat on "Waiting
 * for the other device to confirm…" while the phone was woken and unlocked.
 * The phone's confirm arrived 18 seconds past `expires_at` and was refused —
 * the desktop waited forever, the phone said "Device paired", and its next
 * dial was answered PEER_REVOKED, which cleared the phone's own confirmation.
 *
 * @link ../../../../.docs/features/sync/pairing-handshake.md#a-pairing-outlives-the-lock-that-interrupts-it
 */
const CEREMONY_HOLD_USER_ID = 5150;

/**
 * @return array{deviceId: string, edPub: string, edSec: string, kxPub: string}
 */
function ceremonyHoldDevice(string $deviceId): array
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
function ceremonyHoldSelfRow(DatabaseManager $db, array $device): void
{
    $now = CarbonImmutable::now()->toIso8601ZuluString();

    $db->connection()->table('device_registry')->insert([
        'user_id' => CEREMONY_HOLD_USER_ID,
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
function ceremonyHoldSign(array $device, string $message): string
{
    return (new DeviceKeySigner)->sign($message, sodium_hex2bin($device['edSec']));
}

function ceremonyHoldTokenId(string $tokenHash): int
{
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $row = $db->connection()->table('pairing_tokens')->where('token_hash', $tokenHash)->first();
    expect($row)->not->toBeNull();

    return (int) $row->id;
}

afterEach(function (): void {
    $this->crossDevicePairingTearDown();
    CarbonImmutable::setTestNow();
});

it('lets a peer confirm land after the ORIGINAL ten-minute window, because each local tap moved the deadline', function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-04T17:58:00Z'));
    $this->crossDevicePairingSetUp();

    $desktop = ceremonyHoldDevice('mac-hold');
    $phone = ceremonyHoldDevice('iphone-hold');

    $token = $this->asDevice('desktop', fn () => app(PairingTokenService::class)
        ->issue(CEREMONY_HOLD_USER_ID, $desktop['deviceId'], $desktop['edPub'], $desktop['kxPub']));
    $tokenHash = hash('sha256', $token);

    $this->asDevice('desktop', fn () => ceremonyHoldSelfRow(app(DatabaseManager::class), $desktop));
    $this->asDevice('phone', fn () => ceremonyHoldSelfRow(app(DatabaseManager::class), $phone));

    $this->asDevice('phone', function () use ($desktop, $phone, $token): void {
        $service = app(PairingTokenService::class);
        $service->seedFromInitiator(
            CEREMONY_HOLD_USER_ID,
            new PairingPeerIdentity($desktop['deviceId'], $desktop['edPub'], $desktop['kxPub']),
            $token,
        );
        $service->accept($token, CEREMONY_HOLD_USER_ID, $phone['deviceId'], $phone['edPub'], $phone['kxPub']);
    });

    $this->asDevice('desktop', fn () => app(PairingTokenService::class)->applyResponderAccept(
        CEREMONY_HOLD_USER_ID,
        $tokenHash,
        $phone['deviceId'],
        $phone['edPub'],
        $phone['kxPub'],
    ));

    // Nine minutes in — still inside the ten-minute TTL, so both taps are
    // accepted. This is where the reader on each device compares the words.
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-04T18:07:00Z'));

    foreach ([['phone', $phone], ['desktop', $desktop]] as [$role, $device]) {
        $this->asDevice($role, function () use ($tokenHash, $device): void {
            $tokenId = ceremonyHoldTokenId($tokenHash);
            $state = app(PairingTokenService::class)->confirm(
                $tokenId,
                CEREMONY_HOLD_USER_ID,
                $device['deviceId'],
                PairingSafetyDigest::forToken($tokenId, CEREMONY_HOLD_USER_ID),
            );
            expect($state)->toBe(PairingState::AwaitingConfirm->value);
        });
    }

    // Twelve minutes in — past `issue()`'s deadline of 18:08. The frames each
    // side has been re-emitting since its own tap now arrive.
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-04T18:10:00Z'));

    $fromPhone = ceremonyHoldSign($phone, PairingFrame::confirmSigningMessage(
        $tokenHash,
        $phone['deviceId'],
        $desktop['deviceId'],
        $phone['kxPub'],
        $desktop['kxPub'],
    ));

    $desktopOutcome = $this->asDevice('desktop', fn () => app(PairingTokenService::class)->applyPeerConfirm(
        CEREMONY_HOLD_USER_ID,
        $tokenHash,
        $phone['deviceId'],
        $desktop['deviceId'],
        $fromPhone,
    ));

    expect($desktopOutcome)->toEqual(PeerConfirmResult::applied(PairingState::Confirmed));

    $fromDesktop = ceremonyHoldSign($desktop, PairingFrame::confirmSigningMessage(
        $tokenHash,
        $desktop['deviceId'],
        $phone['deviceId'],
        $desktop['kxPub'],
        $phone['kxPub'],
    ));

    $phoneOutcome = $this->asDevice('phone', fn () => app(PairingTokenService::class)->applyPeerConfirm(
        CEREMONY_HOLD_USER_ID,
        $tokenHash,
        $desktop['deviceId'],
        $phone['deviceId'],
        $fromDesktop,
    ));

    expect($phoneOutcome)->toEqual(PeerConfirmResult::applied(PairingState::Confirmed));

    // The whole point of the window: both registries end up holding the peer,
    // which is what the phone's import gate and the desktop's device list read.
    $this->asDevice('desktop', function () use ($phone): void {
        expect(app(DeviceRegistryService::class)->deviceKeys(CEREMONY_HOLD_USER_ID))->toHaveKey($phone['deviceId']);
    });

    $this->asDevice('phone', function () use ($desktop): void {
        expect(app(DeviceRegistryService::class)->deviceKeys(CEREMONY_HOLD_USER_ID))->toHaveKey($desktop['deviceId']);
    });
});

it('does not let a tap revive a ceremony whose window had already run out', function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-04T17:58:00Z'));
    $this->crossDevicePairingSetUp();

    $desktop = ceremonyHoldDevice('mac-dead');
    $phone = ceremonyHoldDevice('iphone-dead');

    $token = $this->asDevice('desktop', fn () => app(PairingTokenService::class)
        ->issue(CEREMONY_HOLD_USER_ID, $desktop['deviceId'], $desktop['edPub'], $desktop['kxPub']));
    $tokenHash = hash('sha256', $token);

    $this->asDevice('desktop', fn () => ceremonyHoldSelfRow(app(DatabaseManager::class), $desktop));

    $this->asDevice('desktop', fn () => app(PairingTokenService::class)->applyResponderAccept(
        CEREMONY_HOLD_USER_ID,
        $tokenHash,
        $phone['deviceId'],
        $phone['edPub'],
        $phone['kxPub'],
    ));

    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-04T18:20:00Z'));

    $this->asDevice('desktop', function () use ($tokenHash, $desktop): void {
        $tokenId = ceremonyHoldTokenId($tokenHash);
        $state = app(PairingTokenService::class)->confirm(
            $tokenId,
            CEREMONY_HOLD_USER_ID,
            $desktop['deviceId'],
            PairingSafetyDigest::forToken($tokenId, CEREMONY_HOLD_USER_ID),
        );
        expect($state)->toBeNull();
    });
});
