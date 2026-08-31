<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Sync\Public\Services\SyncStatusService;

uses(RefreshDatabase::class);

// "Has this device written anything since the last session closed" is one
// MAX(). It was asked by plucking every recorded_at this device had ever
// written and running CarbonImmutable::parse() on each of them, on a screen
// the phone mounts unconditionally — 200,000 entries exhausted its 128 MB.

const SLO_SELF = 'latest-op-self-device';

function sloUser(): int
{
    return (int) DB::table('users')->insertGetId([
        'username' => 'slo-'.bin2hex(random_bytes(4)),
        'password' => 'fixture',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

function sloClosedSession(int $userId, string $lastSeen): void
{
    DB::table('sync_sessions')->insert([
        'user_id' => $userId,
        'local_device_id' => SLO_SELF,
        'peer_device_id' => 'latest-op-peer',
        'status' => 'closed',
        'error_message' => null,
        'connected_at' => $lastSeen,
        'last_seen_at' => $lastSeen,
        'created_at' => $lastSeen,
        'updated_at' => $lastSeen,
    ]);

    DB::table('device_registry')->insert([
        'user_id' => $userId,
        'device_id' => SLO_SELF,
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

// Chunked so the fixture never itself holds what the assertion measures.
function sloOps(int $userId, int $count, string $recordedAt): void
{
    $chunk = [];

    for ($i = 0; $i < $count; $i++) {
        $chunk[] = [
            'user_id' => $userId,
            'device_id' => SLO_SELF,
            'table_name' => 'goals',
            'pk' => (string) $i,
            'field' => 'name',
            'op_type' => 'set',
            'value' => '"'.str_repeat('v', 80).'"',
            'hlc_l' => 1000 + $i,
            'hlc_c' => 0,
            'signature' => str_repeat('c', 128),
            'recorded_at' => $recordedAt,
        ];

        if (count($chunk) === 500) {
            DB::table('op_log_entries')->insert($chunk);
            $chunk = [];
        }
    }

    if ($chunk !== []) {
        DB::table('op_log_entries')->insert($chunk);
    }
}

it('asks the database for the newest local op rather than reading all of them', function (): void {
    $userId = sloUser();
    sloClosedSession($userId, '2026-08-19T00:36:58+02:00');
    sloOps($userId, 20_000, '2026-08-19 06:00:00');

    /** @var list<string> $opLogQueries */
    $opLogQueries = [];
    DB::listen(function (QueryExecuted $q) use (&$opLogQueries): void {
        if (str_contains($q->sql, 'op_log_entries')) {
            $opLogQueries[] = $q->sql;
        }
    });

    gc_collect_cycles();
    $before = memory_get_usage(true);

    $status = app(SyncStatusService::class)->overallStatus($userId);

    // 20,000 rows cost roughly 12 MB as plucked strings before a single
    // Carbon is built from them; an aggregate costs nothing that scales.
    expect($status)->toBe('syncing')
        ->and(memory_get_usage(true) - $before)->toBeLessThan(6 * 1024 * 1024)
        ->and($opLogQueries)->toHaveCount(1)
        ->and($opLogQueries[0])->toContain('max("recorded_at")');
});

it('still compares the two timestamp formats as instants rather than as strings', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $userId = sloUser();

    // sync_sessions writes ISO8601 with an offset and op_log_entries writes a
    // space-separated stamp, so ' ' sorting before 'T' made every local op
    // look older than the session it came after.
    sloClosedSession($userId, '2026-08-19T00:36:58+02:00');
    sloOps($userId, 1, '2026-08-18 20:00:00');

    expect(app(SyncStatusService::class)->overallStatus($userId))->toBe('all_synced')
        ->and($db->connection()->table('op_log_entries')->count())->toBe(1);
});
