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

// users.history.list carries no server-side from-filter, so the allow-list can
// only be applied client-side — exactly as the Microsoft delta branch does.
// Without it "read only the senders you allow-listed" writes the whole
// incoming mail stream to disk, sender and subject in plaintext columns.

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

function emailScanAllowListInbox(string $username): array
{
    $user = User::query()->create([
        'username' => $username,
        'password' => 'fixture',
        'period_start_day' => 1,
    ]);

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $now = CarbonImmutable::now()->toDateTimeString();

    $inboxId = (int) $db->connection()->table('inboxes')->insertGetId([
        'user_id' => $user->id,
        'provider' => 'gmail',
        'email' => $username.'@example.com',
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

    return [$user, $inboxId, $db];
}

function emailScanBlobFileCount(string $root): int
{
    if (! is_dir($root)) {
        return 0;
    }

    $found = 0;
    /** @var SplFileInfo $file */
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $file) {
        if ($file->isFile()) {
            $found++;
        }
    }

    return $found;
}

it('Gmail incremental: never persists a message from a sender outside the allow-list', function (): void {
    [$user, $inboxId, $db] = emailScanAllowListInbox('gmail-allow-list');

    $fake = new FakeGmailApiClient($this->app->make(Filesystem::class));
    $fake->queueHistoryResponse(['private-sample-private-mail'], '12400');
    $this->app->instance(GmailApiClientContract::class, $fake);

    /** @var IncrementalScanJob $job */
    $job = $this->app->make(IncrementalScanJob::class, ['inboxId' => $inboxId]);
    $this->app->call([$job, 'handle']);

    $rows = $db->connection()->table('inbox_messages')->where('inbox_id', $inboxId)->get();

    expect($rows)->toHaveCount(0)
        ->and(emailScanBlobFileCount($this->inboxRoot))->toBe(0);

    // The cursor still advances: a filtered-out message must not stall the walk.
    $state = $db->connection()->table('inbox_scan_state')->where('inbox_id', $inboxId)->first(['last_history_id']);
    expect($state->last_history_id)->toBe('12400');
});

it('Gmail incremental: still persists an allow-listed sender alongside a filtered one', function (): void {
    [$user, $inboxId, $db] = emailScanAllowListInbox('gmail-allow-list-mixed');

    $fake = new FakeGmailApiClient($this->app->make(Filesystem::class));
    $fake->queueHistoryResponse(['private-sample-private-mail', 'ics-sample-statement-notice'], '12400');
    $this->app->instance(GmailApiClientContract::class, $fake);

    /** @var IncrementalScanJob $job */
    $job = $this->app->make(IncrementalScanJob::class, ['inboxId' => $inboxId]);
    $this->app->call([$job, 'handle']);

    $stored = $db->connection()->table('inbox_messages')
        ->where('inbox_id', $inboxId)
        ->pluck('sender_email')
        ->all();

    expect($stored)->toBe(['noreply@ics.nl'])
        ->and(emailScanBlobFileCount($this->inboxRoot))->toBe(1);
});
