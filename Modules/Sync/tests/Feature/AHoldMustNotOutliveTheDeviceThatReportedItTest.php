<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Sync\Internal\Http\Livewire\IntroducedDevicesSection;
use Modules\Sync\Internal\Transport\Frame\TransportFramer;
use Modules\Sync\Internal\Transport\PeerCatchUpExchanger;
use Modules\Sync\Public\Enums\SyncOverallStatus;
use Modules\Sync\Public\Services\DeviceRegistryService;
use Modules\Sync\Public\Services\SyncStatusService;
use Psr\Log\NullLogger;

uses(RefreshDatabase::class);

// Removal takes every trace of a device that a reader can still reach — its
// sessions, its mailbox, its tokens — and sync_withheld_history arrived after
// that list was written. So a peer's last report outlived the peer: nothing
// rewrites a count except the device that sent it, and that device is gone.
//
// The aggregate and the device list then answered from two different places.
// One had no session left to read and said nothing was known; the other went
// on printing a number from an exchange that can never happen again.

const OUTLIVED_SELF = 'outlived-self-device-id';

const OUTLIVED_REPORTER = 'the-mac';

const OUTLIVED_SURVIVOR = 'the-laptop';

const OUTLIVED_AUTHOR = 'old-phone';

function outlivedUser(): User
{
    return User::query()->create([
        'username' => 'outlived-'.bin2hex(random_bytes(4)),
        'password' => 'fixture',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

function outlivedDevice(DatabaseManager $db, int $userId, string $deviceId, bool $isSelf): int
{
    $at = '2026-09-01T09:00:00+02:00';

    return (int) $db->connection()->table('device_registry')->insertGetId([
        'user_id' => $userId,
        'device_id' => $deviceId,
        'name' => 'Name of '.$deviceId,
        'ed25519_public_key_hex' => sodium_bin2hex(sodium_crypto_sign_publickey(sodium_crypto_sign_keypair())),
        'x25519_public_key_hex' => str_repeat('b', 64),
        'safety_number_words' => 'three spot buzz rich dove puzzle',
        'is_self' => $isSelf,
        'paired_at' => $at,
        'confirmed_at' => $at,
        'created_at' => $at,
        'updated_at' => $at,
    ]);
}

function outlivedSession(DatabaseManager $db, int $userId, string $peerDeviceId): void
{
    $at = '2026-09-01T09:00:00+02:00';

    $db->connection()->table('sync_sessions')->insert([
        'user_id' => $userId,
        'local_device_id' => OUTLIVED_SELF,
        'peer_device_id' => $peerDeviceId,
        'status' => 'closed',
        'error_message' => null,
        'connected_at' => $at,
        'last_seen_at' => $at,
        'created_at' => $at,
        'updated_at' => $at,
    ]);
}

/**
 * @return array{reporter: int, survivor: int}
 */
function outlivedHousehold(DatabaseManager $db, int $userId): array
{
    outlivedDevice($db, $userId, OUTLIVED_SELF, isSelf: true);
    $reporter = outlivedDevice($db, $userId, OUTLIVED_REPORTER, isSelf: false);
    $survivor = outlivedDevice($db, $userId, OUTLIVED_SURVIVOR, isSelf: false);

    outlivedSession($db, $userId, OUTLIVED_REPORTER);
    outlivedSession($db, $userId, OUTLIVED_SURVIVOR);

    return ['reporter' => $reporter, 'survivor' => $survivor];
}

// The wire report itself, through the class that parses a real
// CATCH_UP_RESPONSE, so nothing here writes the ledger row by hand.
function outlivedReport(DatabaseManager $db, int $userId, string $peerDeviceId, int $count): void
{
    new PeerCatchUpExchanger($db, new TransportFramer, new NullLogger)->recordIntroductions($userId, [
        'withheld' => [['device_id' => OUTLIVED_AUTHOR, 'count' => $count]],
        'introductions' => [],
    ], $peerDeviceId);
}

/**
 * @return list<array<string, mixed>>
 */
function outlivedListedHolds(User $user): array
{
    /** @var list<array<string, mixed>> $held */
    $held = Livewire::actingAs($user)->test(IntroducedDevicesSection::class)->get('withheld');

    return $held;
}

// The positive control. Everything below asserts a hold is ABSENT somewhere,
// and an assertion like that passes just as well against a state nothing can
// reach — so one case has to prove the wire report lands and both surfaces
// report it before the others are worth anything.
it('reaches both surfaces from one report at all', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $user = outlivedUser();
    $userId = (int) $user->id;
    outlivedHousehold($db, $userId);

    expect(app(SyncStatusService::class)->overallStatus($userId))->toBe(SyncOverallStatus::AllSynced);

    outlivedReport($db, $userId, OUTLIVED_REPORTER, 155);

    expect(app(SyncStatusService::class)->overallStatus($userId))->toBe(SyncOverallStatus::Withheld);

    $held = outlivedListedHolds($user);

    expect($held)->toHaveCount(1)
        ->and($held[0]['count'])->toBe(155)
        ->and($held[0]['peer'])->toBe('Name of '.OUTLIVED_REPORTER);
});

it('drops a hold with the peer that reported it, on the line and in the list', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $user = outlivedUser();
    $userId = (int) $user->id;
    $rows = outlivedHousehold($db, $userId);
    outlivedReport($db, $userId, OUTLIVED_REPORTER, 155);

    app(DeviceRegistryService::class)->purge($userId, $rows['reporter']);

    expect(app(SyncStatusService::class)->overallStatus($userId))->toBe(SyncOverallStatus::AllSynced)
        ->and(outlivedListedHolds($user))->toBe([]);
});

// The half a removal must NOT take. A surviving peer holding the same author's
// work back is its own live fact, and it rewrites its own count on its own next
// exchange — so keying the cleanup on the author would delete a report the
// household can still act on along with the one it cannot.
it('keeps a surviving peer holding the same author back', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $user = outlivedUser();
    $userId = (int) $user->id;
    $rows = outlivedHousehold($db, $userId);
    outlivedReport($db, $userId, OUTLIVED_REPORTER, 155);
    outlivedReport($db, $userId, OUTLIVED_SURVIVOR, 42);

    app(DeviceRegistryService::class)->purge($userId, $rows['reporter']);

    $held = outlivedListedHolds($user);

    expect(app(SyncStatusService::class)->overallStatus($userId))->toBe(SyncOverallStatus::Withheld)
        ->and($held)->toHaveCount(1)
        ->and($held[0]['count'])->toBe(42)
        ->and($held[0]['peer'])->toBe('Name of '.OUTLIVED_SURVIVOR);
});

