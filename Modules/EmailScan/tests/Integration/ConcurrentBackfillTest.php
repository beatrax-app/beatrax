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

// `PRAGMA busy_timeout = 5000` inside each per-page transaction is what makes
// two backfills for one user wait rather than raise SQLITE_BUSY. A
// single-threaded PHP test cannot stage two real writers, so a recording
// decorator proves the pragma is issued before every insert instead.

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

    $captured = $recordingDb->capturedStatements;

    // The pragma has to be the first statement in each transaction body, and
    // the job walks one page of three messages, so at least three of them.
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

    // The recording wrapper is a passthrough, so the writes still landed.
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

    $connection->statement('PRAGMA busy_timeout = 5000');

    expect(true)->toBeTrue();
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
