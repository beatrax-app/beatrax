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

// The wall-clock anchor is captured before the first provider call and passed
// into the post-walk deltaPage baseline. Anchored on "now" instead, a message
// arriving during the walk falls past the walk's receivedDateTime cursor and
// below the baseline's lower bound, so no tick ever sees it.

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
