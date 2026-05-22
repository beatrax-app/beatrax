<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\EmailScan\Internal\InboxScanStateMachine;
use Modules\EmailScan\Internal\Jobs\BackfillInboxJob;
use Modules\EmailScan\Internal\Jobs\IncrementalScanJob;
use Psr\Log\LoggerInterface;

uses(RefreshDatabase::class);

/*
 * Per-job failed() hook invariant.
 *
 * BackfillInboxJob + IncrementalScanJob each define a public failed()
 * method that Laravel calls on the resolved job instance after the
 * worker exhausts its retry budget. The method flips
 * inbox_scan_state.status to 'error' with the truncated exception
 * message and does not escalate an invalid transition (e.g. an
 * already-needs_reauth row failing again) into a queue-worker error.
 *
 * Test flow per job:
 *  1. Seed user + inbox + inbox_scan_state with status='idle'.
 *  2. Instantiate the job with the seeded inboxId.
 *  3. Invoke failed() with a RuntimeException and the resolved
 *     collaborators.
 *  4. Assert the row's status flipped to 'error' and the error_message
 *     carries the exception's truncated text.
 */

beforeEach(function (): void {
    $this->user = User::query()->create([
        'username' => 'failed-hook',
        'password' => 'fixture',
        'period_start_day' => 1,
    ]);

    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $now = CarbonImmutable::now()->toDateTimeString();

    $this->inboxId = (int) $db->connection()->table('inboxes')->insertGetId([
        'user_id' => $this->user->id,
        'provider' => 'gmail',
        'email' => 'failed-hook@example.com',
        'backfill_window_months' => 3,
        'backfill_progress' => null,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    $db->connection()->table('inbox_scan_state')->insert([
        'user_id' => $this->user->id,
        'inbox_id' => $this->inboxId,
        'folder' => 'INBOX',
        'status' => 'idle',
        'retry_attempts' => 0,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
});

it('BackfillInboxJob::failed() flips inbox_scan_state.status to error with truncated message', function (): void {
    $job = new BackfillInboxJob(inboxId: $this->inboxId, windowMonths: 3);
    /** @var InboxScanStateMachine $sm */
    $sm = $this->app->make(InboxScanStateMachine::class);

    $job->failed(
        new RuntimeException('Synthetic backfill failure.'),
        $sm,
        $this->app->make(LoggerInterface::class),
    );

    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $row = $db->connection()
        ->table('inbox_scan_state')
        ->where('inbox_id', $this->inboxId)
        ->where('folder', 'INBOX')
        ->first(['status', 'error_message']);

    expect($row)->not->toBeNull();
    expect($row->status)->toBe('error');
    expect((string) $row->error_message)->toContain('Synthetic backfill failure');
});

it('IncrementalScanJob::failed() flips inbox_scan_state.status to error with truncated message', function (): void {
    $job = new IncrementalScanJob(inboxId: $this->inboxId);
    /** @var InboxScanStateMachine $sm */
    $sm = $this->app->make(InboxScanStateMachine::class);

    $job->failed(new RuntimeException('Synthetic incremental failure.'), $sm);

    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $row = $db->connection()
        ->table('inbox_scan_state')
        ->where('inbox_id', $this->inboxId)
        ->where('folder', 'INBOX')
        ->first(['status', 'error_message']);

    expect($row)->not->toBeNull();
    expect($row->status)->toBe('error');
    expect((string) $row->error_message)->toContain('Synthetic incremental failure');
});

it('IncrementalScanJob::failed() swallows invalid transitions (needs_reauth → error is rejected)', function (): void {
    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $db->connection()
        ->table('inbox_scan_state')
        ->where('inbox_id', $this->inboxId)
        ->where('folder', 'INBOX')
        ->update([
            'status' => 'needs_reauth',
            'error_message' => 'pre-existing reauth required',
        ]);

    $job = new IncrementalScanJob(inboxId: $this->inboxId);
    /** @var InboxScanStateMachine $sm */
    $sm = $this->app->make(InboxScanStateMachine::class);

    // The state machine rejects needs_reauth → error; the failed()
    // hook MUST swallow the exception so the queue-worker does not
    // escalate a recovery scenario into a hard error.
    $job->failed(new RuntimeException('subsequent failure during reauth grace.'), $sm);

    $row = $db->connection()
        ->table('inbox_scan_state')
        ->where('inbox_id', $this->inboxId)
        ->where('folder', 'INBOX')
        ->first(['status', 'error_message']);

    expect($row)->not->toBeNull();
    expect($row->status)->toBe('needs_reauth');
    expect((string) $row->error_message)->toBe('pre-existing reauth required');
});

it('BackfillInboxJob::failed() on a non-existent inbox swallows the RuntimeException', function (): void {
    // The state machine raises RuntimeException when applyStatus is
    // called for an inbox with no inbox_scan_state row. The failed()
    // hook must swallow this too — a deleted-mid-flight inbox is not
    // a queue-worker error.
    $job = new BackfillInboxJob(inboxId: 99999, windowMonths: 3);
    /** @var InboxScanStateMachine $sm */
    $sm = $this->app->make(InboxScanStateMachine::class);

    // Must not throw.
    $job->failed(
        new RuntimeException('vanished mid-flight.'),
        $sm,
        $this->app->make(LoggerInterface::class),
    );

    expect(true)->toBeTrue(); // side-effect: no throw
});
