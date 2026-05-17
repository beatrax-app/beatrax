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

uses(RefreshDatabase::class);

/*
 * IncrementalScanJob Gmail cursor-expiry fallback invariant.
 *
 * RESEARCH Pitfall 3: Gmail's users.history.list returns 404 when
 * the startHistoryId is older than the ~7-day retention window.
 * The Plan 07 contract is:
 *  1. Catch CursorExpiredException.
 *  2. Fall back to a date-bounded messages.list walk over the
 *     sender allow-list.
 *  3. Re-baseline the cursor (in production via a getProfile call;
 *     the Wave 0 contract pins the post-fallback historyId to the
 *     prior value so the next hour's tick retries listHistory).
 *
 * Test flow:
 *  1. Seed user + Gmail inbox + scan_state with last_history_id='12345'.
 *  2. Bind a FakeGmailApiClient with NO queued history response —
 *     the default 404 fixture fires CursorExpiredException.
 *  3. Dispatch the job.
 *  4. Assert: the FakeGmailApiClient's request log shows listHistory
 *     FIRST (which threw) THEN listSenderMessages (the fallback walk).
 *  5. Assert: 3 inbox_messages rows landed (the Fake's page-1 fixture
 *     carries 3 messages).
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

it('catches CursorExpiredException on listHistory, falls back to listSenderMessages, and persists the messages', function (): void {
    $user = User::query()->create([
        'email' => 'gmail-expired@example.com',
        'password' => 'fixture',
        'period_start_day' => 1,
    ]);

    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $now = CarbonImmutable::now()->toDateTimeString();

    $inboxId = (int) $db->connection()->table('inboxes')->insertGetId([
        'user_id' => $user->id,
        'provider' => 'gmail',
        'email' => 'gmail-expired@example.com',
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

    // No queued history response — listHistory falls back to the
    // 404 fixture → CursorExpiredException.
    $fake = new FakeGmailApiClient($this->app->make(Filesystem::class));
    $this->app->instance(GmailApiClientContract::class, $fake);

    /** @var IncrementalScanJob $job */
    $job = $this->app->make(IncrementalScanJob::class, ['inboxId' => $inboxId]);
    $this->app->call([$job, 'handle']);

    // Assert: 3 inbox_messages rows landed (from the fallback walk).
    $rows = $db->connection()
        ->table('inbox_messages')
        ->where('inbox_id', $inboxId)
        ->count();
    expect($rows)->toBe(3);

    // Assert: call sequence shows listHistory FIRST (the 404 throw),
    // then listSenderMessages (the fallback walk).
    $methodSequence = array_map(
        static fn (array $c): string => (string) $c['method'],
        $fake->getRequestedCalls(),
    );
    $listHistoryIndex = array_search('listHistory', $methodSequence, strict: true);
    $listSenderIndex = array_search('listSenderMessages', $methodSequence, strict: true);

    expect($listHistoryIndex)->not->toBeFalse();
    expect($listSenderIndex)->not->toBeFalse();
    expect($listSenderIndex)->toBeGreaterThan($listHistoryIndex);

    // Assert: status returned to idle after the fallback walk.
    $scanState = $db->connection()
        ->table('inbox_scan_state')
        ->where('inbox_id', $inboxId)
        ->where('folder', 'INBOX')
        ->first(['status', 'last_history_id']);
    expect($scanState)->not->toBeNull();
    expect($scanState->status)->toBe('idle');
    // Cursor preserved (we did not learn a fresh historyId from the
    // fallback walk; the next hour's run re-attempts listHistory).
    expect($scanState->last_history_id)->toBe('12345');

    // WR-07: the fallback walk must pass a date-bounded
    // $windowStart so the server-side `after:` filter trims the
    // result set tightly against last_scan_at - 7 days. A null
    // windowStart would walk the entire allow-list history capped
    // only by the 500-message defensive cap, contradicting the
    // class docblock's "capped at last_scan_at minus 7 days"
    // contract.
    $listSenderCall = null;
    foreach ($fake->getRequestedCalls() as $call) {
        if ($call['method'] === 'listSenderMessages') {
            $listSenderCall = $call;
            break;
        }
    }
    expect($listSenderCall)->not->toBeNull();
    expect($listSenderCall['args']['windowStart'])->not->toBeNull();
});