// Dismissing a peer row is a session edit, not a removal: the device stays
// paired and the report stays true. The aggregate read its answer from the
// sessions alone, so the one act a reader can take on that list turned a
// reported hold into "not synced yet" while the list below kept printing it.
it('does not fall to unknown when the sessions are dismissed under a live hold', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $user = outlivedUser();
    $userId = (int) $user->id;
    outlivedHousehold($db, $userId);
    outlivedReport($db, $userId, OUTLIVED_REPORTER, 155);

    /** @var SyncStatusService $status */
    $status = app(SyncStatusService::class);
    $status->forgetSession($userId, OUTLIVED_REPORTER);
    $status->forgetSession($userId, OUTLIVED_SURVIVOR);

    expect($status->peerStatuses($userId))->toBe([])
        ->and($status->overallStatus($userId))->toBe(SyncOverallStatus::Withheld)
        ->and(outlivedListedHolds($user))->toHaveCount(1);
});

it('still says unknown once nothing is held and no session was ever recorded', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $user = outlivedUser();
    $userId = (int) $user->id;
    outlivedHousehold($db, $userId);

    /** @var SyncStatusService $status */
    $status = app(SyncStatusService::class);
    $status->forgetSession($userId, OUTLIVED_REPORTER);
    $status->forgetSession($userId, OUTLIVED_SURVIVOR);

    expect($status->overallStatus($userId))->toBe(SyncOverallStatus::Unknown);
});
