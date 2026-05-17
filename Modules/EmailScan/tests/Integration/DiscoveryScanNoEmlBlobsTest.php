<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\EmailScan\Internal\Clients\FakeGmailApiClient;
use Modules\EmailScan\Internal\Clients\FakeGraphApiClient;
use Modules\EmailScan\Internal\Clients\GmailApiClientContract;
use Modules\EmailScan\Internal\Clients\GraphApiClientContract;
use Modules\EmailScan\Internal\Jobs\DiscoveryScanJob;

uses(RefreshDatabase::class);

/*
 * DiscoveryScanJob NO-.eml-blobs invariant + sender exclusion +
 * occurrence-count increment + dismissed-sender exclusion.
 *
 * Plan 09 D-121: the daily discovery job must populate
 * discovered_senders rows from a broad-keyword subject query WITHOUT
 * ever persisting a `.eml` body. The Gmail client fetches headers
 * only (format=metadata, metadataHeaders=['From','Date']); the Graph
 * client uses $search with $select=id,from,subject,receivedDateTime.
 *
 * Test flow:
 *  1. Seed user + Gmail inbox + Microsoft inbox + the three system
 *     known_senders rows (paypal.com / @ics.nl / googleplay-noreply@google.com).
 *  2. Queue Fake discovery responses including known senders (which
 *     should be filtered) + new candidate senders (which should land).
 *  3. Assert storage/app/inbox/ is empty before the run.
 *  4. Run DiscoveryScanJob.
 *  5. Assert discovered_senders ONLY carries the new candidate rows;
 *     paypal / ics / googleplay are NOT present.
 *  6. Assert storage/app/inbox/ is STILL empty after the run.
 *  7. Re-run; assert occurrence_count increments for repeating senders.
 *  8. Flip one row to state='dismissed'; re-run; assert that row's
 *     count does NOT increment.
 */

