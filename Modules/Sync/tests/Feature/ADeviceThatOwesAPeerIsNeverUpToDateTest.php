<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\Sync\Public\Enums\SyncOverallStatus;
use Modules\Sync\Public\Services\SyncStatusService;

uses(RefreshDatabase::class);

// "Changes not yet sent" was read off op_log_entries alone, and the two things
// a locked or freshly-paired device owes a peer have no op yet: a coordinate a
// keyless process could not sign, and a row the pre-sync walk has not reached.
// Neither had a surface of its own, and both read as up to date.

const DOP_SELF = 'owes-self-device-id';

function dopUser(): User
{
    return User::query()->create([
        'username' => 'dop-'.bin2hex(random_bytes(4)),
        'password' => 'fixture',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

function dopClosedSession(DatabaseManager $db, int $userId): void
{
    $lastSeen = '2026-09-01T09:00:00+02:00';

    $db->connection()->table('sync_sessions')->insert([
        'user_id' => $userId,
        'local_device_id' => DOP_SELF,
        'peer_device_id' => 'owes-peer-device-id',
        'status' => 'closed',
        'error_message' => null,
        'connected_at' => $lastSeen,
        'last_seen_at' => $lastSeen,
        'created_at' => $lastSeen,
        'updated_at' => $lastSeen,
    ]);

    $db->connection()->table('device_registry')->insert([
        'user_id' => $userId,
        'device_id' => DOP_SELF,
        'name' => 'This device',
        'ed25519_public_key_hex' => str_repeat('a', 64),
        'x25519_public_key_hex' => str_repeat('b', 64),
        'safety_number_words' => 'three spot buzz rich dove puzzle',
        'is_self' => true,
        'paired_at' => $lastSeen,
        'confirmed_at' => $lastSeen,
        'created_at' => $lastSeen,
        'updated_at' => $lastSeen,
    ]);
}

it('reports up to date when this device owes a peer nothing', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $user = dopUser();
    dopClosedSession($db, (int) $user->id);

    expect(app(SyncStatusService::class)->overallStatus((int) $user->id))->toBe(SyncOverallStatus::AllSynced);
});

it('is behind while a coordinate a keyless process left behind is still owed', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $user = dopUser();
    dopClosedSession($db, (int) $user->id);

    $db->connection()->table('deferred_op_captures')->insert([
        'user_id' => (int) $user->id,
        'table_name' => 'recurring_series',
        'pk' => '7',
        'field' => 'billing_day',
        'op_kind' => 'set',
        'delta' => null,
        'captured_at' => '2026-09-01T09:30:00Z',
    ]);

    expect(app(SyncStatusService::class)->overallStatus((int) $user->id))->toBe(SyncOverallStatus::Behind);
});

it('is behind while the pre-sync walk has rows left to reach', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $user = dopUser();
    dopClosedSession($db, (int) $user->id);

    $db->connection()->table('sync_backfill_state')->insert([
        'user_id' => (int) $user->id,
        'cursor_table' => 'transactions',
        'cursor_pk' => '412',
        'captured' => 412,
        'started_at' => '2026-09-01T09:30:00Z',
        'completed_at' => null,
        'updated_at' => '2026-09-01T09:31:00Z',
    ]);

    expect(app(SyncStatusService::class)->overallStatus((int) $user->id))->toBe(SyncOverallStatus::Behind);
});

it('is up to date again once the walk has closed', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $user = dopUser();
    dopClosedSession($db, (int) $user->id);

    $db->connection()->table('sync_backfill_state')->insert([
        'user_id' => (int) $user->id,
        'cursor_table' => 'transactions',
        'cursor_pk' => '412',
        'captured' => 412,
        'started_at' => '2026-09-01T09:30:00Z',
        'completed_at' => '2026-09-01T09:40:00Z',
        'updated_at' => '2026-09-01T09:40:00Z',
    ]);

    expect(app(SyncStatusService::class)->overallStatus((int) $user->id))->toBe(SyncOverallStatus::AllSynced);
});

it('never reads another reader\'s owed work as this one being behind', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $reader = dopUser();
    $other = dopUser();
    dopClosedSession($db, (int) $reader->id);

    $db->connection()->table('deferred_op_captures')->insert([
        'user_id' => (int) $other->id,
        'table_name' => 'goals',
        'pk' => '3',
        'field' => 'name',
        'op_kind' => 'set',
        'delta' => null,
        'captured_at' => '2026-09-01T09:30:00Z',
    ]);

    expect(app(SyncStatusService::class)->overallStatus((int) $reader->id))->toBe(SyncOverallStatus::AllSynced);
});
