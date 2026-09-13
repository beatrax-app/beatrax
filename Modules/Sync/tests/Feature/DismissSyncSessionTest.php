<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Models\User;
use Modules\Core\Public\Exceptions\ColumnNotDeclaredException;
use Modules\Sync\Public\Enums\SyncOverallStatus;
use Modules\Sync\Public\Services\DeviceRegistryService;
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

it('removes a single recorded session and clears the state it was holding', function (): void {
    $db = app(DatabaseManager::class);
    $userId = dismissUser('dismiss-single');

    dismissSession($db, $userId, 'unknown', 'failed');

    /** @var SyncStatusService $status */
    $status = app(SyncStatusService::class);

    // "Connection failed" is a peer that cannot be reached, which is ordinary,
    // so the row holds the surface on offline rather than on error.
    expect($status->overallStatus($userId))->toBe(SyncOverallStatus::Offline);

    $status->forgetSession($userId, 'unknown');

    expect($status->peerStatuses($userId))->toBe([])
        ->and($status->overallStatus($userId))->toBe(SyncOverallStatus::Unknown, 'the state must go with the row');
});

it('sweeps sessions no confirmed device backs and keeps the live one', function (): void {
    $db = app(DatabaseManager::class);
    $userId = dismissUser('dismiss-orphans');

    dismissConfirmedDevice($db, $userId, 'live-peer');
    dismissSession($db, $userId, 'live-peer', 'active');
    dismissSession($db, $userId, 'removed-peer-a', 'failed');
    dismissSession($db, $userId, 'removed-peer-b', 'closed');

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

// The window this delete is dangerous in. SQLite reads a double-quoted name
// matching no column as a string literal, so `whereNull('self_retired_at')` is
// false for every row while that migration has not run: the confirmed list
// comes back empty and `whereNotIn` on an empty set compiles to `1 = 1`.
it('refuses to sweep while it cannot tell which devices are confirmed', function (): void {
    $db = app(DatabaseManager::class);
    $userId = dismissUser('dismiss-unmigrated');

    dismissConfirmedDevice($db, $userId, 'live-peer');
    dismissSession($db, $userId, 'live-peer', 'active');

    Schema::table('device_registry', fn (Blueprint $table) => $table->dropColumn('self_retired_at'));

    /** @var SyncStatusService $status */
    $status = app(SyncStatusService::class);

    expect(fn () => $status->forgetOrphanedSessions($userId))
        ->toThrow(ColumnNotDeclaredException::class);
});

// The consequence, pinned apart from the refusal that prevents it. Without the
// schema question this deletes the live session too -- the whole list, scoped
// by user_id alone -- so the row count is the assertion that has to stand on
// its own rather than behind an expectation about an exception.
it('leaves a live session standing while it cannot tell which devices are confirmed', function (): void {
    $db = app(DatabaseManager::class);
    $userId = dismissUser('dismiss-unmigrated-keeps');

    dismissConfirmedDevice($db, $userId, 'live-peer');
    dismissSession($db, $userId, 'live-peer', 'active');

    Schema::table('device_registry', fn (Blueprint $table) => $table->dropColumn('self_retired_at'));

    /** @var SyncStatusService $status */
    $status = app(SyncStatusService::class);

    try {
        $status->forgetOrphanedSessions($userId);
    } catch (ColumnNotDeclaredException) {
        // The refusal is the sibling test's subject. What matters here is that
        // nothing was deleted on the way to it.
    }

    expect($status->peerStatuses($userId))->toHaveCount(1, 'an unscoped delete would have taken this row');
});

// Zero confirmed devices is a real state and every session really is history
// there, so the empty case still deletes. Refusing it would put back the defect
// this method was written for: rows nothing could clear.
it('still sweeps every session when no device is confirmed and the schema says so', function (): void {
    $db = app(DatabaseManager::class);
    $userId = dismissUser('dismiss-none-confirmed');

    dismissSession($db, $userId, 'removed-peer-a', 'failed');
    dismissSession($db, $userId, 'removed-peer-b', 'closed');

    /** @var SyncStatusService $status */
    $status = app(SyncStatusService::class);

    expect($status->forgetOrphanedSessions($userId))->toBe(2)
        ->and($status->peerStatuses($userId))->toBe([]);
});

// The seam. Its one line is invisible to the arch guard that covers the inline
// sites -- it takes a Builder somebody else built, so no statement there names
// the table -- which is why it is asserted directly instead.
it('refuses at the seam, so every reader asking who my devices are refuses with it', function (): void {
    $db = app(DatabaseManager::class);
    $userId = dismissUser('dismiss-seam');

    dismissConfirmedDevice($db, $userId, 'live-peer');

    Schema::table('device_registry', fn (Blueprint $table) => $table->dropColumn('self_retired_at'));

    /** @var DeviceRegistryService $devices */
    $devices = app(DeviceRegistryService::class);

    expect(fn () => $devices->confirmedDevices($userId))
        ->toThrow(ColumnNotDeclaredException::class);
});

it('answers normally at the seam once the column is there', function (): void {
    $db = app(DatabaseManager::class);
    $userId = dismissUser('dismiss-seam-ok');

    dismissConfirmedDevice($db, $userId, 'live-peer');

    /** @var DeviceRegistryService $devices */
    $devices = app(DeviceRegistryService::class);

    expect($devices->confirmedDevices($userId))->toHaveCount(1);
});
