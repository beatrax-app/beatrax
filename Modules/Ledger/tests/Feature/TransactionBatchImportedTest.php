<?php

declare(strict_types=1);

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\DB;
use Modules\Import\Public\Events\TransactionImported;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\ImportRun;
use Modules\Ledger\Public\Actions\RecordTransactions;
use Modules\Ledger\Public\Events\TransactionBatchImported;

/** @var list<TransactionBatchImported> */
$recordedBatches = [];

/** @var list<TransactionImported> */
$recordedRows = [];

beforeEach(function () use (&$recordedBatches, &$recordedRows): void {
    $recordedBatches = [];
    $recordedRows = [];

    $this->user = User::query()->create([
        'username' => 'batch-importer',
        'password' => 'fixture-password',
        'period_start_day' => 1,
    ]);
    $this->account = Account::create([
        'user_id' => $this->user->id,
        'name' => 'ASN',
        'slug' => 'asn-batch',
        'kind' => 'bank',
        'iban' => 'NL57ASNB0123456780',
        'default_currency' => 'EUR',
    ]);
    $this->importRun = ImportRun::create([
        'user_id' => $this->user->id,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/batch-test.csv',
        'sha256' => str_repeat('d', 64),
        'uploaded_at' => CarbonImmutable::now(),
        'status' => 'previewed',
    ]);

    /** @var Dispatcher $events */
    $events = $this->app->make(Dispatcher::class);

    $events->listen(
        TransactionBatchImported::class,
        static function (TransactionBatchImported $event) use (&$recordedBatches): void {
            $recordedBatches[] = $event;
        },
    );
    $events->listen(
        TransactionImported::class,
        static function (TransactionImported $event) use (&$recordedRows): void {
            $recordedRows[] = $event;
        },
    );

    // Distinct fingerprints, so every row in a batch is a genuine insert.
    $this->distinctBatch = function (int $count, string $sourceFormat = 'csv', int $offset = 0): array {
        $rows = [];

        for ($i = 0; $i < $count; $i++) {
            $idx = $offset + $i;
            $second = str_pad((string) ($idx % 60), 2, '0', STR_PAD_LEFT);
            $minute = str_pad((string) (intdiv($idx, 60) % 60), 2, '0', STR_PAD_LEFT);

            $rows[] = $this->canonical([
                'userId' => $this->user->id,
                'accountId' => $this->account->id,
                'importRunId' => $this->importRun->id,
                'bookedAt' => CarbonImmutable::parse("2026-02-10 09:{$minute}:{$second}"),
                'counterpartyNormalized' => "batch merchant {$idx}",
                'sourceRowIndex' => $idx,
                'sourceRef' => "BATCH-REF-{$idx}",
                'sourceFormat' => $sourceFormat,
            ]);
        }

        return $rows;
    };
});

it('dispatches exactly ONE batch event for a 500-row single-format import, plus 500 per-row events (Req 10)', function () use (&$recordedBatches, &$recordedRows): void {
    $action = $this->app->make(RecordTransactions::class);
    $rows = ($this->distinctBatch)(500, 'csv');

    $result = $action($rows, $this->user);

    expect($result->inserted)->toBe(500);
    expect($recordedBatches)->toHaveCount(1);
    expect($recordedRows)->toHaveCount(500);

    $batch = $recordedBatches[0];
    expect($batch->userId)->toBe($this->user->id);
    expect($batch->insertedCount)->toBe(500);
    expect($batch->sourceFormats)->toBe(['csv']);
});

it('dispatches ZERO batch events when every row is a fingerprint duplicate', function () use (&$recordedBatches): void {
    $action = $this->app->make(RecordTransactions::class);
    $rows = ($this->distinctBatch)(10, 'csv');

    // First run inserts everything, second run is all-duplicate.
    $action($rows, $this->user);
    $recordedBatches = [];

    $second = $action($rows, $this->user);

    expect($second->inserted)->toBe(0);
    expect($second->duplicates)->toBe(10);
    expect($recordedBatches)->toHaveCount(0);
});

it('reports both formats sorted for a mixed csv + eml batch, in a single event', function () use (&$recordedBatches): void {
    $action = $this->app->make(RecordTransactions::class);

    $csvRows = ($this->distinctBatch)(3, 'csv', offset: 0);
    $emlRows = ($this->distinctBatch)(2, 'eml', offset: 100);

    $result = $action([...$csvRows, ...$emlRows], $this->user);

    expect($result->inserted)->toBe(5);
    expect($recordedBatches)->toHaveCount(1);
    expect($recordedBatches[0]->sourceFormats)->toBe(['csv', 'eml']);
    expect($recordedBatches[0]->insertedCount)->toBe(5);
});

it('dispatches the per-row and batch events outside any open DB transaction (D-28 / WR-06)', function (): void {
    // RefreshDatabase already holds an outer transaction, so `=== 0` would prove
    // nothing. Both events must land back at that baseline: the per-row one used
    // to fire inside persistChunk's own transaction, so a rollback left Search,
    // Transfers, Receipts and Anomaly acting on rows that never committed.
    $baseline = DB::transactionLevel();

    /** @var Dispatcher $events */
    $events = $this->app->make(Dispatcher::class);

    $rowLevel = null;
    $events->listen(
        TransactionImported::class,
        function () use (&$rowLevel): void {
            $rowLevel ??= DB::transactionLevel();
        },
    );

    $batchLevel = null;
    $events->listen(
        TransactionBatchImported::class,
        function () use (&$batchLevel): void {
            $batchLevel = DB::transactionLevel();
        },
    );

    $action = $this->app->make(RecordTransactions::class);
    $rows = ($this->distinctBatch)(5, 'csv');

    $action($rows, $this->user);

    expect($rowLevel)->toBe($baseline, 'TransactionImported must fire after persistChunk commits');
    expect($batchLevel)->toBe($baseline);
});

it('owes its after-commit ordering to the action, not to a framework marker interface', function (): void {
    expect(new ReflectionClass(TransactionImported::class)->getInterfaceNames())
        ->not->toContain('Illuminate\\Contracts\\Events\\ShouldHandleEventsAfterCommit')
        ->not->toContain('Illuminate\\Contracts\\Queue\\ShouldQueue');
});
