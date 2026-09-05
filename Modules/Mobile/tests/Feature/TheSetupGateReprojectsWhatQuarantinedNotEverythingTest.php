<?php

declare(strict_types=1);

use Illuminate\Contracts\Session\Session;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Modules\Auth\Public\Testing\AppLockTestHarness;
use Modules\Core\Models\User;
use Modules\Core\Public\Services\UserDataPathService;
use Modules\Mobile\Internal\Sync\InitialSyncPuller;
use Modules\Mobile\Internal\Sync\SyncPhase;
use Modules\Sync\Internal\Crypto\GdkKeyringService;
use Modules\Sync\Internal\Identity\DeviceIdentityService;
use Modules\Sync\Internal\Transport\Relay\RelayConfig;
use Psr\Log\AbstractLogger;

uses(RefreshDatabase::class);

// The gate's re-projection ran OpLogRebuilder over the whole persisted op log:
// every entry hydrated into an object at ~645 bytes each, inside one
// transaction, on a wire:poll tick. 200,000 of them exhausted the phone's
// 128 MB, and memory exhaustion is E_ERROR — the catch below it never ran, the
// stamp was never written, and the next tick redid the same doomed work.

function gateUser(): User
{
    return User::query()->create([
        'username' => 'gate-reproject-'.bin2hex(random_bytes(4)),
        'password' => bcrypt('gate-reproject-pass'),
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

function gatePeer(DatabaseManager $db, int $userId): string
{
    $peerDeviceId = 'desktop-gate-'.bin2hex(random_bytes(4));

    $db->connection()->table('device_registry')->insert([
        'user_id' => $userId,
        'device_id' => $peerDeviceId,
        'name' => 'Fixture Desktop',
        'ed25519_public_key_hex' => bin2hex(random_bytes(32)),
        'x25519_public_key_hex' => bin2hex(random_bytes(32)),
        'safety_number_words' => 'one two three four five six',
        'is_self' => 0,
        'paired_at' => '2026-07-01 00:00:00',
        'confirmed_at' => '2026-07-01 00:00:00',
        'created_at' => '2026-07-01 00:00:00',
        'updated_at' => '2026-07-01 00:00:00',
    ]);

    return $peerDeviceId;
}

function gateArrivedHistory(DatabaseManager $db, int $userId, string $deviceId, int $count): void
{
    $batch = [];

    for ($i = 1; $i <= $count; $i++) {
        $batch[] = [
            'user_id' => $userId,
            'device_id' => $deviceId,
            'table_name' => 'categories',
            'pk' => (string) (5000 + $i),
            'field' => 'name',
            'op_type' => 'set',
            'value' => '"'.str_repeat('v', 80).'"',
            'hlc_l' => $i,
            'hlc_c' => 0,
            'signature' => str_repeat('ab', 64),
            'recorded_at' => '2026-07-10 00:00:00',
        ];

        if (count($batch) === 250) {
            $db->connection()->table('op_log_entries')->insert($batch);
            $batch = [];
        }
    }

    if ($batch !== []) {
        $db->connection()->table('op_log_entries')->insert($batch);
    }
}

// A phone that has just paired, holds a confirmed peer and an unlocked
// app-lock, and has taken delivery of the desktop's epoch.
/**
 * @return array{0: int, 1: string, 2: Session}
 */
function gateReadyToReproject(int $entries): array
{
    $user = gateUser();
    $userId = (int) $user->id;

    /** @var Session $session */
    $session = app(Session::class);
    AppLockTestHarness::unlock($session, str_repeat("\x2a", 32));

    @unlink(UserDataPathService::appPath('sync/identity/'.$userId.'.enc'));
    @unlink(UserDataPathService::appPath('sync/gdk/'.$userId.'.enc'));

    app(DeviceIdentityService::class)->generateAndPersist($userId, $session);

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $peerDeviceId = gatePeer($db, $userId);

    gateArrivedHistory($db, $userId, $peerDeviceId, $entries);

    app(GdkKeyringService::class)->generateAndPersist($userId, $session);

    /** @var RelayConfig $relayConfig */
    $relayConfig = app(RelayConfig::class);
    $relayConfig->setEndpointUrl('https://relay.fixture.test');
    Http::fake(['relay.fixture.test/*' => Http::response(['blobs' => []], 200)]);

    return [$userId, $peerDeviceId, $session];
}

it('re-projects a twenty-thousand entry history without ever reading the whole log', function (): void {
    [$userId, $peerDeviceId, $session] = gateReadyToReproject(20_000);

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    /** @var InitialSyncPuller $puller */
    $puller = app(InitialSyncPuller::class);

    expect($puller->pull($userId, $session)['phase'])->toBe(SyncPhase::Rebuilding);

    /** @var list<string> $wholeLogReads */
    $wholeLogReads = [];
    DB::listen(function (QueryExecuted $q) use (&$wholeLogReads): void {
        if (str_contains($q->sql, 'select * from "op_log_entries"')) {
            $wholeLogReads[] = $q->sql;
        }
    });

    memory_reset_peak_usage();
    $before = memory_get_usage(true);

    $done = $puller->pull($userId, $session);

    $peakDelta = memory_get_peak_usage(true) - $before;

    $cursor = $db->connection()->table('mobile_sync_progress')
        ->where('user_id', $userId)
        ->where('peer_device_id', $peerDeviceId)
        ->first();

    expect($done['phase'])->toBe(SyncPhase::Complete)
        ->and($cursor->reprojected_at)->not->toBeNull()
        // Nothing quarantined, so nothing needs re-projecting, so nothing is
        // read. The whole-log rebuild read all 20,000 rows to arrive here.
        ->and($wholeLogReads)->toBe([])
        ->and($peakDelta)->toBeLessThan(12 * 1024 * 1024);
});

it('counts the attempt before it runs, so one that never returns is named on the next tick', function (): void {
    [$userId, $peerDeviceId, $session] = gateReadyToReproject(10);

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    expect(app(InitialSyncPuller::class)->pull($userId, $session)['phase'])->toBe(SyncPhase::Rebuilding);

    $recorder = new class extends AbstractLogger
    {
        /** @var list<array{level: string, message: string}> */
        public array $records = [];

        public function log($level, string|Stringable $message, array $context = []): void
        {
            $this->records[] = ['level' => (string) $level, 'message' => (string) $message];
        }
    };

    Log::swap($recorder);
    app()->forgetInstance(InitialSyncPuller::class);

    // The state a killed pass leaves behind: an attempt on disk and no stamp.
    // Nothing else distinguishes it from the tick that is about to try, which
    // is why the count is written before the pass rather than after it.
    $db->connection()->table('mobile_sync_progress')
        ->where('user_id', $userId)
        ->where('peer_device_id', $peerDeviceId)
        ->update(['reproject_attempts' => 1]);

    app(InitialSyncPuller::class)->pull($userId, $session);

    $messages = array_column(array_values(array_filter(
        $recorder->records,
        static fn (array $r): bool => $r['level'] === 'error',
    )), 'message');

    expect($messages)->toContain('InitialSyncPuller: a previous history re-projection never returned; starting another.')
        ->and((int) $db->connection()->table('mobile_sync_progress')
            ->where('user_id', $userId)
            ->where('peer_device_id', $peerDeviceId)
            ->value('reproject_attempts'))->toBe(2);
});
