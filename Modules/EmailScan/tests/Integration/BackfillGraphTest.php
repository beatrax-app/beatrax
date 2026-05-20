<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Sleep;
use Modules\Core\Models\User;
use Modules\EmailScan\Internal\Clients\FakeGraphApiClient;
use Modules\EmailScan\Internal\Clients\GraphApiClientContract;
use Modules\EmailScan\Internal\Clients\RateLimitedException;
use Modules\EmailScan\Internal\Jobs\BackfillInboxJob;
use Modules\EmailScan\Public\Services\EmlBlobStore;

uses(RefreshDatabase::class);

/*
 * BackfillInboxJob Microsoft Graph happy-path integration test.
 *
 * Mirrors BackfillChunkedJobTest (Plan 05) but seeds a provider =
 * 'microsoft' inbox and rebinds GraphApiClientContract to the Wave 0
 * FakeGraphApiClient. End-of-walk state assertions:
 *
 *  - three .eml blobs land on disk under
 *    storage/app/inbox/{user_id}/{inbox_id}/{YYYY}/{MM}/
 *  - three inbox_messages rows with status='fetched' carry the four
 *    index fields populated from the .eml headers AND the provider-
 *    stamped receivedDateTime drives internal_date (per the two-
 *    phase pattern's correctness requirement)
 *  - inbox_scan_state.last_delta_link is non-NULL after the baseline
 *    deltaPage(null) call lands its @odata.deltaLink (the Fake's
 *    delta-baseline fixture carries the canonical token)
 *  - inbox_scan_state.status flips to 'idle'
 *  - inboxes.backfill_progress returns to NULL after completion
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

it('walks Graph pages, persists .eml + inbox_messages rows, establishes the deltaLink baseline, and flips status to idle', function (): void {
    $user = User::query()->create([
        'username' => 'graph-backfill',
        'password' => 'fixture',
        'period_start_day' => 1,
    ]);

    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $now = CarbonImmutable::now()->toDateTimeString();

    $inboxId = (int) $db->connection()->table('inboxes')->insertGetId([
        'user_id' => $user->id,
        'provider' => 'microsoft',
        'email' => 'graph-backfill@example.com',
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
    // path consumes synthesised Graph fixtures.
    $fake = new FakeGraphApiClient($this->app->make(Filesystem::class));
    $this->app->instance(GraphApiClientContract::class, $fake);

    /** @var BackfillInboxJob $job */
    $job = $this->app->make(BackfillInboxJob::class, ['inboxId' => $inboxId, 'windowMonths' => 3]);
    $this->app->call([$job, 'handle']);

    // Assert: three .eml files exist at the per-user / per-inbox
    // partitioned paths. The provider-stamped receivedDateTime is the
    // canonical internal_date — Plan 01's fixture pins the year/month
    // partition to 2026/05.
    /** @var EmlBlobStore $store */
    $store = $this->app->make(EmlBlobStore::class);
    foreach (
        [
            ['paypal-sample-receipt', new DateTimeImmutable('2026-05-11 09:14:21+00:00')],
            ['ics-sample-statement-notice', new DateTimeImmutable('2026-05-12 06:00:13+00:00')],
            ['googleplay-sample-purchase', new DateTimeImmutable('2026-05-13 17:45:49+00:00')],
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

    // Verify the provider-stamped receivedDateTime (2026-05-11 09:14:21Z)
    // drove internal_date — NOT the message's in-body Date: header.
    // RESEARCH Pattern 4 calls this out as the correctness requirement
    // for the Microsoft branch.
    expect($paypal->internal_date)->toBe('2026-05-11 09:14:21');

    $ics = $byId['ics-sample-statement-notice'];
    expect($ics->sender_email)->toBe('noreply@ics.nl');
    expect($ics->sender_name)->toBe('ICS Cards');
    expect($ics->subject)->toBe('Je nieuwe maandafschrift staat klaar');
    expect($ics->internal_date)->toBe('2026-05-12 06:00:13');

    $play = $byId['googleplay-sample-purchase'];
    expect($play->sender_email)->toBe('googleplay-noreply@google.com');
    expect($play->subject)->toBe('Your Google Play Order Receipt');
    expect($play->internal_date)->toBe('2026-05-13 17:45:49');

    // Assert: backfill_progress is null after completion.
    $inboxAfter = $db->connection()->table('inboxes')->where('id', $inboxId)->first(['backfill_progress']);
    expect($inboxAfter)->not->toBeNull();
    expect($inboxAfter->backfill_progress)->toBeNull();

    // Assert: status flipped to idle + last_delta_link set to the
    // Fake's hard-coded baseline deltaLink fixture.
    $scanState = $db->connection()
        ->table('inbox_scan_state')
        ->where('inbox_id', $inboxId)
        ->where('folder', 'INBOX')
        ->first(['status', 'last_delta_link', 'last_history_id']);
    expect($scanState)->not->toBeNull();
    expect($scanState->status)->toBe('idle');
    expect($scanState->last_delta_link)
        ->toBe('https://graph.microsoft.com/v1.0/me/messages/$delta?$deltatoken=baseline-xyz');
    // Microsoft branch must NEVER write last_history_id — that column
    // is Gmail-only. Asserting NULL here pins the provider isolation.
    expect($scanState->last_history_id)->toBeNull();
});

it('catches RateLimitedException, transitions to rate_limited, and re-throws so the worker retries', function (): void {
    $user = User::query()->create([
        'username' => 'graph-throttle',
        'password' => 'fixture',
        'period_start_day' => 1,
    ]);

    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $now = CarbonImmutable::now()->toDateTimeString();

    $inboxId = (int) $db->connection()->table('inboxes')->insertGetId([
        'user_id' => $user->id,
        'provider' => 'microsoft',
        'email' => 'graph-throttle@example.com',
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

    // Arm the Fake to throw on the first listSenderMessagesPaged call.
    $fake = new FakeGraphApiClient($this->app->make(Filesystem::class));
    $fake->simulateRateLimit($inboxId, 5);
    $this->app->instance(GraphApiClientContract::class, $fake);

    /** @var BackfillInboxJob $job */
    $job = $this->app->make(BackfillInboxJob::class, ['inboxId' => $inboxId, 'windowMonths' => 3]);

    $thrown = null;
    try {
        $this->app->call([$job, 'handle']);
    } catch (Throwable $e) {
        $thrown = $e;
    }

    expect($thrown)->not->toBeNull();
    expect($thrown::class)->toBe(RateLimitedException::class);

    // The status row must record the rate_limited transition with the
    // retry-after hint. The job rethrows so the queue can reschedule.
    $scanState = $db->connection()
        ->table('inbox_scan_state')
        ->where('inbox_id', $inboxId)
        ->first(['status', 'error_message']);
    expect($scanState)->not->toBeNull();
    expect($scanState->status)->toBe('rate_limited');
    expect((string) $scanState->error_message)->toContain('5');
});
