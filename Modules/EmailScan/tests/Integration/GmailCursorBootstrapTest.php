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
use Modules\EmailScan\Internal\Jobs\BackfillInboxJob;
use Modules\EmailScan\Internal\Jobs\IncrementalScanJob;

uses(RefreshDatabase::class);

// users.messages.list carries no historyId, so nothing on the backfill's
// page-walk can baseline the cursor. Without a baseline every Gmail
// incremental tick returned before its first API call.

beforeEach(function (): void {
    Sleep::fake();

    $this->inboxRoot = storage_path('app/inbox');
    if (is_dir($this->inboxRoot)) {
        $this->app->make(Filesystem::class)->deleteDirectory($this->inboxRoot);
    }

    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $this->db = $db;
    $now = CarbonImmutable::now()->toDateTimeString();

    $user = User::query()->create([
        'username' => 'cursor-bootstrap-'.bin2hex(random_bytes(4)),
        'password' => 'fixture',
        'period_start_day' => 1,
    ]);

    $this->inboxId = (int) $db->connection()->table('inboxes')->insertGetId([
        'user_id' => $user->id,
        'provider' => 'gmail',
        'email' => 'cursor-bootstrap@example.com',
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

    $this->fake = new FakeGmailApiClient($this->app->make(Filesystem::class));
    $this->app->instance(GmailApiClientContract::class, $this->fake);
});

afterEach(function (): void {
    if (is_dir($this->inboxRoot)) {
        $this->app->make(Filesystem::class)->deleteDirectory($this->inboxRoot);
    }
});

function storedCursor(DatabaseManager $db, int $inboxId): ?string
{
    $row = $db->connection()
        ->table('inbox_scan_state')
        ->where('inbox_id', $inboxId)
        ->where('folder', 'INBOX')
        ->first(['last_history_id']);

    return $row === null || ! is_string($row->last_history_id) ? null : $row->last_history_id;
}

/**
 * @return list<string>
 */
function calledMethods(FakeGmailApiClient $fake): array
{
    return array_map(
        static fn (array $call): string => $call['method'],
        $fake->getRequestedCalls(),
    );
}

it('leaves a backfilled inbox with a cursor the next tick can start from', function (): void {
    $job = $this->app->make(BackfillInboxJob::class, ['inboxId' => $this->inboxId, 'windowMonths' => 3]);
    $this->app->call([$job, 'handle']);

    expect(storedCursor($this->db, $this->inboxId))->toBe('12345');
});

it('reaches the history endpoint on the tick after a backfill', function (): void {
    $backfill = $this->app->make(BackfillInboxJob::class, ['inboxId' => $this->inboxId, 'windowMonths' => 3]);
    $this->app->call([$backfill, 'handle']);

    $next = new FakeGmailApiClient($this->app->make(Filesystem::class));
    $next->queueHistoryResponse([], '12500');
    $this->app->instance(GmailApiClientContract::class, $next);

    $incremental = $this->app->make(IncrementalScanJob::class, ['inboxId' => $this->inboxId]);
    $this->app->call([$incremental, 'handle']);

    expect(calledMethods($next))->toContain('listHistory')
        ->and(storedCursor($this->db, $this->inboxId))->toBe('12500');
});

it('adopts the mailbox cursor for an inbox that was backfilled without one', function (): void {
    $job = $this->app->make(IncrementalScanJob::class, ['inboxId' => $this->inboxId]);
    $this->app->call([$job, 'handle']);

    expect(storedCursor($this->db, $this->inboxId))->toBe('12345')
        ->and(calledMethods($this->fake))->not->toContain('listHistory');
});

it('writes no cursor at all when the mailbox will not report one', function (): void {
    $this->fake->simulateUnknownHistoryId();

    $job = $this->app->make(BackfillInboxJob::class, ['inboxId' => $this->inboxId, 'windowMonths' => 3]);
    $this->app->call([$job, 'handle']);

    expect(storedCursor($this->db, $this->inboxId))->toBeNull();
});
