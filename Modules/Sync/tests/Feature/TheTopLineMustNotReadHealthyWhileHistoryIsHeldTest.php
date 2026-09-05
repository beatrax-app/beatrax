<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Core\Public\Support\Lang;
use Modules\Sync\Internal\Pairing\DeviceIntroductionService;
use Modules\Sync\Internal\Transport\Frame\TransportFramer;
use Modules\Sync\Internal\Transport\PeerCatchUpExchanger;
use Modules\Sync\Public\Enums\SyncOverallStatus;
use Modules\Sync\Public\Http\Livewire\SyncStatusSection;
use Modules\Sync\Public\Services\SyncStatusService;
use Psr\Log\NullLogger;

uses(RefreshDatabase::class);

// The withheld count fed a per-peer surface and nothing above it, so a device
// missing an entire replaced phone's history read "All devices up to date" on
// the one line a reader checks to answer "is my data here".

const HELD_SELF = 'held-self-device-id';

const HELD_PEER = 'the-mac';

function heldUser(): User
{
    return User::query()->create([
        'username' => 'held-'.bin2hex(random_bytes(4)),
        'password' => 'fixture',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

function heldDevice(DatabaseManager $db, int $userId, string $deviceId, bool $isSelf): void
{
    $at = '2026-09-01T09:00:00+02:00';

    $db->connection()->table('device_registry')->insert([
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

function heldSettledHousehold(DatabaseManager $db, int $userId, string $status = 'closed'): void
{
    $at = '2026-09-01T09:00:00+02:00';

    $db->connection()->table('sync_sessions')->insert([
        'user_id' => $userId,
        'local_device_id' => HELD_SELF,
        'peer_device_id' => HELD_PEER,
        'status' => $status,
        'error_message' => $status === 'failed' ? 'Connection failed' : null,
        'connected_at' => $at,
        'last_seen_at' => $at,
        'created_at' => $at,
        'updated_at' => $at,
    ]);

    heldDevice($db, $userId, HELD_SELF, isSelf: true);
    heldDevice($db, $userId, HELD_PEER, isSelf: false);
}

/**
 * @param  list<array{device_id: string, name: string, ed25519_public_key_hex: string}>  $introductions
 */
function heldReport(DatabaseManager $db, int $userId, int $count, array $introductions = []): void
{
    new PeerCatchUpExchanger($db, new TransportFramer, new NullLogger)->recordIntroductions($userId, [
        'withheld' => [['device_id' => 'old-phone', 'count' => $count]],
        'introductions' => $introductions,
    ], HELD_PEER);
}

it('does not read as up to date while a peer is holding history back', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $userId = (int) heldUser()->id;
    heldSettledHousehold($db, $userId);

    /** @var SyncStatusService $status */
    $status = app(SyncStatusService::class);

    expect($status->overallStatus($userId))->toBe(SyncOverallStatus::AllSynced);

    heldReport($db, $userId, 155);

    expect($status->overallStatus($userId))->toBe(SyncOverallStatus::Withheld);
});

it('puts a hold above being behind, because no exchange clears one', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $userId = (int) heldUser()->id;
    heldSettledHousehold($db, $userId);

    // Owed in both directions at once. Behind leaves on the next exchange
    // and the hold leaves on none, which is what decides the order — not
    // whether the reader has something to press.
    $db->connection()->table('deferred_op_captures')->insert([
        'user_id' => $userId,
        'table_name' => 'recurring_series',
        'pk' => '7',
        'field' => 'billing_day',
        'op_kind' => 'set',
        'delta' => null,
        'captured_at' => '2026-09-02T09:00:00Z',
    ]);

    /** @var SyncStatusService $status */
    $status = app(SyncStatusService::class);

    expect($status->overallStatus($userId))->toBe(SyncOverallStatus::Behind);

    heldReport($db, $userId, 155);

    expect($status->overallStatus($userId))->toBe(SyncOverallStatus::Withheld);
});

it('keeps an unreachable peer above a hold, because neither moves until the dial does', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $userId = (int) heldUser()->id;
    heldSettledHousehold($db, $userId, status: 'failed');

    heldReport($db, $userId, 155);

    expect(app(SyncStatusService::class)->overallStatus($userId))->toBe(SyncOverallStatus::Offline);
});

it('goes quiet the moment the reader confirms the author, without waiting for an exchange', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $user = heldUser();
    $userId = (int) $user->id;
    heldSettledHousehold($db, $userId);

    heldReport($db, $userId, 155, [[
        'device_id' => 'old-phone',
        'name' => 'Old phone',
        'ed25519_public_key_hex' => sodium_bin2hex(sodium_crypto_sign_publickey(sodium_crypto_sign_keypair())),
    ]]);

    /** @var SyncStatusService $status */
    $status = app(SyncStatusService::class);

    expect($status->overallStatus($userId))->toBe(SyncOverallStatus::Withheld);

    /** @var DeviceIntroductionService $introductions */
    $introductions = app(DeviceIntroductionService::class);
    $introductions->confirm($userId, (int) $introductions->forUser($userId)[0]->id);

    expect($status->overallStatus($userId))->toBe(SyncOverallStatus::AllSynced);
});

it('says it on the status surface rather than only in the device list below it', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $user = heldUser();
    $userId = (int) $user->id;
    heldSettledHousehold($db, $userId);
    heldReport($db, $userId, 155);

    Livewire::actingAs($user)->test(SyncStatusSection::class)
        ->assertSet('overallStatus', SyncOverallStatus::Withheld->value)
        ->assertSee(Lang::get('sync::status.withheld'))
        ->assertDontSee(Lang::get('sync::status.all_synced'));
});
