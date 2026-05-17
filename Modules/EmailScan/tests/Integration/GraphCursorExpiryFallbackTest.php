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

/*
 * IncrementalScanJob Graph cursor-expiry fallback invariant.
 *
 * RESEARCH Pitfall 4: Microsoft Graph's $delta endpoint returns
 * HTTP 410 / syncStateNotFound when the @odata.deltaLink token has
 * aged out. The Plan 07 contract is:
 *  1. Catch CursorExpiredException.
 *  2. Fall back to a date-bounded /me/messages walk with the
 *     receivedDateTime >= last_scan_at - 7d filter.
 *  3. Re-baseline the cursor via a fresh deltaPage(null) call so
 *     the next hour's tick has a valid deltaLink to walk from.
 *
 * Test flow:
 *  1. Seed user + Microsoft inbox + scan_state with last_delta_link set.
 *  2. Bind a FakeGraphApiClient + arm simulateCursorExpired so the
 *     first deltaPage call throws.
 *  3. Dispatch the job.
 *  4. Assert call sequence: deltaPage(stored_link) → listSenderMessagesPaged
 *     (fallback walk) → deltaPage(null) (re-baseline).
 *  5. Assert: 3 inbox_messages rows landed; last_delta_link advanced to
 *     the baseline fixture's value.
 */

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
        'email' => 'graph-expired@example.com',
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

    // Arm the cursor-expired throw on the FIRST deltaPage call.
    $fake = new FakeGraphApiClient($this->app->make(Filesystem::class));
    $fake->simulateCursorExpired($inboxId);
    $this->app->instance(GraphApiClientContract::class, $fake);

    /** @var IncrementalScanJob $job */
    $job = $this->app->make(IncrementalScanJob::class, ['inboxId' => $inboxId]);
    $this->app->call([$job, 'handle']);

    // Assert: 3 inbox_messages rows landed (from the fallback walk
    // via listSenderMessagesPaged, which returns the page-1 fixture).
    $rows = $db->connection()
        ->table('inbox_messages')
        ->where('inbox_id', $inboxId)
        ->count();
    expect($rows)->toBe(3);

    // Assert: call sequence.
    //   1. deltaPage(stored_link)       — threw CursorExpiredException
    //   2. listSenderMessagesPaged      — fallback walk page 1 (3 messages)
    //   3. getRawMessage × 3            — raw .eml fetches
    //   4. listSenderMessagesPaged      — fallback walk page 2 (empty)
    //   5. deltaPage(null)              — re-baseline call
    $methodSequence = array_map(
        static fn (array $c): string => (string) $c['method'],
        $fake->getRequestedCalls(),
    );

    $deltaCalls = array_values(array_filter(
        $fake->getRequestedCalls(),
        static fn (array $c): bool => $c['method'] === 'deltaPage',
    ));
    expect($deltaCalls)->toHaveCount(2);
    // First deltaPage call must be the stored (stale) deltaLink.
    expect($deltaCalls[0]['args']['deltaLink'])->toContain('stale-xyz');
    // Second deltaPage call must be the baseline re-establish (null).
    expect($deltaCalls[1]['args']['deltaLink'])->toBeNull();

    // listSenderMessagesPaged appears between the two deltaPage calls.
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

    // Assert: last_delta_link advanced to the baseline fixture value.
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
