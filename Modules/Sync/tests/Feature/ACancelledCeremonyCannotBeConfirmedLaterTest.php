<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\Sync\Internal\Pairing\PairingState;
use Modules\Sync\Internal\Pairing\PairingTokenService;
use Modules\Sync\Public\Dto\PairingPeerIdentity;
use Modules\Sync\Tests\Support\PairingSafetyDigest;

uses(RefreshDatabase::class);

// confirm() read neither `state` nor `expires_at`, so a ceremony the reader had
// cancelled — or one whose window had run out hours earlier — could still be
// finished into a CONFIRMED device_registry row: full Noise admission, Ed25519
// op verification and GDK fan-out eligibility. Every other door into the same
// admission was already gated, and PairingState's comment claimed this one was.
/**
 * @link ../../../../.docs/features/sync/pairing-handshake.md
 */
const CANCELLED_INITIATOR = 'desktop-initiator';

const CANCELLED_RESPONDER = 'phone-self';

function cancelledCeremonyUser(string $username): User
{
    return User::query()->create([
        'username' => $username,
        'password' => bcrypt('fixture'),
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

// One database stands in for both sides: the second confirm() is the peer's
// confirm signal arriving on this same row, which is how every other test in
// this module drives a completed ceremony.
function cancelledCeremonyRow(PairingTokenService $service, DatabaseManager $db, int $userId): int
{
    $token = bin2hex(random_bytes(16));

    $service->seedFromInitiator($userId, new PairingPeerIdentity(CANCELLED_INITIATOR, str_repeat('a', 64), str_repeat('b', 64)), $token);
    $service->accept($token, $userId, CANCELLED_RESPONDER, str_repeat('9', 64), str_repeat('8', 64));

    $row = $db->connection()->table('pairing_tokens')->where('user_id', $userId)->first(['id', 'state']);

    expect($row)->not->toBeNull()
        ->and($row->state)->toBe(PairingState::AwaitingConfirm->value);

    return (int) $row->id;
}

function cancelledCeremonyAdmitted(DatabaseManager $db, int $userId): bool
{
    return $db->connection()->table('device_registry')
        ->where('user_id', $userId)
        ->where('device_id', CANCELLED_INITIATOR)
        ->whereNotNull('confirmed_at')
        ->exists();
}

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-07-14 09:00:00');
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('still admits a device through a ceremony that is live, so the gate is not the whole door', function (): void {
    /** @var PairingTokenService $service */
    $service = app(PairingTokenService::class);
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    $userId = (int) cancelledCeremonyUser('ceremony-live')->id;
    $tokenId = cancelledCeremonyRow($service, $db, $userId);
    $digest = PairingSafetyDigest::forToken($tokenId, $userId);

    $service->confirm($tokenId, $userId, CANCELLED_RESPONDER, $digest);

    expect($service->confirm($tokenId, $userId, CANCELLED_INITIATOR, $digest))->toBe(PairingState::Confirmed->value)
        ->and(cancelledCeremonyAdmitted($db, $userId))->toBeTrue();
});

it('refuses a confirm arriving after the ceremony stopped being live', function (string $case, Closure $end): void {
    /** @var PairingTokenService $service */
    $service = app(PairingTokenService::class);
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    $userId = (int) cancelledCeremonyUser('ceremony-'.$case)->id;
    $tokenId = cancelledCeremonyRow($service, $db, $userId);
    $digest = PairingSafetyDigest::forToken($tokenId, $userId);

    // The reader's own side, tapped while the ceremony was still live.
    $service->confirm($tokenId, $userId, CANCELLED_RESPONDER, $digest);

    $end($service, $db, $userId, $tokenId);

    // The peer's confirm arrives afterwards. Admitting on it would hand full
    // Noise admission and GDK fan-out eligibility to a pairing the reader had
    // already ended.
    expect($service->confirm($tokenId, $userId, CANCELLED_INITIATOR, $digest))->toBeNull()
        ->and(cancelledCeremonyAdmitted($db, $userId))->toBeFalse();
})->with([
    'the countdown hit zero' => ['expired', function (PairingTokenService $service, DatabaseManager $db, int $userId, int $tokenId): void {
        $service->expire($tokenId, $userId);
    }],
    'the reader cancelled the modal' => ['cancelled', function (PairingTokenService $service, DatabaseManager $db, int $userId, int $tokenId): void {
        $service->expireUnfinished($userId);
    }],
    'the TTL simply lapsed' => ['lapsed', function (PairingTokenService $service, DatabaseManager $db, int $userId, int $tokenId): void {
        $db->connection()->table('pairing_tokens')
            ->where('id', $tokenId)
            ->update(['expires_at' => '2020-01-01T00:00:00Z']);
    }],
    'both at once' => ['both', function (PairingTokenService $service, DatabaseManager $db, int $userId, int $tokenId): void {
        $service->expire($tokenId, $userId);
        $db->connection()->table('pairing_tokens')
            ->where('id', $tokenId)
            ->update(['expires_at' => '2020-01-01T00:00:00Z']);
    }],
]);
