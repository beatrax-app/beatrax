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
 * IncrementalScanJob WR-06 quota-saving invariant.
 *
 * When the Gmail history walk re-surfaces a provider_message_id we
 * already have on disk + indexed (e.g. the message landed during a
 * prior backfill before the incremental cursor was established),
 * the job MUST NOT issue a second getRawMessage call for it. The
 * insertOrIgnore protected the DB invariant; this test pins the
 * "don't burn provider quota on a re-fetch" behaviour.
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

    // Pre-seed inbox_messages with the message id the upcoming
    // history walk will surface. This simulates the "prior backfill
    // already landed this message" case the WR-06 fix addresses.
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

    // Queue a history response that re-surfaces the already-fetched
    // id plus one NEW id. The new id should be fetched; the
    // already-present id should be skipped.
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

    // Exactly one getRawMessage call — for the new id only.
    expect($rawCalls)->toHaveCount(1);
    expect($rawCalls[0]['args']['providerMessageId'])->toBe('ics-sample-statement-notice');
});
