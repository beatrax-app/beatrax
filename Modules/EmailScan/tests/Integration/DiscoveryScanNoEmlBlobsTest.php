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

// The discovery job populates discovered_senders from a subject query without
// ever persisting a .eml body: Gmail is asked for headers only, Graph for
// $select=id,from,subject,receivedDateTime. Every pass here re-asserts that
// storage/app/inbox stays empty.

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
        'username' => 'discovery',
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

    // Page 1 mixes new senders with PayPal, which the known-senders exclude
    // list has to drop before the upsert.
    $fakeGmail = new FakeGmailApiClient($this->app->make(Filesystem::class));
    $fakeGmail->queueDiscoveryResponse([
        ['id' => 'msg-bol-1', 'fromAddress' => 'orders@bol.com', 'fromName' => 'Bol.com', 'internalDate' => '2026-05-14T08:12:00Z'],
        ['id' => 'msg-paypal-1', 'fromAddress' => 'service@paypal.com', 'fromName' => 'PayPal', 'internalDate' => '2026-05-14T09:00:00Z'],
        ['id' => 'msg-coolblue-1', 'fromAddress' => 'noreply@coolblue.nl', 'fromName' => 'Coolblue', 'internalDate' => '2026-05-14T09:30:00Z'],
    ]);
    $this->app->instance(GmailApiClientContract::class, $fakeGmail);

    // Same shape on the Graph side, in the payload the real
    // listDiscoveryCandidatesPaged returns.
    $fakeGraph = new FakeGraphApiClient($this->app->make(Filesystem::class));
    $fakeGraph->queueDiscoveryResponse([
        ['id' => 'graph-ah-1', 'from' => ['emailAddress' => ['address' => 'noreply@ah.nl', 'name' => 'Albert Heijn']], 'receivedDateTime' => '2026-05-14T07:00:00Z', 'subject' => 'Je bestelling is bevestigd'],
        ['id' => 'graph-googleplay-1', 'from' => ['emailAddress' => ['address' => 'googleplay-noreply@google.com', 'name' => 'Google Play']], 'receivedDateTime' => '2026-05-14T07:30:00Z', 'subject' => 'Your Google Play Order Receipt'],
    ]);
    $this->app->instance(GraphApiClientContract::class, $fakeGraph);

    expect(countFilesRecursive_TestHelper($this->inboxRoot))->toBe(0);

    /** @var DiscoveryScanJob $job */
    $job = $this->app->make(DiscoveryScanJob::class, ['userId' => $user->id]);
    $this->app->call([$job, 'handle']);

    // Bol.com, Coolblue and Albert Heijn land; PayPal and Google Play were
    // excluded as known senders.
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

    expect(countFilesRecursive_TestHelper($this->inboxRoot))->toBe(0);

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

    // Coolblue is absent from the second payload, so only Bol.com and Albert
    // Heijn bump.
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

    expect(countFilesRecursive_TestHelper($this->inboxRoot))->toBe(0);

    // Coolblue is dismissed but still in the next payload: its count must not
    // bump, while the sender alongside it still lands.
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

    expect(countFilesRecursive_TestHelper($this->inboxRoot))->toBe(0);

    // An empty inbox tree would also hold if a body fetch were made and
    // discarded, so the call log is checked for getRawMessage directly.
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

// Returns 0 for a root that does not exist, which is the state the discovery
// loop is supposed to leave storage/app/inbox in.
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
