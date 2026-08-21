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
use Modules\EmailScan\Internal\Jobs\BackfillInboxJob;

uses(RefreshDatabase::class);

// The Microsoft branch walks /me/messages to exhaustion first, and only then
// issues one delta call with $deltaLink=null to establish the baseline. A
// delta call raised mid-walk would anchor the cursor on a mailbox the backfill
// has not finished reading.

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

it('walks pages, then calls deltaPage(null) EXACTLY ONCE after the walk completes, then persists last_delta_link', function (): void {
    $user = User::query()->create([
        'username' => 'graph-two-phase',
        'password' => 'fixture',
        'period_start_day' => 1,
    ]);

    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $now = CarbonImmutable::now()->toDateTimeString();

    $inboxId = (int) $db->connection()->table('inboxes')->insertGetId([
        'user_id' => $user->id,
        'provider' => 'microsoft',
        'email' => 'graph-two-phase@example.com',
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

    $calls = $fake->getRequestedCalls();
    $methodSequence = array_map(static fn (array $c): string => (string) $c['method'], $calls);

    // Page 1 carries an @odata.nextLink, and the Fake answers a non-null
    // nextLink with page-2-empty.json, which carries none — so the walker
    // exits after exactly two listSenderMessagesPaged calls.
    expect($methodSequence)->toBe([
        'listSenderMessagesPaged',
        'getRawMessage',
        'getRawMessage',
        'getRawMessage',
        'listSenderMessagesPaged',
        'deltaPage',
    ]);

    // Once, not per page: the walker does not re-walk via delta mid-backfill.
    $deltaCalls = array_values(array_filter(
        $calls,
        static fn (array $c): bool => $c['method'] === 'deltaPage',
    ));
    expect($deltaCalls)->toHaveCount(1);
    expect($deltaCalls[0]['args'])->toMatchArray([
        'inboxId' => $inboxId,
        'deltaLink' => null,
    ]);

    // The baseline may only be established once the walk has terminated.
    $lastListIndex = max(array_keys(array_filter(
        $methodSequence,
        static fn (string $m): bool => $m === 'listSenderMessagesPaged',
    )));
    $deltaIndex = (int) array_keys(array_filter(
        $methodSequence,
        static fn (string $m): bool => $m === 'deltaPage',
    ))[0];
    expect($deltaIndex)->toBeGreaterThan($lastListIndex);

    $scanState = $db->connection()
        ->table('inbox_scan_state')
        ->where('inbox_id', $inboxId)
        ->first(['last_delta_link']);
    expect($scanState)->not->toBeNull();
    expect($scanState->last_delta_link)
        ->toBe('https://graph.microsoft.com/v1.0/me/messages/$delta?$deltatoken=baseline-xyz');
});
