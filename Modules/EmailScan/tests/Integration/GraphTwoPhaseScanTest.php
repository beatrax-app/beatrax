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

/*
 * Two-phase Graph scan ordering invariant.
 *
 * RESEARCH Pattern 4 states the Microsoft branch MUST:
 *   1. Walk /me/messages with the OData (from in [...]) +
 *      receivedDateTime ge {windowStart} filter (the backfill phase).
 *   2. AFTER the page walk terminates (page-2-empty has no nextLink
 *      in Plan 01's fixture), issue EXACTLY ONE
 *      /me/mailFolders/inbox/messages/delta call with $deltaLink=null
 *      to establish the baseline @odata.deltaLink (the baseline
 *      phase).
 *   3. Persist that deltaLink into inbox_scan_state.last_delta_link
 *      via InboxScanStateMachine::recordCursor — the sole legal
 *      mutator (BoundaryArchTest invariant).
 *
 * This test asserts the call sequence + the single-shot deltaPage
 * invariant by inspecting the FakeGraphApiClient's request log.
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

    // The Fake's listSenderMessagesPaged returns the page-1 fixture
    // (3 messages, no nextLink in this fixture's wire shape — note:
    // page-1 carries @odata.nextLink but the FakeGraphApiClient
    // serves page-2-empty.json on a non-null nextLink, which has NO
    // nextLink itself, so the walker exits after two listSender calls).
    //
    // Expected exact call sequence:
    //   1. listSenderMessagesPaged   (page 1, nextLink=null cursor in)
    //   2. getRawMessage             (paypal)
    //   3. getRawMessage             (ics)
    //   4. getRawMessage             (googleplay)
    //   5. listSenderMessagesPaged   (page 2 follow, the URL acts as cursor)
    //   6. deltaPage                 (baseline, deltaLink=null)
    expect($methodSequence)->toBe([
        'listSenderMessagesPaged',
        'getRawMessage',
        'getRawMessage',
        'getRawMessage',
        'listSenderMessagesPaged',
        'deltaPage',
    ]);

    // Assert: deltaPage was called EXACTLY ONCE — the walker does
    // NOT incrementally re-walk via delta during backfill. The
    // single baseline call sits at the END of the sequence.
    $deltaCalls = array_values(array_filter(
        $calls,
        static fn (array $c): bool => $c['method'] === 'deltaPage',
    ));
    expect($deltaCalls)->toHaveCount(1);
    expect($deltaCalls[0]['args'])->toMatchArray([
        'inboxId' => $inboxId,
        'deltaLink' => null,
    ]);

    // The single deltaPage call must come AFTER the last
    // listSenderMessagesPaged call (the baseline establishes only
    // once the backfill walk has fully terminated).
    $lastListIndex = max(array_keys(array_filter(
        $methodSequence,
        static fn (string $m): bool => $m === 'listSenderMessagesPaged',
    )));
    $deltaIndex = (int) array_keys(array_filter(
        $methodSequence,
        static fn (string $m): bool => $m === 'deltaPage',
    ))[0];
    expect($deltaIndex)->toBeGreaterThan($lastListIndex);

    // Assert: last_delta_link was written ONLY after the deltaPage
    // call (the recordCursor surface persisted the @odata.deltaLink
    // from the Fake's baseline fixture).
    $scanState = $db->connection()
        ->table('inbox_scan_state')
        ->where('inbox_id', $inboxId)
        ->first(['last_delta_link']);
    expect($scanState)->not->toBeNull();
    expect($scanState->last_delta_link)
        ->toBe('https://graph.microsoft.com/v1.0/me/messages/$delta?$deltatoken=baseline-xyz');
});
