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

// failed() runs after the worker exhausts its retry budget. It flips the row
// to 'error', and an invalid transition inside it must not escalate into a
// queue-worker error of its own.

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

    // The machine rejects needs_reauth → error, and the hook has to swallow
    // that rather than escalate a recovery scenario into a hard error.
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
    // An inbox deleted mid-flight has no scan-state row for applyStatus to
    // find, and that is not a queue-worker error either.
    $job = new BackfillInboxJob(inboxId: 99999, windowMonths: 3);
    /** @var InboxScanStateMachine $sm */
    $sm = $this->app->make(InboxScanStateMachine::class);

    $job->failed(
        new RuntimeException('vanished mid-flight.'),
        $sm,
        $this->app->make(LoggerInterface::class),
    );

    expect(true)->toBeTrue(); // side-effect: no throw
});
