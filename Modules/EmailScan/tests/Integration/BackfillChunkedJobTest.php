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
use Modules\EmailScan\Public\Services\EmlBlobStore;

uses(RefreshDatabase::class);

/*
 * BackfillInboxJob happy-path integration test.
 *
 * Drives the Wave 0 FakeGmailApiClient against the real
 * BackfillInboxJob and asserts the end-of-walk state: three .eml
 * blobs on disk under storage/app/inbox/{user_id}/{inbox_id}/...,
 * three inbox_messages rows with status='fetched', the
 * backfill_progress payload cleared, and inbox_scan_state flipped
 * to idle with the historyId baseline persisted.
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

it('walks pages, persists .eml + inbox_messages rows, and flips status to idle', function (): void {
    $user = User::query()->create([
        'username' => 'backfill-happy',
        'password' => 'fixture',
        'period_start_day' => 1,
    ]);

    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $now = CarbonImmutable::now()->toDateTimeString();

    $inboxId = (int) $db->connection()->table('inboxes')->insertGetId([
        'user_id' => $user->id,
        'provider' => 'gmail',
        'email' => 'backfill-happy@example.com',
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
        'retry_attempts' => 0,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    // Swap the Fake into the contract binding so the production job
    // path consumes synthesised fixtures.
    $fake = new FakeGmailApiClient($this->app->make(Filesystem::class));
    $this->app->instance(GmailApiClientContract::class, $fake);

    /** @var BackfillInboxJob $job */
    $job = $this->app->make(BackfillInboxJob::class, ['inboxId' => $inboxId, 'windowMonths' => 3]);
    $this->app->call([$job, 'handle']);

    // Assert: three .eml files exist at the per-user / per-inbox
    // partitioned paths.
    /** @var EmlBlobStore $store */
    $store = $this->app->make(EmlBlobStore::class);
    foreach (
        [
            ['paypal-sample-receipt',         new DateTimeImmutable('2026-05-11 09:14:21+00:00')],
            ['ics-sample-statement-notice',   new DateTimeImmutable('2026-05-12 06:00:13+00:00')],
            ['googleplay-sample-purchase',    new DateTimeImmutable('2026-05-13 17:45:49+00:00')],
        ] as [$messageId, $internalDate]
    ) {
        $path = $store->pathFor($user->id, $inboxId, $internalDate, $messageId);
        expect($store->exists($path))->toBeTrue("Expected blob for {$messageId} at {$path}");
    }

    // Assert: three inbox_messages rows with status='fetched' and
    // the four index fields populated from the .eml headers.
    $rows = $db->connection()
        ->table('inbox_messages')
        ->where('inbox_id', $inboxId)
        ->orderBy('internal_date', 'asc')
        ->get();

    expect($rows)->toHaveCount(3);

    $byId = [];
    foreach ($rows as $row) {
        /** @var stdClass $row */
        $byId[(string) $row->provider_message_id] = $row;
    }

    $paypal = $byId['paypal-sample-receipt'];
    expect($paypal->sender_email)->toBe('service@paypal.com');
    expect($paypal->sender_name)->toBe('PayPal');
    expect($paypal->subject)->toBe('Bedankt voor je betaling aan Synthetic Merchant BV');
    expect($paypal->status)->toBe('fetched');

    $ics = $byId['ics-sample-statement-notice'];
    expect($ics->sender_email)->toBe('noreply@ics.nl');
    expect($ics->sender_name)->toBe('ICS Cards');
    expect($ics->subject)->toBe('Je nieuwe maandafschrift staat klaar');

    $play = $byId['googleplay-sample-purchase'];
    expect($play->sender_email)->toBe('googleplay-noreply@google.com');
    expect($play->subject)->toBe('Your Google Play Order Receipt');

    // Assert: backfill_progress is null after completion.
    $inboxAfter = $db->connection()->table('inboxes')->where('id', $inboxId)->first(['backfill_progress']);
    expect($inboxAfter)->not->toBeNull();
    expect($inboxAfter->backfill_progress)->toBeNull();

    // Assert: status flipped to idle + last_history_id set to the
    // Fake's hard-coded '12345'.
    $scanState = $db->connection()
        ->table('inbox_scan_state')
        ->where('inbox_id', $inboxId)
        ->where('folder', 'INBOX')
        ->first(['status', 'last_history_id']);
    expect($scanState)->not->toBeNull();
    expect($scanState->status)->toBe('idle');
    expect($scanState->last_history_id)->toBe('12345');
});
