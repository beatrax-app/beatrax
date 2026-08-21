<?php

declare(strict_types=1);

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Modules\Core\Models\User as CoreUser;
use Modules\Import\Public\Events\TransactionImported;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\ImportRun;
use Modules\Ledger\Models\Transaction;
use Modules\Ledger\Public\Actions\RecordTransactions;
use Modules\Ledger\Public\Contracts\CapturesTransactionsForSync;
use Modules\Ledger\Public\Services\FingerprintComposer;

/**
 * @var list<TransactionImported>
 */
$imported = [];

/**
 * @var list<int>
 */
$capturedIds = [];

beforeEach(function () use (&$imported, &$capturedIds): void {
    $imported = [];
    $capturedIds = [];

    $this->user = User::query()->create([
        'username' => 'readback-importer',
        'password' => 'fixture-password',
        'period_start_day' => 1,
    ]);
    $this->account = Account::create([
        'user_id' => $this->user->id,
        'name' => 'ASN',
        'slug' => 'asn',
        'kind' => 'bank',
        'iban' => 'NL57ASNB0123456789',
        'default_currency' => 'EUR',
    ]);
    $this->importRun = ImportRun::create([
        'user_id' => $this->user->id,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/readback-test.csv',
        'sha256' => str_repeat('r', 64),
        'uploaded_at' => CarbonImmutable::now(),
        'status' => 'previewed',
    ]);

    /** @var Dispatcher $events */
    $events = $this->app->make(Dispatcher::class);
    $events->listen(
        TransactionImported::class,
        static function (TransactionImported $event) use (&$imported): void {
            $imported[] = $event;
        },
    );

    // The ids the recorder hands the sync capture are built beside the models
    // the events carry, so both lists are read from one run.
    $this->app->instance(CapturesTransactionsForSync::class, new class($capturedIds) implements CapturesTransactionsForSync
    {
        /**
         * @param  list<int>  $seen
         */
        public function __construct(private array &$seen) {}

        public function captureTransactions(array $transactionIds, CoreUser $user): void
        {
            foreach ($transactionIds as $id) {
                $this->seen[] = $id;
            }
        }
    });

    $this->batch = function (int $count, int $offset = 0): array {
        $rows = [];
        for ($i = $offset; $i < $offset + $count; $i++) {
            $second = str_pad((string) ($i % 60), 2, '0', STR_PAD_LEFT);
            $minute = str_pad((string) (intdiv($i, 60) % 60), 2, '0', STR_PAD_LEFT);

            $rows[] = $this->canonical([
                'userId' => $this->user->id,
                'accountId' => $this->account->id,
                'importRunId' => $this->importRun->id,
                'bookedAt' => CarbonImmutable::parse("2026-01-15 10:{$minute}:{$second}"),
                'counterpartyNormalized' => "merchant {$i}",
                'sourceRowIndex' => $i,
                'sourceRef' => "ASN-REF-{$i}",
            ]);
        }

        return $rows;
    };
});

/**
 * Counts the fingerprint reads against transactions while $work runs. The
 * TransactionImported listeners query the table too, and those are not the
 * shape under test.
 *
 * @param  callable(): void  $work
 */
function readBackSelectCount(callable $work): int
{
    $count = 0;
    DB::listen(function ($query) use (&$count): void {
        $sql = strtolower(ltrim($query->sql));
        if (str_starts_with($sql, 'select') && str_contains($sql, 'from "transactions"') && str_contains($sql, '"fingerprint"')) {
            $count++;
        }
    });

    $work();

    return $count;
}

it('reads a chunk of inserted rows back in one select instead of one per row', function (): void {
    $action = $this->app->make(RecordTransactions::class);
    $rows = ($this->batch)(40);

    $selects = readBackSelectCount(function () use ($action, $rows): void {
        $action($rows, $this->user);
    });

    expect($selects)->toBe(1)
        ->and(Transaction::query()->where('user_id', $this->user->id)->count())->toBe(40);
});

it('carries the same models in the same association and the same order', function () use (&$imported, &$capturedIds): void {
    $action = $this->app->make(RecordTransactions::class);
    $rows = ($this->batch)(25);

    $result = $action($rows, $this->user);

    expect($result->inserted)->toBe(25)
        ->and($imported)->toHaveCount(25)
        ->and($capturedIds)->toHaveCount(25);

    $expectedIds = Transaction::query()
        ->where('user_id', $this->user->id)
        ->orderBy('source_row_index')
        ->pluck('id')
        ->all();

    $eventIds = array_map(static fn (TransactionImported $event): int => $event->transaction->id, $imported);

    expect($eventIds)->toBe($expectedIds)
        ->and($capturedIds)->toBe($expectedIds);

    // The model on event i has to be the row written for canonical row i, not
    // merely some row of the batch: a read-back keyed wrongly still produces
    // twenty-five models in twenty-five events.
    foreach ($imported as $index => $event) {
        expect($event->transaction->source_row_index)->toBe($index)
            ->and($event->transaction->counterparty_normalized)->toBe("merchant {$index}")
            ->and($event->transaction->user_id)->toBe($this->user->id);
    }
});

