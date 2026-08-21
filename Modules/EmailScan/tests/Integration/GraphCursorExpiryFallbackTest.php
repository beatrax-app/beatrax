<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Sleep;
use Modules\Core\Models\User;
use Modules\EmailScan\Internal\Clients\FakeGraphApiClient;
use Modules\EmailScan\Internal\Clients\GraphApiClientContract;
use Modules\EmailScan\Internal\Jobs\IncrementalScanJob;

uses(RefreshDatabase::class);

// Graph's $delta returns 410 / syncStateNotFound once the deltaLink token has
// aged out. The job falls back to a date-bounded /me/messages walk and then
// re-baselines with deltaPage(null), so the next tick has a link to walk from.

beforeEach(function (): void {
    Sleep::fake();

    $this->inboxRoot = storage_path('app/inbox');
    if (is_dir($this->inboxRoot)) {
        $this->app->make(Filesystem::class)->deleteDirectory($this->inboxRoot);
    }
});

afterEach(function (): void {
    if (is_dir($this->inboxRoot)) {
        $this->app->make(Filesystem::class)->deleteDirectory($this->inboxRoot);
    }
});

it('catches CursorExpiredException on deltaPage, falls back to listSenderMessagesPaged, re-baselines via deltaPage(null), and persists the messages', function (): void {
    $user = User::query()->create([
        'username' => 'graph-expired',
        'password' => 'fixture',
        'period_start_day' => 1,
    ]);

    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $now = CarbonImmutable::now()->toDateTimeString();

    $inboxId = (int) $db->connection()->table('inboxes')->insertGetId([
        'user_id' => $user->id,
        'provider' => 'microsoft',
        'email' => 'graph-expired@example.com',
        'backfill_window_months' => 3,
        'backfill_progress' => null,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    $db->connection()->table('inbox_scan_state')->insert([
        'user_id' => $user->id,
        'inbox_id' => $inboxId,
        'folder' => 'INBOX',
        'status' => 'idle',
        'last_delta_link' => 'https://graph.microsoft.com/v1.0/me/mailFolders/inbox/messages/$delta?$deltatoken=stale-xyz',
        'last_scan_at' => $now,
        'retry_attempts' => 0,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $fake = new FakeGraphApiClient($this->app->make(Filesystem::class));
    $fake->simulateCursorExpired($inboxId);
    $this->app->instance(GraphApiClientContract::class, $fake);

    /** @var IncrementalScanJob $job */
    $job = $this->app->make(IncrementalScanJob::class, ['inboxId' => $inboxId]);
    $this->app->call([$job, 'handle']);

    $rows = $db->connection()
        ->table('inbox_messages')
        ->where('inbox_id', $inboxId)
        ->count();
    expect($rows)->toBe(3);

    $methodSequence = array_map(
        static fn (array $c): string => (string) $c['method'],
        $fake->getRequestedCalls(),
    );

    $deltaCalls = array_values(array_filter(
        $fake->getRequestedCalls(),
        static fn (array $c): bool => $c['method'] === 'deltaPage',
    ));
    expect($deltaCalls)->toHaveCount(2);
    expect($deltaCalls[0]['args']['deltaLink'])->toContain('stale-xyz');
    // Null is the re-baseline: the stale link cannot be walked from again.
    expect($deltaCalls[1]['args']['deltaLink'])->toBeNull();

    $firstDeltaIdx = array_keys(array_filter(
        $methodSequence,
        static fn (string $m): bool => $m === 'deltaPage',
    ));
    $secondDeltaIdx = $firstDeltaIdx[1] ?? -1;
    $listIndices = array_keys(array_filter(
        $methodSequence,
        static fn (string $m): bool => $m === 'listSenderMessagesPaged',
    ));
    expect($listIndices)->not->toBe([]);
    foreach ($listIndices as $idx) {
        expect($idx)->toBeGreaterThan($firstDeltaIdx[0]);
        expect($idx)->toBeLessThan($secondDeltaIdx);
    }

    $scanState = $db->connection()
        ->table('inbox_scan_state')
        ->where('inbox_id', $inboxId)
        ->where('folder', 'INBOX')
        ->first(['status', 'last_delta_link']);
    expect($scanState)->not->toBeNull();
    expect($scanState->status)->toBe('idle');
    expect($scanState->last_delta_link)
        ->toBe('https://graph.microsoft.com/v1.0/me/messages/$delta?$deltatoken=baseline-xyz');
});
