<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\Sync\Public\Services\SyncStatusService;

// sync_sessions rows outlive the registry rows they name, so a failed
// handshake or a since-removed peer stayed on the settings screen forever,
// holding the whole section in an error state with no control to clear it.

function dismissUser(string $username): int
{
    return (int) User::query()->create([
        'username' => $username,
        'password' => bcrypt('fixture'),
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ])->id;
}

function dismissSession(DatabaseManager $db, int $userId, string $peerDeviceId, string $status): void
{
    $db->connection()->table('sync_sessions')->insert([
        'user_id' => $userId,
        'local_device_id' => 'self-device',
        'peer_device_id' => $peerDeviceId,
        'status' => $status,
        'error_message' => $status === 'failed' ? 'Connection failed' : null,
        'connected_at' => null,
        'last_seen_at' => '2026-08-14T10:00:00Z',
        'created_at' => '2026-08-14T10:00:00Z',
        'updated_at' => '2026-08-14T10:00:00Z',
    ]);
}

function dismissConfirmedDevice(DatabaseManager $db, int $userId, string $deviceId): void
{
    $db->connection()->table('device_registry')->insert([
        'user_id' => $userId,
        'device_id' => $deviceId,
        'name' => 'Live peer',
        'ed25519_public_key_hex' => sodium_bin2hex(sodium_crypto_sign_publickey(sodium_crypto_sign_keypair())),
        'x25519_public_key_hex' => sodium_bin2hex(sodium_crypto_box_publickey(sodium_crypto_box_keypair())),
        'safety_number_words' => 'abandon ability able about above absent',
        'is_self' => 0,
        'paired_at' => '2026-08-01T10:00:00Z',
        'confirmed_at' => '2026-08-01T10:05:00Z',
        'created_at' => '2026-08-01T10:00:00Z',
        'updated_at' => '2026-08-01T10:00:00Z',
    ]);
}

it('removes a single recorded session and clears the error it was holding', function (): void {
    $db = app(DatabaseManager::class);
    $userId = dismissUser('dismiss-single');

    dismissSession($db, $userId, 'unknown', 'failed');

    /** @var SyncStatusService $status */
    $status = app(SyncStatusService::class);

    expect($status->overallStatus($userId))->toBe('error');

    $status->forgetSession($userId, 'unknown');

    expect($status->peerStatuses($userId))->toBe([])
        ->and($status->overallStatus($userId))->toBe('unknown', 'the error must go with the row');
});

it('sweeps sessions no confirmed device backs and keeps the live one', function (): void {
    $db = app(DatabaseManager::class);
    $userId = dismissUser('dismiss-orphans');

    dismissConfirmedDevice($db, $userId, 'live-peer');
    dismissSession($db, $userId, 'live-peer', 'active');
    dismissSession($db, $userId, 'removed-peer-a', 'failed');
    dismissSession($db, $userId, 'removed-peer-b', 'offline');

    /** @var SyncStatusService $status */
    $status = app(SyncStatusService::class);

    expect($status->forgetOrphanedSessions($userId))->toBe(2);

    $remaining = array_map(
        static fn (object $row): string => (string) $row->peer_device_id,
        $status->peerStatuses($userId),
    );

    expect($remaining)->toBe(['live-peer'], 'a session with a device behind it is not orphaned');
});

it('never sweeps a session while its device is still confirmed', function (): void {
    $db = app(DatabaseManager::class);
    $userId = dismissUser('dismiss-keeps-live');

    dismissConfirmedDevice($db, $userId, 'only-peer');
    dismissSession($db, $userId, 'only-peer', 'failed');

    /** @var SyncStatusService $status */
    $status = app(SyncStatusService::class);

    // A failing peer that is still paired is a problem to see, not to hide.
    expect($status->forgetOrphanedSessions($userId))->toBe(0)
        ->and($status->peerStatuses($userId))->toHaveCount(1);
});

it('ignores an empty device id rather than deleting the whole list', function (): void {
    $db = app(DatabaseManager::class);
    $userId = dismissUser('dismiss-empty-id');

    dismissSession($db, $userId, 'peer-one', 'failed');

    /** @var SyncStatusService $status */
    $status = app(SyncStatusService::class);
    $status->forgetSession($userId, '');

    expect($status->peerStatuses($userId))->toHaveCount(1);
});