it('reads back only what it inserted when the batch mixes duplicates in', function () use (&$imported, &$capturedIds): void {
    $action = $this->app->make(RecordTransactions::class);

    $action(($this->batch)(6), $this->user);
    $imported = [];
    $capturedIds = [];

    // Rows 0-5 are already recorded; 6-11 are new, so the read-back must find
    // six rows and the events must name those six.
    $result = $action(($this->batch)(12), $this->user);

    expect($result->inserted)->toBe(6)
        ->and($result->duplicates)->toBe(6)
        ->and($imported)->toHaveCount(6);

    $eventIndexes = array_map(
        static fn (TransactionImported $event): int => $event->transaction->source_row_index,
        $imported,
    );

    expect($eventIndexes)->toBe([6, 7, 8, 9, 10, 11])
        ->and($capturedIds)->toBe(array_map(
            static fn (TransactionImported $event): int => $event->transaction->id,
            $imported,
        ));
});

it('never reads another reader\'s row back for a fingerprint that collides', function () use (&$imported): void {
    $stranger = User::query()->create([
        'username' => 'readback-stranger',
        'password' => 'fixture-password',
        'period_start_day' => 1,
    ]);
    $strangerAccount = Account::create([
        'user_id' => $stranger->id,
        'name' => 'Stranger ASN',
        'slug' => 'stranger-asn',
        'kind' => 'bank',
        'iban' => 'NL57ASNB0987654321',
        'default_currency' => 'EUR',
    ]);
    $strangerRun = ImportRun::create([
        'user_id' => $stranger->id,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/readback-stranger.csv',
        'sha256' => str_repeat('s', 64),
        'uploaded_at' => CarbonImmutable::now(),
        'status' => 'previewed',
    ]);

    $rows = ($this->batch)(3);

    /** @var FingerprintComposer $fingerprints */
    $fingerprints = $this->app->make(FingerprintComposer::class);

    // The fingerprint hashes the user id, so a collision cannot arise by
    // accident — it is forced here because a read-back that forgets to scope by
    // owner would hand this reader's row to the other one's import.
    foreach ($rows as $index => $row) {
        Transaction::query()->create([
            'user_id' => $stranger->id,
            'account_id' => $strangerAccount->id,
            'type' => 'expense',
            'posted_at' => CarbonImmutable::parse('2026-01-15'),
            'booked_at' => CarbonImmutable::parse('2026-01-15 09:00:00'),
            'value_date' => CarbonImmutable::parse('2026-01-15'),
            'amount_minor' => -500,
            'currency' => 'EUR',
            'settled_amount_minor' => -500,
            'settled_currency' => 'EUR',
            'counterparty_normalized' => "stranger {$index}",
            'normalization_version' => 1,
            'source_format' => 'asn-csv',
            'import_run_id' => $strangerRun->id,
            'source_row_index' => $index,
            'source_ref' => "STRANGER-{$index}",
            'fingerprint' => $fingerprints->compose($row),
            'fingerprint_version' => $fingerprints->version(),
            'status' => 'uncleared',
        ]);
    }

    $action = $this->app->make(RecordTransactions::class);
    $result = $action($rows, $this->user);

    expect($result->inserted)->toBe(3)
        ->and($imported)->toHaveCount(3);

    foreach ($imported as $event) {
        expect($event->transaction->user_id)->toBe($this->user->id);
    }
});

it('still fails loudly when a row it wrote cannot be read back', function (): void {
    // Stands in for the row the insert reported as written and the read cannot
    // find: firstOrFail() raised here, and a silently shorter batch would leave
    // the sync capture and every listener unaware of money that was recorded.
    Transaction::addGlobalScope('readback-blind', static function (Builder $query): void {
        $query->whereRaw('1 = 0');
    });

    $action = $this->app->make(RecordTransactions::class);

    expect(fn () => $action(($this->batch)(3), $this->user))
        ->toThrow(ModelNotFoundException::class);

    Transaction::addGlobalScope('readback-blind', static function (Builder $query): void {});
});
