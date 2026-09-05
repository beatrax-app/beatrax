<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\Sync\Public\Enums\SyncOverallStatus;
use Modules\Sync\Public\Services\SyncStatusService;

uses(RefreshDatabase::class);

// "All devices up to date" was read off the session state alone, so a change made
// after the session closed had been nowhere and the panel still said up to date.
// The fixtures keep the two formats the tables really write — sessions ISO8601
// with an offset, the op log a space — because ' ' sorts before 'T' as a string.

const SSP_SELF = 'self-device-id';

function sspUser(): User
{
    return User::query()->create([
        'username' => 'ssp-'.bin2hex(random_bytes(4)),
        'password' => 'fixture',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

function sspClosedSession(DatabaseManager $db, int $userId, string $lastSeen): void
{
    $db->connection()->table('sync_sessions')->insert([
        'user_id' => $userId,
        'local_device_id' => SSP_SELF,
        'peer_device_id' => 'peer-device-id',
        'status' => 'closed',
        'error_message' => null,
        'connected_at' => $lastSeen,
        'last_seen_at' => $lastSeen,
        'created_at' => $lastSeen,
        'updated_at' => $lastSeen,
    ]);

    $db->connection()->table('device_registry')->insert([
        'user_id' => $userId,
        'device_id' => SSP_SELF,
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

function sspOp(DatabaseManager $db, int $userId, string $deviceId, string $recordedAt): void
{
    $db->connection()->table('op_log_entries')->insert([
        'user_id' => $userId,
        'device_id' => $deviceId,
        'table_name' => 'goals',
        'pk' => '1',
        'field' => 'name',
        'op_type' => 'set',
        'value' => json_encode('Sync bewijs'),
        'hlc_l' => 1,
        'hlc_c' => 0,
        'signature' => str_repeat('c', 128),
        'recorded_at' => $recordedAt,
        'gdk_epoch' => 1,
    ]);
}

it('reports up to date when nothing has changed since the last session', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $user = sspUser();
    sspClosedSession($db, (int) $user->id, '2026-08-19T00:36:58+02:00');

    expect(app(SyncStatusService::class)->overallStatus((int) $user->id))->toBe(SyncOverallStatus::AllSynced);
});

it('does not report up to date while this device holds a change made since', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $user = sspUser();
    sspClosedSession($db, (int) $user->id, '2026-08-19T00:36:58+02:00');
    sspOp($db, (int) $user->id, SSP_SELF, '2026-08-19 00:42:56');

    expect(app(SyncStatusService::class)->overallStatus((int) $user->id))->toBe(SyncOverallStatus::Behind);
});

it('ignores ops that arrived FROM a peer — those are not ours to deliver', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $user = sspUser();
    sspClosedSession($db, (int) $user->id, '2026-08-19T00:36:58+02:00');
    sspOp($db, (int) $user->id, 'peer-device-id', '2026-08-19 00:42:56');

    expect(app(SyncStatusService::class)->overallStatus((int) $user->id))->toBe(SyncOverallStatus::AllSynced);
});

it('ignores our own ops that predate the last session, since they went with it', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $user = sspUser();
    sspClosedSession($db, (int) $user->id, '2026-08-19T00:36:58+02:00');
    sspOp($db, (int) $user->id, SSP_SELF, '2026-08-19 00:10:00');

    expect(app(SyncStatusService::class)->overallStatus((int) $user->id))->toBe(SyncOverallStatus::AllSynced);
});
