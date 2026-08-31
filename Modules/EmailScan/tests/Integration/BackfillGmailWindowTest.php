<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Sleep;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;
use Modules\EmailScan\Internal\Clients\FakeGmailApiClient;
use Modules\EmailScan\Internal\Clients\GmailApiClientContract;
use Modules\EmailScan\Internal\Jobs\BackfillInboxJob;

uses(RefreshDatabase::class);

// Without a $windowStart on the listSenderMessages call, Gmail walks the full
// sender-allow-list history back to inbox creation, and the user's chosen
// backfill window means nothing.

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

it('passes the user-selected window (3 months) into the Gmail listSenderMessages windowStart arg', function (): void {
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
        'username' => 'gmail-window',
        'password' => 'fixture',
        'period_start_day' => 1,
    ]);

    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $now = $fixedNow->toDateTimeString();

    $inboxId = (int) $db->connection()->table('inboxes')->insertGetId([
        'user_id' => $user->id,
        'provider' => 'gmail',
        'email' => 'gmail-window@example.com',
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

    $fake = new FakeGmailApiClient($this->app->make(Filesystem::class));
    $this->app->instance(GmailApiClientContract::class, $fake);

    /** @var BackfillInboxJob $job */
    $job = $this->app->make(BackfillInboxJob::class, ['inboxId' => $inboxId, 'windowMonths' => 3]);
    $this->app->call([$job, 'handle']);

    // A null windowStart is the regression: Gmail then walks all-time.
    $calls = array_values(array_filter(
        $fake->getRequestedCalls(),
        static fn (array $c): bool => $c['method'] === 'listSenderMessages',
    ));

    expect($calls)->not->toBeEmpty();
    $firstCallArgs = $calls[0]['args'];
    expect($firstCallArgs['windowStart'] ?? null)->not->toBeNull(
        'WR-01 regression: windowStart must be passed into Gmail backfill so the user-selected window is honoured.',
    );

    $expectedStart = $fixedNow->modify('-3 months')->format(DateTimeInterface::ATOM);
    expect($firstCallArgs['windowStart'])->toBe($expectedStart);

    CarbonImmutable::setTestNow();
});

it('plumbs a 12-month window correctly', function (): void {
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
        'username' => 'gmail-window-12',
        'password' => 'fixture',
        'period_start_day' => 1,
    ]);

    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $now = $fixedNow->toDateTimeString();

    $inboxId = (int) $db->connection()->table('inboxes')->insertGetId([
        'user_id' => $user->id,
        'provider' => 'gmail',
        'email' => 'gmail-window-12@example.com',
        'backfill_window_months' => 12,
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

    $fake = new FakeGmailApiClient($this->app->make(Filesystem::class));
    $this->app->instance(GmailApiClientContract::class, $fake);

    /** @var BackfillInboxJob $job */
    $job = $this->app->make(BackfillInboxJob::class, ['inboxId' => $inboxId, 'windowMonths' => 12]);
    $this->app->call([$job, 'handle']);

    $calls = array_values(array_filter(
        $fake->getRequestedCalls(),
        static fn (array $c): bool => $c['method'] === 'listSenderMessages',
    ));

    expect($calls)->not->toBeEmpty();
    expect($calls[0]['args']['windowStart'])
        ->toBe($fixedNow->modify('-12 months')->format(DateTimeInterface::ATOM));

    CarbonImmutable::setTestNow();
});

// A run on the 31st stepping back a month with plain month arithmetic lands in
// the month AFTER the one the reader asked for — 31 March back one month is 3
// March, not 28 February — so the oldest days of the window never get walked.
it('does not overflow the window start out of a short month when the run lands on the 31st', function (): void {
    $fixedNow = CarbonImmutable::create(2026, 3, 31, 12, 0, 0, 'UTC');
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
        'username' => 'gmail-window-eom',
        'password' => 'fixture',
        'period_start_day' => 1,
    ]);

    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $now = $fixedNow->toDateTimeString();

    $inboxId = (int) $db->connection()->table('inboxes')->insertGetId([
        'user_id' => $user->id,
        'provider' => 'gmail',
        'email' => 'gmail-window-eom@example.com',
        'backfill_window_months' => 1,
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

    $fake = new FakeGmailApiClient($this->app->make(Filesystem::class));
    $this->app->instance(GmailApiClientContract::class, $fake);

    /** @var BackfillInboxJob $job */
    $job = $this->app->make(BackfillInboxJob::class, ['inboxId' => $inboxId, 'windowMonths' => 1]);
    $this->app->call([$job, 'handle']);

    $calls = array_values(array_filter(
        $fake->getRequestedCalls(),
        static fn (array $c): bool => $c['method'] === 'listSenderMessages',
    ));

    expect($calls)->not->toBeEmpty();
    expect($calls[0]['args']['windowStart'])
        ->toBe($fixedNow->subMonthsNoOverflow(1)->format(DateTimeInterface::ATOM))
        ->and($calls[0]['args']['windowStart'])->toStartWith('2026-02-28');

    CarbonImmutable::setTestNow();
});
