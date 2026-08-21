<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Sync\Public\Services\SyncStatusService;

uses(RefreshDatabase::class);

// SyncStatusSectionTest never reaches 'offline' and asserts lastSyncedHuman()
// only as null. Both gaps matter to whoever reads the panel: offline versus
// all_synced is the difference between "your devices are up to date" and
// "nothing has connected", and the relative time says how stale that answer is.

function sssSeed(int $userId, array $rows): void
{
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    foreach ($rows as $i => $row) {
        $db->connection()->table('sync_sessions')->insert(array_merge([
            'user_id' => $userId,
            'local_device_id' => 'this-device',
            'peer_device_id' => 'peer-'.$i,
            'status' => 'closed',
            'error_message' => null,
            'last_seen_at' => null,
            'created_at' => '2026-07-19 06:00:00',
            'updated_at' => '2026-07-19 06:00:00',
        ], $row));
    }
}

function sssService(): SyncStatusService
{
    return app(SyncStatusService::class);
}

it('reports unknown when the user has no sessions at all', function (): void {
    expect(sssService()->overallStatus(1))->toBe('unknown');
});

// A row that errored outranks one that is mid-handshake, because a peer needing
// attention should not be hidden behind one that is merely busy.
it('ranks an errored peer above a syncing one, and a syncing one above a finished one', function (array $rows, string $expected): void {
    sssSeed(1, $rows);

    expect(sssService()->overallStatus(1))->toBe($expected);
})->with([
    'failed with a message' => [[['status' => 'failed', 'error_message' => 'handshake rejected']], 'error'],
    'error outranks syncing' => [[
        ['status' => 'active'],
        ['status' => 'failed', 'error_message' => 'handshake rejected'],
    ], 'error'],
    'connecting' => [[['status' => 'connecting']], 'syncing'],
    'handshaking' => [[['status' => 'handshaking']], 'syncing'],
    'active' => [[['status' => 'active']], 'syncing'],
    'syncing outranks finished' => [[
        ['status' => 'closed'],
        ['status' => 'connecting'],
    ], 'syncing'],
    'closed' => [[['status' => 'closed']], 'all_synced'],
    'failed but seen before' => [[['status' => 'failed', 'last_seen_at' => '2026-07-19 05:00:00']], 'all_synced'],
]);

// A failure that never connected is not a completed sync: calling it all_synced
// would claim the devices agree when nothing ever reached the other end.
it('reports offline for a peer that failed without ever being seen', function (): void {
    sssSeed(1, [['status' => 'failed', 'error_message' => '', 'last_seen_at' => null]]);

    expect(sssService()->overallStatus(1))->toBe('offline');
});

it('scopes the status to the asking user', function (): void {
    sssSeed(1, [['status' => 'closed']]);
    sssSeed(2, [['status' => 'failed', 'error_message' => 'not yours']]);

    expect(sssService()->overallStatus(1))->toBe('all_synced')
        ->and(sssService()->overallStatus(2))->toBe('error');
});

it('has no last-synced time before any peer has been seen', function (): void {
    sssSeed(1, [['status' => 'connecting', 'last_seen_at' => null]]);

    expect(sssService()->lastSyncedHuman(CarbonImmutable::parse('2026-07-19 06:00:00'), 1))->toBeNull();
});

// Carbon truncates rather than rounds, so 119 seconds is one minute and not two.
// The strings are its short forms, which is what makes them translate; the
// ladder they replaced returned English literals whatever the locale.
it('renders the gap since the newest last_seen_at', function (string $seenAt, ?string $expected): void {
    sssSeed(1, [['status' => 'closed', 'last_seen_at' => $seenAt]]);

    $now = CarbonImmutable::parse('2026-07-19 12:00:00');

    expect(sssService()->lastSyncedHuman($now, 1))->toBe($expected);
})->with([
    'same instant' => ['2026-07-19 12:00:00', 'just now'],
    'a second short of a minute' => ['2026-07-19 11:59:01', '59s ago'],
    'exactly a minute' => ['2026-07-19 11:59:00', '1m ago'],
    'truncates rather than rounds' => ['2026-07-19 11:58:01', '1m ago'],
    'a minute short of an hour' => ['2026-07-19 11:01:00', '59m ago'],
    'exactly an hour' => ['2026-07-19 11:00:00', '1h ago'],
    'a day short of counting days' => ['2026-07-18 13:00:00', '23h ago'],
    'exactly a day' => ['2026-07-18 12:00:00', '1d ago'],
    'two days' => ['2026-07-17 12:00:00', '2d ago'],
]);

// The newest wins regardless of the order rows come back in, which is the
// whole reason the comparison is a max rather than a first-row read.
it('takes the newest last_seen_at across peers', function (): void {
    sssSeed(1, [
        ['status' => 'closed', 'last_seen_at' => '2026-07-17 12:00:00'],
        ['status' => 'closed', 'last_seen_at' => '2026-07-19 11:00:00'],
        ['status' => 'closed', 'last_seen_at' => '2026-07-18 12:00:00'],
    ]);

    expect(sssService()->lastSyncedHuman(CarbonImmutable::parse('2026-07-19 12:00:00'), 1))->toBe('1h ago');
});

it('ignores peers with no last_seen_at when picking the newest', function (): void {
    sssSeed(1, [
        ['status' => 'connecting', 'last_seen_at' => null],
        ['status' => 'closed', 'last_seen_at' => '2026-07-19 11:00:00'],
    ]);

    expect(sssService()->lastSyncedHuman(CarbonImmutable::parse('2026-07-19 12:00:00'), 1))->toBe('1h ago');
});
