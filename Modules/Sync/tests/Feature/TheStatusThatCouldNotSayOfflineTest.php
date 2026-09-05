<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Sync\Public\Enums\SyncOverallStatus;
use Modules\Sync\Public\Http\Livewire\SyncStatusSection;
use Modules\Sync\Public\Services\SyncStatusService;

uses(RefreshDatabase::class);

// The arm that answered for a peer whose session had finished could return only
// "syncing" or "all synced". So an unreachable peer that had been seen once read
// as up to date, and a device holding changes it had never sent read as an
// exchange in progress — with nothing connected and nothing on its way.

function offlineArmUser(string $username): User
{
    return User::query()->create([
        'username' => $username,
        'password' => bcrypt('fixture'),
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

/**
 * @param  array<string, mixed>  $overrides
 */
function offlineArmSession(DatabaseManager $db, int $userId, array $overrides = []): void
{
    $db->connection()->table('sync_sessions')->insert(array_merge([
        'user_id' => $userId,
        'local_device_id' => 'this-device',
        'peer_device_id' => 'study-desktop',
        'status' => 'closed',
        'error_message' => null,
        'last_seen_at' => '2026-08-01 10:05:00',
        'connected_at' => '2026-08-01 10:04:00',
        'created_at' => '2026-08-01 10:04:00',
        'updated_at' => '2026-08-01 10:05:00',
    ], $overrides));
}

function offlineArmSelfDevice(DatabaseManager $db, int $userId): void
{
    $db->connection()->table('device_registry')->insert([
        'user_id' => $userId,
        'device_id' => 'this-device',
        'name' => 'This laptop',
        'ed25519_public_key_hex' => str_repeat('ab', 32),
        'x25519_public_key_hex' => str_repeat('cd', 32),
        'safety_number_words' => 'alpha bravo charlie',
        'is_self' => 1,
        'paired_at' => '2026-08-01 10:00:00',
        'confirmed_at' => '2026-08-01 10:01:00',
        'created_at' => '2026-08-01 10:00:00',
        'updated_at' => '2026-08-01 10:01:00',
    ]);
}

// One op this device authored after the session that could have carried it
// closed. The value never leaves this test — only recorded_at is read.
function offlineArmLocalOp(DatabaseManager $db, int $userId, string $recordedAt): void
{
    $db->connection()->table('op_log_entries')->insert([
        'user_id' => $userId,
        'device_id' => 'this-device',
        'table_name' => 'goals',
        'pk' => '41',
        'field' => 'name',
        'op_type' => 'set',
        'value' => '"Holiday"',
        'hlc_l' => 1,
        'hlc_c' => 0,
        'signature' => 'fixture-signature',
        'recorded_at' => $recordedAt,
    ]);
}

it('says offline for a peer it finished an exchange with and can no longer reach', function (): void {
    $db = app(DatabaseManager::class);
    $userId = (int) offlineArmUser('offline-arm-seen-before')->id;

    offlineArmSession($db, $userId, [
        'status' => 'failed',
        'error_message' => 'could not reach peer: connection refused',
    ]);

    expect(app(SyncStatusService::class)->overallStatus($userId))->toBe(SyncOverallStatus::Offline);
});

// The one the spec names outright: an unreachable relay is normal, and normal
// is not an error. The row's own label had always said so.
it('says offline rather than error when the relay is the thing that cannot be reached', function (): void {
    $db = app(DatabaseManager::class);
    $userId = (int) offlineArmUser('offline-arm-relay')->id;

    offlineArmSession($db, $userId, [
        'status' => 'failed',
        'error_message' => 'relay endpoint unreachable',
    ]);

    expect(app(SyncStatusService::class)->overallStatus($userId))->toBe(SyncOverallStatus::Offline);
});

it('still says error when the failure was the peer failing to verify', function (): void {
    $db = app(DatabaseManager::class);
    $userId = (int) offlineArmUser('offline-arm-verify')->id;

    offlineArmSession($db, $userId, [
        'status' => 'failed',
        'error_message' => 'Noise handshake verify failed: static key mismatch',
    ]);

    expect(app(SyncStatusService::class)->overallStatus($userId))->toBe(SyncOverallStatus::Error);
});

it('says behind, not syncing, when this device holds changes the last session never carried', function (): void {
    $db = app(DatabaseManager::class);
    $userId = (int) offlineArmUser('offline-arm-behind')->id;

    offlineArmSelfDevice($db, $userId);
    offlineArmSession($db, $userId);
    offlineArmLocalOp($db, $userId, '2026-08-01 11:00:00');

    expect(app(SyncStatusService::class)->overallStatus($userId))->toBe(SyncOverallStatus::Behind);
});

it('says all synced when every local op predates the session that carried it', function (): void {
    $db = app(DatabaseManager::class);
    $userId = (int) offlineArmUser('offline-arm-caught-up')->id;

    offlineArmSelfDevice($db, $userId);
    offlineArmSession($db, $userId);
    offlineArmLocalOp($db, $userId, '2026-08-01 10:01:00');

    expect(app(SyncStatusService::class)->overallStatus($userId))->toBe(SyncOverallStatus::AllSynced);
});

// The surface, not the service: a case the blade had no branch for fell through
// to the final @else, which is the "All devices up to date" one.
it('draws the behind state as its own sentence and never as up to date', function (): void {
    $db = app(DatabaseManager::class);
    $user = offlineArmUser('offline-arm-render');
    $this->actingAs($user);

    offlineArmSelfDevice($db, (int) $user->id);
    offlineArmSession($db, (int) $user->id);
    offlineArmLocalOp($db, (int) $user->id, '2026-08-01 11:00:00');

    Livewire::test(SyncStatusSection::class)
        ->assertSet('overallStatus', 'behind')
        ->assertSee('Changes not yet sent')
        ->assertDontSee('All devices up to date');
});

it('draws an unreachable peer it once synced with as offline, not as up to date', function (): void {
    $db = app(DatabaseManager::class);
    $user = offlineArmUser('offline-arm-render-offline');
    $this->actingAs($user);

    offlineArmSession($db, (int) $user->id, [
        'status' => 'failed',
        'error_message' => 'connection timed out',
    ]);

    Livewire::test(SyncStatusSection::class)
        ->assertSet('overallStatus', 'offline')
        ->assertSee('Devices offline')
        ->assertDontSee('All devices up to date');
});
