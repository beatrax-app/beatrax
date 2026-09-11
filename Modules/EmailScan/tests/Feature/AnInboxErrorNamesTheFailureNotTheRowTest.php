<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\EmailScan\Internal\Jobs\BackfillInboxJob;
use Modules\EmailScan\Internal\Jobs\IncrementalScanJob;

uses(RefreshDatabase::class);

// inbox_scan_state.error_message is a plaintext column, read back through
// InboxQuery into a Public DTO. The retry budget is exhausted by the time
// failed() runs, so whatever the job threw last is what lands in it — and a
// scan job's queries carry the sender address and the subject as bindings.

/** @return array{string, string} the sender and the subject a failing insert bound */
function anInboxErrorsBoundRow(): array
{
    return ['bookings@clinic.example', 'Invoice for your appointment on 3 April'];
}

function anInboxErrorsQueryException(): QueryException
{
    [$sender, $subject] = anInboxErrorsBoundRow();

    return new QueryException(
        'sqlite',
        'insert into "inbox_messages" ("sender_email", "subject") values (?, ?)',
        [$sender, $subject],
        new PDOException('SQLSTATE[HY000]: General error: 5 database is locked'),
    );
}

function anInboxErrorsScanStateRow(int $inboxId): object
{
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    $row = $db->connection()
        ->table('inbox_scan_state')
        ->where('inbox_id', $inboxId)
        ->where('folder', 'INBOX')
        ->first(['status', 'error_message']);

    expect($row)->not->toBeNull('The fixture wrote no inbox_scan_state row, so this test asserts about nothing.');

    return (object) $row;
}

beforeEach(function (): void {
    $user = User::query()->create([
        'username' => 'inbox-error-reason',
        'password' => 'fixture',
        'period_start_day' => 1,
    ]);

    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $now = CarbonImmutable::now()->toDateTimeString();

    $this->inboxId = (int) $db->connection()->table('inboxes')->insertGetId([
        'user_id' => $user->id,
        'provider' => 'gmail',
        'email' => 'inbox-error-reason@example.com',
        'backfill_window_months' => 3,
        'backfill_progress' => null,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $db->connection()->table('inbox_scan_state')->insert([
        'user_id' => $user->id,
        'inbox_id' => $this->inboxId,
        'folder' => 'INBOX',
        'status' => 'idle',
        'retry_attempts' => 0,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
});

it('records why a backfill died without recording the row it died on', function (): void {
    [$sender, $subject] = anInboxErrorsBoundRow();

    (new BackfillInboxJob(inboxId: $this->inboxId, windowMonths: 3))->failed(anInboxErrorsQueryException());

    $row = anInboxErrorsScanStateRow($this->inboxId);
    $stored = (string) $row->error_message;

    expect($stored)->not->toContain($sender)
        ->and($stored)->not->toContain($subject)
        ->and($stored)->not->toContain('insert into')
        ->and($stored)->not->toContain('database is locked');

    // The positive control. Writing NULL, or the empty string, would satisfy
    // every assertion above while leaving the inbox strip with no reason to
    // show and support with nothing to read.
    expect($row->status)->toBe('error')
        ->and($stored)->toBe('QueryException');
});

it('records why an incremental scan died without recording the row it died on', function (): void {
    [$sender, $subject] = anInboxErrorsBoundRow();

    (new IncrementalScanJob(inboxId: $this->inboxId))->failed(anInboxErrorsQueryException());

    $row = anInboxErrorsScanStateRow($this->inboxId);
    $stored = (string) $row->error_message;

    expect($stored)->not->toContain($sender)
        ->and($stored)->not->toContain($subject)
        ->and($stored)->not->toContain('insert into');

    expect($row->status)->toBe('error')
        ->and($stored)->toBe('QueryException');
});