beforeEach(function (): void {
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

it('walks Gmail + Microsoft inboxes, populates discovered_senders with NEW senders only, and never persists .eml blobs', function (): void {
    $user = User::query()->create([
        'email' => 'discovery@example.com',
        'password' => 'fixture',
        'period_start_day' => 1,
    ]);

    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $now = CarbonImmutable::now()->toDateTimeString();

    $gmailInboxId = (int) $db->connection()->table('inboxes')->insertGetId([
        'user_id' => $user->id,
        'provider' => 'gmail',
        'email' => 'discovery+gmail@example.com',
        'backfill_window_months' => 3,
        'backfill_progress' => null,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $microsoftInboxId = (int) $db->connection()->table('inboxes')->insertGetId([
        'user_id' => $user->id,
        'provider' => 'microsoft',
        'email' => 'discovery+ms@example.com',
        'backfill_window_months' => 3,
        'backfill_progress' => null,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    // Queue Gmail responses — page 1 mixes new + already-known senders;
    // the already-known PayPal one MUST be filtered out before the
    // upsert lands a row.
    $fakeGmail = new FakeGmailApiClient($this->app->make(Filesystem::class));
    $fakeGmail->queueDiscoveryResponse([
        ['id' => 'msg-bol-1', 'fromAddress' => 'orders@bol.com', 'fromName' => 'Bol.com', 'internalDate' => '2026-05-14T08:12:00Z'],
        ['id' => 'msg-paypal-1', 'fromAddress' => 'service@paypal.com', 'fromName' => 'PayPal', 'internalDate' => '2026-05-14T09:00:00Z'],
        ['id' => 'msg-coolblue-1', 'fromAddress' => 'noreply@coolblue.nl', 'fromName' => 'Coolblue', 'internalDate' => '2026-05-14T09:30:00Z'],
    ]);
    $this->app->instance(GmailApiClientContract::class, $fakeGmail);

    // Queue Microsoft responses — same shape: one new sender + one
    // known. Graph payload shape mirrors what the real
    // GraphApiClient::listDiscoveryCandidatesPaged returns.
    $fakeGraph = new FakeGraphApiClient($this->app->make(Filesystem::class));
    $fakeGraph->queueDiscoveryResponse([
        ['id' => 'graph-ah-1', 'from' => ['emailAddress' => ['address' => 'noreply@ah.nl', 'name' => 'Albert Heijn']], 'receivedDateTime' => '2026-05-14T07:00:00Z', 'subject' => 'Je bestelling is bevestigd'],
        ['id' => 'graph-googleplay-1', 'from' => ['emailAddress' => ['address' => 'googleplay-noreply@google.com', 'name' => 'Google Play']], 'receivedDateTime' => '2026-05-14T07:30:00Z', 'subject' => 'Your Google Play Order Receipt'],
    ]);
    $this->app->instance(GraphApiClientContract::class, $fakeGraph);

    // Pre-condition: the inbox root is empty (the discovery loop must
    // not create it).
    expect(countFilesRecursive_TestHelper($this->inboxRoot))->toBe(0);

    /** @var DiscoveryScanJob $job */
    $job = $this->app->make(DiscoveryScanJob::class, ['userId' => $user->id]);
    $this->app->call([$job, 'handle']);

    // Assert: 3 NEW candidate rows landed (Bol.com + Coolblue from
    // Gmail + Albert Heijn from Graph). PayPal + Google Play were
    // filtered out by the known-senders exclude list.
    $candidates = $db->connection()
        ->table('discovered_senders')
        ->where('user_id', $user->id)
        ->orderBy('sender_email', 'asc')
        ->get();
    expect($candidates)->toHaveCount(3);

    $emails = array_map(static fn ($r): string => (string) $r->sender_email, $candidates->all());
    expect($emails)->toBe(['noreply@ah.nl', 'noreply@coolblue.nl', 'orders@bol.com']);

    foreach ($candidates as $row) {
        expect($row->state)->toBe('candidate');
        expect((int) $row->occurrence_count)->toBe(1);
    }

    // CRITICAL INVARIANT: the storage/app/inbox tree must still be
    // empty — the discovery loop NEVER persists .eml blobs.
    expect(countFilesRecursive_TestHelper($this->inboxRoot))->toBe(0);

    // Re-run with the same queued payload + a re-queued response.
    $fakeGmail2 = new FakeGmailApiClient($this->app->make(Filesystem::class));
    $fakeGmail2->queueDiscoveryResponse([
        ['id' => 'msg-bol-2', 'fromAddress' => 'orders@bol.com', 'fromName' => 'Bol.com', 'internalDate' => '2026-05-15T08:12:00Z'],
    ]);
    $this->app->instance(GmailApiClientContract::class, $fakeGmail2);

    $fakeGraph2 = new FakeGraphApiClient($this->app->make(Filesystem::class));
    $fakeGraph2->queueDiscoveryResponse([
        ['id' => 'graph-ah-2', 'from' => ['emailAddress' => ['address' => 'noreply@ah.nl', 'name' => 'Albert Heijn']], 'receivedDateTime' => '2026-05-15T07:00:00Z', 'subject' => 'Je bestelling is bevestigd'],
    ]);
    $this->app->instance(GraphApiClientContract::class, $fakeGraph2);

    /** @var DiscoveryScanJob $job2 */
    $job2 = $this->app->make(DiscoveryScanJob::class, ['userId' => $user->id]);
    $this->app->call([$job2, 'handle']);

    // Bol.com + Albert Heijn occurrence_count both bump to 2; Coolblue
    // stays at 1 (it was not in the second-run payload).
    $bol = $db->connection()->table('discovered_senders')
        ->where('user_id', $user->id)->where('sender_email', 'orders@bol.com')->first();
    expect($bol)->not->toBeNull();
    expect((int) $bol->occurrence_count)->toBe(2);

    $ah = $db->connection()->table('discovered_senders')
        ->where('user_id', $user->id)->where('sender_email', 'noreply@ah.nl')->first();
    expect($ah)->not->toBeNull();
    expect((int) $ah->occurrence_count)->toBe(2);

    $coolblue = $db->connection()->table('discovered_senders')
        ->where('user_id', $user->id)->where('sender_email', 'noreply@coolblue.nl')->first();
    expect($coolblue)->not->toBeNull();
    expect((int) $coolblue->occurrence_count)->toBe(1);

    // Still no .eml blobs on disk.
    expect(countFilesRecursive_TestHelper($this->inboxRoot))->toBe(0);

    // Flip Coolblue to state='dismissed' + re-run with a payload that
    // includes Coolblue + a fresh new sender. The dismissed row must
    // NOT have its count bumped; the new sender lands as a fresh row.
    $db->connection()->table('discovered_senders')
        ->where('id', $coolblue->id)
        ->update(['state' => 'dismissed', 'updated_at' => $now]);

    $fakeGmail3 = new FakeGmailApiClient($this->app->make(Filesystem::class));
    $fakeGmail3->queueDiscoveryResponse([
        ['id' => 'msg-coolblue-3', 'fromAddress' => 'noreply@coolblue.nl', 'fromName' => 'Coolblue', 'internalDate' => '2026-05-16T10:00:00Z'],
        ['id' => 'msg-fresh-1', 'fromAddress' => 'orders@picnic.nl', 'fromName' => 'Picnic', 'internalDate' => '2026-05-16T11:00:00Z'],
    ]);
    $this->app->instance(GmailApiClientContract::class, $fakeGmail3);

    $fakeGraph3 = new FakeGraphApiClient($this->app->make(Filesystem::class));
    $fakeGraph3->queueDiscoveryResponse([]);
    $this->app->instance(GraphApiClientContract::class, $fakeGraph3);

    /** @var DiscoveryScanJob $job3 */
    $job3 = $this->app->make(DiscoveryScanJob::class, ['userId' => $user->id]);
    $this->app->call([$job3, 'handle']);

    // Coolblue's count remains at 1 + state stays 'dismissed'. Picnic
    // lands as a fresh 'candidate' row.
    $coolblueAfter = $db->connection()->table('discovered_senders')
        ->where('user_id', $user->id)->where('sender_email', 'noreply@coolblue.nl')->first();
    expect($coolblueAfter)->not->toBeNull();
    expect($coolblueAfter->state)->toBe('dismissed');
    expect((int) $coolblueAfter->occurrence_count)->toBe(1);

    $picnic = $db->connection()->table('discovered_senders')
        ->where('user_id', $user->id)->where('sender_email', 'orders@picnic.nl')->first();
    expect($picnic)->not->toBeNull();
    expect($picnic->state)->toBe('candidate');
    expect((int) $picnic->occurrence_count)->toBe(1);

    // Final invariant assertion — no .eml blobs ever materialised
    // during the three discovery passes.
    expect(countFilesRecursive_TestHelper($this->inboxRoot))->toBe(0);

    // Defensive grep over the Gmail Fake's call log: discovery MUST
    // NOT call getRawMessage. If a regression slipped a body-fetch into
    // the discovery path, the call log would contain a getRawMessage
    // entry.
    $allCalls = array_merge(
        $fakeGmail->getRequestedCalls(),
        $fakeGmail2->getRequestedCalls(),
        $fakeGmail3->getRequestedCalls(),
        $fakeGraph->getRequestedCalls(),
        $fakeGraph2->getRequestedCalls(),
        $fakeGraph3->getRequestedCalls(),
    );
    $rawCalls = array_filter(
        $allCalls,
        static fn (array $c): bool => $c['method'] === 'getRawMessage',
    );
    expect($rawCalls)->toBe([]);
});

/**
 * Count files (not directories) under the given root, recursively. Returns
 * 0 when the root does not exist. Used to assert the .eml-blob invariant —
 * the discovery loop must never write into storage/app/inbox/.
 */
function countFilesRecursive_TestHelper(string $root): int
{
    if (! is_dir($root)) {
        return 0;
    }
    $count = 0;
    $iter = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
    );
    foreach ($iter as $fileInfo) {
        if ($fileInfo->isFile()) {
            $count++;
        }
    }

    return $count;
}
