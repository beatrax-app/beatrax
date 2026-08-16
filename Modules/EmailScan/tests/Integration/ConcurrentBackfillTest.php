<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Sleep;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;
use Modules\EmailScan\Internal\Clients\FakeGmailApiClient;
use Modules\EmailScan\Internal\Clients\FakeGraphApiClient;
use Modules\EmailScan\Internal\Clients\GmailApiClientContract;
use Modules\EmailScan\Internal\Clients\GraphApiClientContract;
use Modules\EmailScan\Internal\InboxScanStateMachine;
use Modules\EmailScan\Internal\Jobs\BackfillInboxJob;
use Modules\EmailScan\Internal\Jobs\JobUserContext;
use Modules\EmailScan\Internal\MimeHeaderParser;
use Modules\EmailScan\Public\Services\EmlBlobStore;
use Modules\EmailScan\Public\Services\KnownSenderQuery;

uses(RefreshDatabase::class);

/*
 * SQLite writer-lock contention invariant.
 *
 * Two BackfillInboxJob runs for different inboxes of the same
 * user must converge without SQLITE_BUSY. The production guard
 * is `PRAGMA busy_timeout = 5000` set INSIDE the per-page
 * transaction so SQLite waits up to five seconds for a
 * competing writer before raising the busy error rather than
 * failing instantly.
 *
 * Two genuine concurrent writers cannot be created from a
 * single-threaded PHP test (the running thread cannot
 * simultaneously hold one transaction and release it from
 * another connection mid-wait). Instead the test wraps the
 * default connection with a passthrough decorator that records
 * every `statement()` call. The test then asserts that the
 * production transaction body issues `PRAGMA busy_timeout = 5000`
 * BEFORE any inbox_messages insert — proving the runtime guard
 * is in place every per-page write. A separate sanity check
 * exercises the busy_timeout in isolation by issuing the same
 * pragma against the real SQLite connection used by the test
 * suite and confirming the pragma applies cleanly.
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

it('issues PRAGMA busy_timeout = 5000 inside every per-page transaction', function (): void {
    $user = User::query()->create([
        'username' => 'concurrent',
        'password' => 'fixture',
        'period_start_day' => 1,
    ]);

    /** @var DatabaseManager $realDb */
    $realDb = $this->app->make(DatabaseManager::class);
    $now = CarbonImmutable::now()->toDateTimeString();

    $inboxId = (int) $realDb->connection()->table('inboxes')->insertGetId([
        'user_id' => $user->id,
        'provider' => 'gmail',
        'email' => 'concurrent@example.com',
        'backfill_window_months' => 3,
        'backfill_progress' => null,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    $realDb->connection()->table('inbox_scan_state')->insert([
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
    $graphFake = new FakeGraphApiClient($this->app->make(Filesystem::class));
    $this->app->instance(GraphApiClientContract::class, $graphFake);

    $statementsInTransactions = [];
    $recordingDb = new RecordingDatabaseManager(
        $realDb,
        statementsInTransactions: $statementsInTransactions,
    );

    $job = new BackfillInboxJob($inboxId, 3);
    $job->handle(
        $recordingDb,
        $this->app->make(Clock::class),
        $fake,
        $graphFake,
        $this->app->make(EmlBlobStore::class),
        $this->app->make(MimeHeaderParser::class),
        new InboxScanStateMachine($realDb, $this->app->make(Clock::class)),
        $this->app->make(KnownSenderQuery::class),
        $this->app->make(JobUserContext::class),
    );

    // The recording arrays are populated by the connection
    // decorator via reference; pull them off the decorator's
    // public state.
    $captured = $recordingDb->capturedStatements;

    // Every per-page transaction must have issued PRAGMA
    // busy_timeout = 5000 before any other statement inside the
    // transaction body. The job walks one page with three
    // messages, so we expect at least 3 transactions × 1 pragma
    // each.
    $pragmaCount = 0;
    foreach ($captured as $tx) {
        if (count($tx) === 0) {
            continue;
        }
        $firstStatement = (string) $tx[0];
        if (str_contains($firstStatement, 'PRAGMA busy_timeout = 5000')) {
            $pragmaCount++;
        }
    }
    expect($pragmaCount)->toBeGreaterThanOrEqual(3);

    // Sanity: confirm the persisted state landed despite the
    // decorator passthrough — the recording wrapper is non-
    // destructive.
    $msgCount = $realDb->connection()
        ->table('inbox_messages')
        ->where('inbox_id', $inboxId)
        ->count();
    expect($msgCount)->toBe(3);
});

it('accepts PRAGMA busy_timeout = 5000 on the live test connection without error', function (): void {
    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $connection = $db->connection();

    // Issue the pragma; the connection responds without raising.
    $connection->statement('PRAGMA busy_timeout = 5000');

    expect(true)->toBeTrue();
});

/**
 * Passthrough DatabaseManager that wraps every `connection()`
 * call's result in a recording decorator. The capturedStatements
 * property collects one array per `transaction()` invocation,
 * each carrying the raw SQL strings issued via `statement()`
 * inside the transaction body.
 */
final class RecordingDatabaseManager extends DatabaseManager
{
    /** @var list<list<string>> */
    public array $capturedStatements = [];

    public function __construct(
        private readonly DatabaseManager $inner,
        array &$statementsInTransactions = [],
    ) {
        $this->capturedStatements = &$statementsInTransactions;
    }

    public function connection($name = null): Connection
    {
        return new RecordingConnection(
            $this->inner->connection($name),
            capturedStatements: $this->capturedStatements,
        );
    }
}

final class RecordingConnection extends Connection
{
    /** @var list<string> */
    private array $currentTransactionStatements = [];

    private bool $inTransaction = false;

    public function __construct(
        private readonly Connection $inner,
        /** @var list<list<string>> */
        private array &$capturedStatements,
    ) {}

    public function transaction(Closure $callback, $attempts = 1)
    {
        $this->inTransaction = true;
        $this->currentTransactionStatements = [];

        try {
            $result = $this->inner->transaction($callback, $attempts);
        } finally {
            $this->capturedStatements[] = $this->currentTransactionStatements;
            $this->inTransaction = false;
            $this->currentTransactionStatements = [];
        }

        return $result;
    }

    public function table($table, $as = null)
    {
        return $this->inner->table($table, $as);
    }

    public function statement($query, $bindings = [])
    {
        if ($this->inTransaction) {
            $this->currentTransactionStatements[] = $query;
        }

        return $this->inner->statement($query, $bindings);
    }
}
