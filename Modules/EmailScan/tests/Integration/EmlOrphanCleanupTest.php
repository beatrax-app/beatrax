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

// A blob on disk with no index row behind it would be invisible to every
// later scan, so a failed transaction has to unlink the .eml it just wrote.
// The second pass re-runs without the injected failure, because the retry
// after such a rollback is the case that has to stay idempotent.

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

    $fake = new FakeGmailApiClient($this->app->make(Filesystem::class));
    $this->app->instance(GmailApiClientContract::class, $fake);
    $graphFake = new FakeGraphApiClient($this->app->make(Filesystem::class));
    $this->app->instance(GraphApiClientContract::class, $graphFake);

    // The throw lands on the first per-page transaction, by which point the
    // .eml is already on disk.
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
            $this->app->make(GraphDeltaWalk::class),
        );
        $this->fail('Expected RuntimeException from injected DB failure');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toContain('injected-tx-failure');
    }

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

    $rowsAfterFail = $realDb->connection()
        ->table('inbox_messages')
        ->where('inbox_id', $inboxId)
        ->count();
    expect($rowsAfterFail)->toBe(0);

    // A fresh Fake, because the first pass consumed the page cursor and a
    // retry starts a new provider session.
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

// Forces the .eml-then-DB rollback path without touching production code.
final class FailingTransactionDbManager extends DatabaseManager
{
    public function __construct(
        private readonly DatabaseManager $inner,
        private readonly int $failOnCall,
    ) {
        // Every call proxies to $this->inner, so the parent constructor has
        // nothing to set up.
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

final class FailingTransactionConnection extends Connection
{
    private int $transactionCallCount = 0;

    public function __construct(
        private readonly Connection $inner,
        private readonly int $failOnCall,
    ) {
        // The inner connection is already initialised and the parent's
        // protected state is never read here.
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
