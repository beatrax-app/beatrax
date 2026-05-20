<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Sleep;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;
use Modules\EmailScan\Internal\Clients\FakeGraphApiClient;
use Modules\EmailScan\Internal\Clients\GraphApiClientContract;
use Modules\EmailScan\Internal\Jobs\BackfillInboxJob;

uses(RefreshDatabase::class);

/*
 * WR-02 iter-2 regression: runMicrosoftBackfill must capture the
 * walk-start wall-clock anchor BEFORE issuing any provider call, then
 * pass that anchor into the post-walk deltaPage(null, anchor) baseline
 * so messages arriving during the walk are picked up by the next
 * incremental tick rather than slipping through both the walk's
 * receivedDateTime cursor AND the baseline's "now" lower bound.
 *
 * The FakeGraphApiClient records the sinceOverride arg on every
 * deltaPage call. The test runs a happy-path backfill and asserts:
 *   1. deltaPage(null, ...) was invoked exactly once (baseline phase).
 *   2. The sinceOverride is non-null (regression guard — previously
 *      the call site was deltaPage(null) with no anchor).
 *   3. The sinceOverride equals the test's frozen wall-clock now,
 *      proving the anchor was captured before any other call.
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

it('captures the walk-start anchor before any provider call and passes it as the baseline lower bound', function (): void {
    $fixedNow = CarbonImmutable::create(2026, 5, 17, 12, 0, 0, 'UTC');
    CarbonImmutable::setTestNow($fixedNow);

    $clock = new class($fixedNow) implements Clock
    {
        public function __construct(private readonly CarbonImmutable $now) {}

        public function now(): CarbonImmutable
        {
            return $this->now;
        }
    };
    $this->app->instance(Clock::class, $clock);

    $user = User::query()->create([
        'username' => 'graph-baseline',
        'password' => 'fixture',
        'period_start_day' => 1,
    ]);

    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $now = $fixedNow->toDateTimeString();

    $inboxId = (int) $db->connection()->table('inboxes')->insertGetId([
        'user_id' => $user->id,
        'provider' => 'microsoft',
        'email' => 'graph-baseline@example.com',
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

    $fake = new FakeGraphApiClient($this->app->make(Filesystem::class));
    $this->app->instance(GraphApiClientContract::class, $fake);

    /** @var BackfillInboxJob $job */
    $job = $this->app->make(BackfillInboxJob::class, ['inboxId' => $inboxId, 'windowMonths' => 3]);
    $this->app->call([$job, 'handle']);

    $deltaCalls = array_values(array_filter(
        $fake->getRequestedCalls(),
        static fn (array $c): bool => $c['method'] === 'deltaPage',
    ));
    expect($deltaCalls)->toHaveCount(1);

    $baselineCallArgs = $deltaCalls[0]['args'];
    expect($baselineCallArgs['deltaLink'])->toBeNull();
    expect($baselineCallArgs['sinceOverride'] ?? null)->not->toBeNull(
        'WR-02 regression: sinceOverride must be passed to deltaPage so the baseline lower bound is the pre-walk anchor.',
    );
    expect($baselineCallArgs['sinceOverride'])->toBe($fixedNow->format(DateTimeInterface::ATOM));

    CarbonImmutable::setTestNow();
});
