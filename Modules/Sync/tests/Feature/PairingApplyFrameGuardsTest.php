<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\Sync\Internal\Pairing\PairingFrame;
use Modules\Sync\Internal\Pairing\PairingState;
use Modules\Sync\Internal\Pairing\PairingTokenService;
use Modules\Sync\Internal\Signing\DeviceKeySigner;

uses(RefreshDatabase::class);

// The rejections that fire when the stored row disagrees with the frame: an
// unknown token, a frame this device is not the addressee of, a sender that is
// not the identity the row bound. Each is the only thing between a hostile
// relay frame and a trust decision, and none announces itself when it stops.

const PAFG_DESKTOP = 'pafg-desktop';

const PAFG_PHONE = 'pafg-phone';

function pafgUser(string $username): User
{
    return User::query()->create([
        'username' => $username,
        'password' => bcrypt('pafg-pass'),
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

/**
 * @return array{edPub: string, edSec: string}
 */
function pafgKeypair(): array
{
    $sign = sodium_crypto_sign_keypair();

    return [
        'edPub' => sodium_bin2hex(sodium_crypto_sign_publickey($sign)),
        'edSec' => sodium_bin2hex(sodium_crypto_sign_secretkey($sign)),
    ];
}

// Puts a row in the state a PAIR_CONFIRM arrives into: AWAITING_CONFIRM with
// both sides bound, and the desktop registered as this device. Returns the
// token hash and the responder's keypair so a caller can sign as the phone.
/**
 * @return array{tokenHash: string, phone: array{edPub: string, edSec: string}, phoneKx: string, desktopKx: string}
 */
function pafgArrangeAwaitingConfirm(mixed $app, int $userId, bool $withSelfRow = true): array
{
    /** @var PairingTokenService $service */
    $service = $app->make(PairingTokenService::class);
    /** @var DatabaseManager $db */
    $db = $app->make(DatabaseManager::class);

    $desktop = pafgKeypair();
    $phone = pafgKeypair();

    $token = $service->issue($userId, PAFG_DESKTOP, $desktop['edPub'], str_repeat('b', 64));
    $tokenHash = hash('sha256', $token);

    if ($withSelfRow) {
        $db->connection()->table('device_registry')->insert([
            'user_id' => $userId,
            'device_id' => PAFG_DESKTOP,
            'name' => PAFG_DESKTOP,
            'ed25519_public_key_hex' => $desktop['edPub'],
            'x25519_public_key_hex' => str_repeat('b', 64),
            'safety_number_words' => 'abandon ability able about above absent',
            'is_self' => 1,
            'paired_at' => '2026-06-15T09:00:00Z',
            'confirmed_at' => '2026-06-15T09:00:00Z',
            'created_at' => '2026-06-15T09:00:00Z',
            'updated_at' => '2026-06-15T09:00:00Z',
        ]);
    }

    $db->connection()->table('pairing_tokens')
        ->where('token_hash', $tokenHash)
        ->update([
            'responder_device_id' => PAFG_PHONE,
            'responder_ed25519_pub_hex' => $phone['edPub'],
            'responder_x25519_pub_hex' => str_repeat('c', 64),
            'state' => PairingState::AwaitingConfirm->value,
            'initiator_confirmed_at' => '2026-06-15T09:30:00Z',
        ]);

    // The sealing keys this arrangement bound, which the confirm signature the
    // guard tests reconstruct now covers.
    return ['tokenHash' => $tokenHash, 'phone' => $phone, 'phoneKx' => str_repeat('c', 64), 'desktopKx' => str_repeat('b', 64)];
}

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-06-15 10:00:00');
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('refuses a responder-accept frame whose key material is not valid hex', function (): void {
    $user = pafgUser('pafg-bad-keys');

    /** @var PairingTokenService $service */
    $service = $this->app->make(PairingTokenService::class);

    $token = $service->issue((int) $user->id, PAFG_DESKTOP, str_repeat('a', 64), str_repeat('b', 64));
    $tokenHash = hash('sha256', $token);

    // Rejected on the key material alone — the row is never consulted, so a
    // malformed frame cannot half-bind an identity onto it.
    expect($service->applyResponderAccept((int) $user->id, $tokenHash, PAFG_PHONE, str_repeat('z', 64), str_repeat('b', 64)))
        ->toBeFalse();

    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $row = $db->connection()->table('pairing_tokens')->where('token_hash', $tokenHash)->first();

    expect($row->responder_device_id)->toBeNull()
        ->and($row->state)->toBe(PairingState::Pending->value);
});

it('refuses a responder-accept frame for a token hash this database does not hold', function (): void {
    $user = pafgUser('pafg-unknown-token');

    /** @var PairingTokenService $service */
    $service = $this->app->make(PairingTokenService::class);

    expect($service->applyResponderAccept(
        (int) $user->id,
        hash('sha256', 'a-token-that-was-never-issued-here'),
        PAFG_PHONE,
        str_repeat('a', 64),
        str_repeat('b', 64),
    ))->toBeFalse();
});

it('refuses a peer-confirm frame that was addressed to some other device', function (): void {
    $user = pafgUser('pafg-misaddressed');
    $arranged = pafgArrangeAwaitingConfirm($this->app, (int) $user->id);

    /** @var PairingTokenService $service */
    $service = $this->app->make(PairingTokenService::class);

    $message = PairingFrame::confirmSigningMessage($arranged['tokenHash'], PAFG_PHONE, 'a-third-device', $arranged['phoneKx'], $arranged['desktopKx']);
    $sig = (new DeviceKeySigner)->sign($message, sodium_hex2bin($arranged['phone']['edSec']));

    // The signature is genuine and the sender is the bound peer; only the
    // recipient is wrong. A frame this device was never the addressee of must
    // not be applied, however well-formed it is.
    expect($service->applyPeerConfirm((int) $user->id, $arranged['tokenHash'], PAFG_PHONE, 'a-third-device', $sig))
        ->toBeNull();
});

it('refuses a peer-confirm frame when this database has no self device row', function (): void {
    $user = pafgUser('pafg-no-self');
    $arranged = pafgArrangeAwaitingConfirm($this->app, (int) $user->id, withSelfRow: false);

    /** @var PairingTokenService $service */
    $service = $this->app->make(PairingTokenService::class);

    $message = PairingFrame::confirmSigningMessage($arranged['tokenHash'], PAFG_PHONE, PAFG_DESKTOP, $arranged['phoneKx'], $arranged['desktopKx']);
    $sig = (new DeviceKeySigner)->sign($message, sodium_hex2bin($arranged['phone']['edSec']));

    // With no is_self row there is no local side to confirm, so there is no
    // safe way to interpret the frame — fail closed rather than guess.
    expect($service->applyPeerConfirm((int) $user->id, $arranged['tokenHash'], PAFG_PHONE, PAFG_DESKTOP, $sig))
        ->toBeNull();
});

it('refuses a peer-confirm frame from a device the row never bound as the peer', function (): void {
    $user = pafgUser('pafg-unbound-sender');
    $arranged = pafgArrangeAwaitingConfirm($this->app, (int) $user->id);

    /** @var PairingTokenService $service */
    $service = $this->app->make(PairingTokenService::class);

    $intruder = pafgKeypair();
    $message = PairingFrame::confirmSigningMessage($arranged['tokenHash'], 'pafg-intruder', PAFG_DESKTOP, $arranged['phoneKx'], $arranged['desktopKx']);
    $sig = (new DeviceKeySigner)->sign($message, sodium_hex2bin($intruder['edSec']));

    // Correctly self-signed, and the frame is addressed here — but the sender
    // is not the identity this row bound for the peer side. Rejected before
    // any sodium call, so the intruder's key is never even loaded.
    expect($service->applyPeerConfirm((int) $user->id, $arranged['tokenHash'], 'pafg-intruder', PAFG_DESKTOP, $sig))
        ->toBeNull();
});

it('refuses a peer-confirm frame when the bound peer key on the row is malformed', function (): void {
    $user = pafgUser('pafg-corrupt-bound-key');
    $arranged = pafgArrangeAwaitingConfirm($this->app, (int) $user->id);

    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);

    // Corrupt the STORED key, not the frame. The write paths validate, so this
    // stands in for a row damaged by something other than this code — and the
    // guard has to hold anyway.
    $db->connection()->table('pairing_tokens')
        ->where('token_hash', $arranged['tokenHash'])
        ->update(['responder_ed25519_pub_hex' => str_repeat('z', 64)]);

    /** @var PairingTokenService $service */
    $service = $this->app->make(PairingTokenService::class);

    $message = PairingFrame::confirmSigningMessage($arranged['tokenHash'], PAFG_PHONE, PAFG_DESKTOP, $arranged['phoneKx'], $arranged['desktopKx']);
    $sig = (new DeviceKeySigner)->sign($message, sodium_hex2bin($arranged['phone']['edSec']));

    expect($service->applyPeerConfirm((int) $user->id, $arranged['tokenHash'], PAFG_PHONE, PAFG_DESKTOP, $sig))
        ->toBeNull();

    // Still not confirmed: an undecodable bound key can never be treated as a
    // satisfied signature check.
    $row = $db->connection()->table('pairing_tokens')->where('token_hash', $arranged['tokenHash'])->first();
    expect($row->state)->toBe(PairingState::AwaitingConfirm->value)
        ->and($row->responder_confirmed_at)->toBeNull();
});
