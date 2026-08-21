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
use Modules\EmailScan\Internal\Jobs\IncrementalScanJob;

uses(RefreshDatabase::class);

// Graph's own Retry-After value has to survive the whole chain —
// RateLimitedException, then applyRateLimited — to reach error_message as
// "Retry after Xs".

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

it('catches RateLimitedException on Graph deltaPage, transitions to rate_limited, honours retry-after, recovers', function (): void {
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
        'last_delta_link' => 'https://graph.microsoft.com/v1.0/me/mailFolders/inbox/messages/$delta?$deltatoken=xyz',
        'last_scan_at' => $now,
        'retry_attempts' => 0,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    // First run: 120s retry-after on the next deltaPage call.
    $fake = new FakeGraphApiClient($this->app->make(Filesystem::class));
    $fake->simulateDeltaRateLimit($inboxId, 120);
    $this->app->instance(GraphApiClientContract::class, $fake);

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
    expect((string) $scanState->error_message)->toContain('Retry after 120s');

    // Second run: Horizon retried, and it is rate-limited again at 60s.
    $fake2 = new FakeGraphApiClient($this->app->make(Filesystem::class));
    $fake2->simulateDeltaRateLimit($inboxId, 60);
    $this->app->instance(GraphApiClientContract::class, $fake2);

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
    expect((string) $scanStateAfter2->error_message)->toContain('Retry after 60s');

    // Third run: nothing armed, so the recovery path resets retry_attempts.
    $fake3 = new FakeGraphApiClient($this->app->make(Filesystem::class));
    $this->app->instance(GraphApiClientContract::class, $fake3);

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
