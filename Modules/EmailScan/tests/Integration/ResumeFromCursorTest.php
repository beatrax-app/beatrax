<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Sleep;
use Modules\Core\Models\User;
use Modules\EmailScan\Internal\Clients\FakeGmailApiClient;
use Modules\EmailScan\Internal\Clients\GmailApiClientContract;
use Modules\EmailScan\Internal\Jobs\IncrementalScanJob;
use Modules\EmailScan\Public\Services\EmlBlobStore;

uses(RefreshDatabase::class);

/*
 * IncrementalScanJob kill-restart resume invariant.
 *
 * Plan 07 success criterion SC#3: the hourly scanner must pick up
 * from inbox_scan_state.last_history_id (Gmail) on every run — a
 * worker crash mid-scan must not lose or duplicate messages on the
 * NEXT scheduler tick.
 *
 * Test flow:
 *  1. Seed user + Gmail inbox + inbox_scan_state with last_history_id='12345'.
 *  2. Bind FakeGmailApiClient with a queued listHistory response that
 *     returns 2 new messages (paypal + ics) + new historyId='12400'.
 *  3. Dispatch IncrementalScanJob — 2 inbox_messages rows land,
 *     last_history_id='12400', status='idle'.
 *  4. Dispatch the job AGAIN (simulating "process restarted") — the
 *     Fake's queue is empty, so listHistory falls back to the 404
 *     fixture → CursorExpiredException → fallback walk over
 *     listSenderMessages.
 *
 * The simpler kill-restart shape: run the job, kill, run again with
 * the same seed cursor unchanged — assert idempotency. We do that
 * here by queueing a SECOND empty history response (no new messages,
 * cursor unchanged).
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

it('walks the gmail historyId cursor, persists new messages, and resumes idempotently on a second run', function (): void {
    $user = User::query()->create([
        'email' => 'resume@example.com',
        'password' => 'fixture',
        'period_start_day' => 1,
    ]);

    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $now = CarbonImmutable::now()->toDateTimeString();

    $inboxId = (int) $db->connection()->table('inboxes')->insertGetId([
        'user_id' => $user->id,
        'provider' => 'gmail',
        'email' => 'resume@example.com',
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
        'last_history_id' => '12345',
        'last_scan_at' => $now,
        'retry_attempts' => 0,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    // First run — queue a success listHistory response with two
    // new message ids + a fresh historyId.
    $fake = new FakeGmailApiClient($this->app->make(Filesystem::class));
    $fake->queueHistoryResponse(['paypal-sample-receipt', 'ics-sample-statement-notice'], '12400');
    $this->app->instance(GmailApiClientContract::class, $fake);

    /** @var IncrementalScanJob $job */
    $job = $this->app->make(IncrementalScanJob::class, ['inboxId' => $inboxId]);
    $this->app->call([$job, 'handle']);

    // Assert: 2 inbox_messages rows + 2 .eml blobs + cursor advanced.
    $rows = $db->connection()
        ->table('inbox_messages')
        ->where('inbox_id', $inboxId)
        ->orderBy('internal_date', 'asc')
        ->get();
    expect($rows)->toHaveCount(2);

    /** @var EmlBlobStore $store */
    $store = $this->app->make(EmlBlobStore::class);
    expect($store->exists($store->pathFor(
        $user->id,
        $inboxId,
        new DateTimeImmutable('2026-05-11 09:14:21+00:00'),
        'paypal-sample-receipt',
    )))->toBeTrue();

    $scanState = $db->connection()
        ->table('inbox_scan_state')
        ->where('inbox_id', $inboxId)
        ->where('folder', 'INBOX')
        ->first(['status', 'last_history_id']);
    expect($scanState)->not->toBeNull();
    expect($scanState->status)->toBe('idle');
    expect($scanState->last_history_id)->toBe('12400');

    // Second run — simulating "process restarted, scanner re-picks
    // up from the new cursor". Queue an empty history response so
    // the second run is a no-op.
    $fake2 = new FakeGmailApiClient($this->app->make(Filesystem::class));
    $fake2->queueHistoryResponse([], '12400');
    $this->app->instance(GmailApiClientContract::class, $fake2);

    /** @var IncrementalScanJob $job2 */
    $job2 = $this->app->make(IncrementalScanJob::class, ['inboxId' => $inboxId]);
    $this->app->call([$job2, 'handle']);

    // Assert: still 2 rows (no duplicates landed on the second run),
    // cursor unchanged, status idle.
    $rowsAfter = $db->connection()
        ->table('inbox_messages')
        ->where('inbox_id', $inboxId)
        ->count();
    expect($rowsAfter)->toBe(2);

    $scanStateAfter = $db->connection()
        ->table('inbox_scan_state')
        ->where('inbox_id', $inboxId)
        ->where('folder', 'INBOX')
        ->first(['status', 'last_history_id']);
    expect($scanStateAfter)->not->toBeNull();
    expect($scanStateAfter->status)->toBe('idle');
    expect($scanStateAfter->last_history_id)->toBe('12400');

    // Assert: the second run's listHistory call started from the
    // updated cursor '12400' (not the seeded '12345').
    $secondRunCalls = $fake2->getRequestedCalls();
    $listHistoryCalls = array_values(array_filter(
        $secondRunCalls,
        static fn (array $c): bool => $c['method'] === 'listHistory',
    ));
    expect($listHistoryCalls)->toHaveCount(1);
    expect($listHistoryCalls[0]['args'])->toMatchArray([
        'inboxId' => $inboxId,
        'startHistoryId' => '12400',
    ]);
});
