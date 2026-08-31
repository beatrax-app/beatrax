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
use Modules\EmailScan\Internal\Jobs\GraphDeltaWalk;
use Modules\EmailScan\Internal\Jobs\JobUserContext;
use Modules\EmailScan\Internal\MimeHeaderParser;
use Modules\EmailScan\Public\Services\EmlBlobStore;
use Modules\EmailScan\Public\Services\KnownSenderQuery;

uses(RefreshDatabase::class);

// Two backfills for one user have to wait rather than raise SQLITE_BUSY, and
// what makes them wait is the connection's busy_timeout. That used to be set
// per transaction here, at 5000 -- which SILENTLY CUT the 30s config/database.php
// asks for, because the pragma is connection-scoped and outlives the
// transaction that issued it. The connection now carries the configured value
// and no write path re-sets it, so this asserts the value in force during the
// job rather than the statement that used to impose it.

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

it('keeps the configured busy_timeout in force through every per-page transaction', function (): void {
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
        $this->app->make(GraphDeltaWalk::class),
    );

    // No write path may re-issue the pragma: one that does lowers the timeout
    // for every later statement on the same connection, which is the regression
    // this file exists to catch.
    foreach ($recordingDb->capturedStatements as $tx) {
        foreach ($tx as $statement) {
            expect((string) $statement)->not->toContain('PRAGMA busy_timeout');
        }
    }

    $timeout = (int) ($realDb->connection()->select('PRAGMA busy_timeout')[0]->timeout ?? 0);
    expect($timeout)->toBeGreaterThanOrEqual(30_000);

    // The recording wrapper is a passthrough, so the writes still landed.
    $msgCount = $realDb->connection()
        ->table('inbox_messages')
        ->where('inbox_id', $inboxId)
        ->count();
    expect($msgCount)->toBe(3);
});

// SqliteOptimizationsProvider applies it once, at ConnectionEstablished, from
// config. Proved here because a test connection that did not get it would make
// the assertion above pass for the wrong reason.
it('carries the configured busy_timeout on a connection nothing has touched', function (): void {
    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);

    $timeout = (int) ($db->connection()->select('PRAGMA busy_timeout')[0]->timeout ?? 0);

    expect($timeout)->toBeGreaterThanOrEqual(30_000);
});

// capturedStatements collects one array per transaction() invocation, holding
// the raw SQL issued via statement() inside that transaction body.
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
