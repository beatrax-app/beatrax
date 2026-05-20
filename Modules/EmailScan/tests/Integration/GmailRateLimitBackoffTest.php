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
use Modules\EmailScan\Internal\Clients\RateLimitedException;
use Modules\EmailScan\Internal\Jobs\IncrementalScanJob;

uses(RefreshDatabase::class);

/*
 * IncrementalScanJob Gmail rate-limit backoff invariant.
 *
 * Plan 07 success criterion SC#4: a per-inbox rate-limit must
 * transition status='rate_limited' + bump retry_attempts + stamp
 * error_message='Retry after Xs' + rethrow so Horizon honours the
 * project-wide backoff envelope.
 *
 * Test flow:
 *  1. Seed user + Gmail inbox + scan_state with last_history_id set
 *     + retry_attempts=0.
 *  2. Arm the Fake with simulateHistoryRateLimit($inboxId, 60).
 *  3. Dispatch the job — it throws RateLimitedException.
 *  4. Assert: status='rate_limited'; retry_attempts=1;
 *     error_message contains "Retry after 60s".
 *  5. Recovery loop — transition rate_limited → scanning (manually
 *     via the state machine, since a fresh dispatch needs status to
 *     be in the allowed set for scanning), arm another rate-limit
 *     with 120 sec, dispatch — retry_attempts=2.
 *  6. Recovery loop — clear the rate-limit arm, transition back to
 *     scanning, dispatch — happy path; resetRetryAttempts;
 *     retry_attempts=0; status='idle'.
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

it('catches RateLimitedException, transitions to rate_limited, bumps retry_attempts, rethrows; recovers on next run', function (): void {
    $user = User::query()->create([
        'username' => 'gmail-throttle',
        'password' => 'fixture',
        'period_start_day' => 1,
    ]);

    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $now = CarbonImmutable::now()->toDateTimeString();

    $inboxId = (int) $db->connection()->table('inboxes')->insertGetId([
        'user_id' => $user->id,
        'provider' => 'gmail',
        'email' => 'gmail-throttle@example.com',
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

    // First run — arm 60s rate-limit on listHistory.
    $fake = new FakeGmailApiClient($this->app->make(Filesystem::class));
    $fake->simulateHistoryRateLimit($inboxId, 60);
    $this->app->instance(GmailApiClientContract::class, $fake);

    /** @var IncrementalScanJob $job */
    $job = $this->app->make(IncrementalScanJob::class, ['inboxId' => $inboxId]);

    $thrown = null;
    try {
        $this->app->call([$job, 'handle']);
    } catch (Throwable $e) {
        $thrown = $e;
    }
    expect($thrown)->not->toBeNull();
    expect($thrown::class)->toBe(RateLimitedException::class);

    $scanState = $db->connection()
        ->table('inbox_scan_state')
        ->where('inbox_id', $inboxId)
        ->where('folder', 'INBOX')
        ->first(['status', 'retry_attempts', 'error_message']);
    expect($scanState)->not->toBeNull();
    expect($scanState->status)->toBe('rate_limited');
    expect((int) $scanState->retry_attempts)->toBe(1);
    expect((string) $scanState->error_message)->toContain('Retry after 60s');

    // Second run — Horizon retried; arm another rate-limit at 120s.
    // (The job's first action is applyStatus(scanning); the rate_limited
    // → scanning transition is in the allowed map.)
    $fake2 = new FakeGmailApiClient($this->app->make(Filesystem::class));
    $fake2->simulateHistoryRateLimit($inboxId, 120);
    $this->app->instance(GmailApiClientContract::class, $fake2);

    /** @var IncrementalScanJob $job2 */
    $job2 = $this->app->make(IncrementalScanJob::class, ['inboxId' => $inboxId]);
    $thrown2 = null;
    try {
        $this->app->call([$job2, 'handle']);
    } catch (Throwable $e) {
        $thrown2 = $e;
    }
    expect($thrown2)->not->toBeNull();

    $scanStateAfter2 = $db->connection()
        ->table('inbox_scan_state')
        ->where('inbox_id', $inboxId)
        ->where('folder', 'INBOX')
        ->first(['status', 'retry_attempts', 'error_message']);
    expect($scanStateAfter2)->not->toBeNull();
    expect($scanStateAfter2->status)->toBe('rate_limited');
    expect((int) $scanStateAfter2->retry_attempts)->toBe(2);
    expect((string) $scanStateAfter2->error_message)->toContain('Retry after 120s');

    // Third run — no rate-limit armed; queue a normal empty-history
    // response. The recovery happy path resets retry_attempts.
    $fake3 = new FakeGmailApiClient($this->app->make(Filesystem::class));
    $fake3->queueHistoryResponse([], '12345');
    $this->app->instance(GmailApiClientContract::class, $fake3);

    /** @var IncrementalScanJob $job3 */
    $job3 = $this->app->make(IncrementalScanJob::class, ['inboxId' => $inboxId]);
    $this->app->call([$job3, 'handle']);

    $scanStateAfter3 = $db->connection()
        ->table('inbox_scan_state')
        ->where('inbox_id', $inboxId)
        ->where('folder', 'INBOX')
        ->first(['status', 'retry_attempts']);
    expect($scanStateAfter3)->not->toBeNull();
    expect($scanStateAfter3->status)->toBe('idle');
    expect((int) $scanStateAfter3->retry_attempts)->toBe(0);
});
