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

// Gmail's users.history.list 404s once startHistoryId is older than its ~7-day
// retention window. The job falls back to a date-bounded messages.list walk
// over the sender allow-list, and leaves the stored historyId alone so the
// next tick retries listHistory.

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
        'username' => 'gmail-expired',
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

    // With nothing queued, listHistory serves the 404 fixture and the job
    // sees CursorExpiredException.
    $fake = new FakeGmailApiClient($this->app->make(Filesystem::class));
    $this->app->instance(GmailApiClientContract::class, $fake);

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
    $listHistoryIndex = array_search('listHistory', $methodSequence, strict: true);
    $listSenderIndex = array_search('listSenderMessages', $methodSequence, strict: true);

    expect($listHistoryIndex)->not->toBeFalse();
    expect($listSenderIndex)->not->toBeFalse();
    expect($listSenderIndex)->toBeGreaterThan($listHistoryIndex);

    $scanState = $db->connection()
        ->table('inbox_scan_state')
        ->where('inbox_id', $inboxId)
        ->where('folder', 'INBOX')
        ->first(['status', 'last_history_id']);
    expect($scanState)->not->toBeNull();
    expect($scanState->status)->toBe('idle');
    // The fallback walk teaches us no fresh historyId, so the cursor stays put
    // and the next tick re-attempts listHistory.
    expect($scanState->last_history_id)->toBe('12345');

    // A null windowStart would walk the whole allow-list history, bounded only
    // by the 500-message defensive cap.
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
