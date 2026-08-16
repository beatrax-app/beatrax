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
 * Orphan-cleanup invariant: when the DB transaction that follows
 * a .eml write fails, the catch block unlinks the .eml so there
 * is never a blob on disk without a matching index row.
 *
 * The test injects a wrapper DatabaseManager whose connection
 * proxy throws on the FIRST transaction() call. The job has just
 * written the .eml to disk; after the throw it must unlink the
 * blob and re-throw.
 *
 * The second part of the test runs the same job again WITHOUT
 * the injected failure to confirm the path is fully idempotent —
 * UNIQUE on (inbox_id, provider_message_id) + insertOrIgnore +
 * atomic .eml-then-DB ordering makes a retry safe.
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

it('unlinks the .eml when the following DB transaction throws, then succeeds on retry', function (): void {
    $user = User::query()->create([
        'username' => 'orphan',
        'password' => 'fixture',
        'period_start_day' => 1,
    ]);

    /** @var DatabaseManager $realDb */
    $realDb = $this->app->make(DatabaseManager::class);
    $now = CarbonImmutable::now()->toDateTimeString();

    $inboxId = (int) $realDb->connection()->table('inboxes')->insertGetId([
        'user_id' => $user->id,
        'provider' => 'gmail',
        'email' => 'orphan@example.com',
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

    // Bind the Fake.
    $fake = new FakeGmailApiClient($this->app->make(Filesystem::class));
    $this->app->instance(GmailApiClientContract::class, $fake);
    $graphFake = new FakeGraphApiClient($this->app->make(Filesystem::class));
    $this->app->instance(GraphApiClientContract::class, $graphFake);

    // First pass: inject a one-shot failing connection wrapper so
    // the FIRST per-page transaction throws RuntimeException after
    // the .eml is on disk.
    $failingDb = new FailingTransactionDbManager($realDb, failOnCall: 1);

    $job = new BackfillInboxJob($inboxId, 3);
    try {
        $job->handle(
            $failingDb,
            $this->app->make(Clock::class),
            $fake,
            $graphFake,
            $this->app->make(EmlBlobStore::class),
            $this->app->make(MimeHeaderParser::class),
            new InboxScanStateMachine($realDb, $this->app->make(Clock::class)),
            $this->app->make(KnownSenderQuery::class),
            $this->app->make(JobUserContext::class),
        );
        $this->fail('Expected RuntimeException from injected DB failure');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toContain('injected-tx-failure');
    }

    // Assert: NO .eml blobs remain on disk for the inbox.
    $inboxRoot = storage_path('app/inbox/'.$user->id.'/'.$inboxId);
    if (is_dir($inboxRoot)) {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(
            $inboxRoot,
            RecursiveDirectoryIterator::SKIP_DOTS,
        ));
        $leftover = [];
        foreach ($iterator as $file) {
            if ($file->isFile() && str_ends_with($file->getFilename(), '.eml')) {
                $leftover[] = $file->getPathname();
            }
        }
        expect($leftover)->toBe([], 'Expected no orphan .eml blobs');
    }

    // Assert: no inbox_messages rows landed either.
    $rowsAfterFail = $realDb->connection()
        ->table('inbox_messages')
        ->where('inbox_id', $inboxId)
        ->count();
    expect($rowsAfterFail)->toBe(0);

    // Second pass: re-dispatch without the failure injection.
    // Reset the Fake's page cursor since the first pass consumed
    // it (a fresh Fake mirrors a fresh provider session).
    $fake2 = new FakeGmailApiClient($this->app->make(Filesystem::class));
    $this->app->instance(GmailApiClientContract::class, $fake2);
    $graphFake2 = new FakeGraphApiClient($this->app->make(Filesystem::class));
    $this->app->instance(GraphApiClientContract::class, $graphFake2);

    $job2 = $this->app->make(BackfillInboxJob::class, ['inboxId' => $inboxId, 'windowMonths' => 3]);
    $this->app->call([$job2, 'handle']);

    $rowsAfterRetry = $realDb->connection()
        ->table('inbox_messages')
        ->where('inbox_id', $inboxId)
        ->count();
    expect($rowsAfterRetry)->toBe(3);
});

/**
 * Minimal DatabaseManager wrapper that proxies every call to the
 * underlying real manager EXCEPT `connection()`, which returns a
 * Connection proxy whose `transaction()` throws on the configured
 * call number.
 *
 * Used only by EmlOrphanCleanupTest to force the .eml-then-DB
 * rollback path without touching production code paths.
 */
final class FailingTransactionDbManager extends DatabaseManager
{
    public function __construct(
        private readonly DatabaseManager $inner,
        private readonly int $failOnCall,
    ) {
        // Skip parent constructor — we proxy every call through
        // $this->inner. The DatabaseManager constructor signature
        // is not part of the public contract.
    }

    /**
     * @param  string|null  $name
     */
    public function connection($name = null): Connection
    {
        return new FailingTransactionConnection(
            $this->inner->connection($name),
            failOnCall: $this->failOnCall,
        );
    }
}

/**
 * Decorator over a real Connection that counts transaction()
 * calls and throws RuntimeException once the configured call
 * number is reached. Every other method is forwarded verbatim.
 */
final class FailingTransactionConnection extends Connection
{
    private int $transactionCallCount = 0;

    public function __construct(
        private readonly Connection $inner,
        private readonly int $failOnCall,
    ) {
        // Skip parent constructor — the inner connection is fully
        // initialised; we only intercept transaction() + table()
        // + statement(). The parent Connection's protected state
        // is never touched.
    }

    public function transaction(Closure $callback, $attempts = 1)
    {
        $this->transactionCallCount++;
        if ($this->transactionCallCount === $this->failOnCall) {
            throw new RuntimeException('injected-tx-failure');
        }

        return $this->inner->transaction($callback, $attempts);
    }

    public function table($table, $as = null)
    {
        return $this->inner->table($table, $as);
    }

    public function statement($query, $bindings = [])
    {
        return $this->inner->statement($query, $bindings);
    }
}
