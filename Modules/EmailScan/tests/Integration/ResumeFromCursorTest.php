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

// A worker crash mid-scan must neither lose nor duplicate messages: the next
// tick resumes from inbox_scan_state.last_history_id, which the second run
// here re-enters with nothing new to fetch.

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
        'username' => 'resume',
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

    // First run: two new message ids and a fresh historyId.
    $fake = new FakeGmailApiClient($this->app->make(Filesystem::class));
    $fake->queueHistoryResponse(['paypal-sample-receipt', 'ics-sample-statement-notice'], '12400');
    $this->app->instance(GmailApiClientContract::class, $fake);

    /** @var IncrementalScanJob $job */
    $job = $this->app->make(IncrementalScanJob::class, ['inboxId' => $inboxId]);
    $this->app->call([$job, 'handle']);

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

    // Second run stands in for a restarted process: an empty history response
    // from the advanced cursor, so the tick should be a no-op.
    $fake2 = new FakeGmailApiClient($this->app->make(Filesystem::class));
    $fake2->queueHistoryResponse([], '12400');
    $this->app->instance(GmailApiClientContract::class, $fake2);

    /** @var IncrementalScanJob $job2 */
    $job2 = $this->app->make(IncrementalScanJob::class, ['inboxId' => $inboxId]);
    $this->app->call([$job2, 'handle']);

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

    // '12400', not the seeded '12345': the restart resumed from the cursor the
    // first run wrote.
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
