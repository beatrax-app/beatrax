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

// insertOrIgnore already protected the DB invariant; what is pinned here is
// the provider quota. A history walk re-surfacing a message a prior backfill
// landed must not spend a second getRawMessage call on it.

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

it('Gmail incremental: does not call getRawMessage for ids already present in inbox_messages', function (): void {
    $user = User::query()->create([
        'username' => 'wr06',
        'password' => 'fixture',
        'period_start_day' => 1,
    ]);

    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $now = CarbonImmutable::now()->toDateTimeString();

    $inboxId = (int) $db->connection()->table('inboxes')->insertGetId([
        'user_id' => $user->id,
        'provider' => 'gmail',
        'email' => 'wr06@example.com',
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

    // Stands in for a prior backfill having already landed this message id.
    $db->connection()->table('inbox_messages')->insert([
        'user_id' => $user->id,
        'inbox_id' => $inboxId,
        'provider_message_id' => 'paypal-sample-receipt',
        'internal_date' => $now,
        'sender_email' => 'service@paypal.com',
        'sender_name' => 'PayPal',
        'subject' => 'Receipt',
        'status' => 'fetched',
        'fetched_at' => $now,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    // The already-fetched id plus one new one.
    $fake = new FakeGmailApiClient($this->app->make(Filesystem::class));
    $fake->queueHistoryResponse(['paypal-sample-receipt', 'ics-sample-statement-notice'], '12400');
    $this->app->instance(GmailApiClientContract::class, $fake);

    /** @var IncrementalScanJob $job */
    $job = $this->app->make(IncrementalScanJob::class, ['inboxId' => $inboxId]);
    $this->app->call([$job, 'handle']);

    $calls = $fake->getRequestedCalls();
    $rawCalls = array_values(array_filter(
        $calls,
        static fn (array $c): bool => $c['method'] === 'getRawMessage',
    ));

    expect($rawCalls)->toHaveCount(1);
    expect($rawCalls[0]['args']['providerMessageId'])->toBe('ics-sample-statement-notice');
});
