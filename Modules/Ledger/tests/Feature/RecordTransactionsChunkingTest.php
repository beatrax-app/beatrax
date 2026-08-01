<?php

declare(strict_types=1);

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\Dispatcher;
use Modules\Import\Public\Events\TransactionImported;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\ImportRun;
use Modules\Ledger\Models\Transaction;
use Modules\Ledger\Public\Actions\RecordTransactions;

/**
 * Records the TransactionImported events the synchronous dispatcher saw, so
 * the tests can prove exactly one event fires per inserted row and none for
 * ignored duplicates.
 *
 * @var list<TransactionImported>
 */
$recorded = [];

beforeEach(function () use (&$recorded): void {
    $recorded = [];

    $this->user = User::query()->create([
        'username' => 'chunk-importer',
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
        'raw_file_path' => '/tmp/chunk-test.csv',
        'sha256' => str_repeat('c', 64),
        'uploaded_at' => CarbonImmutable::now(),
        'status' => 'previewed',
    ]);

    // Subscribe a recording listener for the duration of the test. Uses the
    // container-bound Dispatcher (no facade) so the constructor-DI'd
    // dispatcher inside RecordTransactions emits to the same instance.
    /** @var Dispatcher $events */
    $events = $this->app->make(Dispatcher::class);
    $events->listen(
        TransactionImported::class,
        static function (TransactionImported $event) use (&$recorded): void {
            $recorded[] = $event;
        },
    );

    // Build $count canonical DTOs whose fingerprints are guaranteed distinct.
    // Varying bookedAt seconds, counterparty_normalized, source_row_index and
    // source_ref per row yields one unique fingerprint tuple per index, so
    // every row is a genuine insert and a re-run's duplicates line up
    // one-for-one. Lives here as a $this-bound closure because canonical() is
    // a protected TestCase method — a free function could not reach it.
    $this->distinctBatch = function (int $count): array {
        $rows = [];

        for ($i = 0; $i < $count; $i++) {
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

it('persists every row and emits one event per insert across multiple chunks', function () use (&$recorded): void {
    // 1,200 rows against CHUNK_SIZE=500 spans three chunks (500/500/200).
    $action = $this->app->make(RecordTransactions::class);

    $rows = ($this->distinctBatch)(1200);

    $result = $action($rows, $this->user);

    expect($result->inserted)->toBe(1200);
    expect($result->duplicates)->toBe(0);

    // Same persisted rows: all 1,200 land in the ledger.
    expect(Transaction::query()->where('user_id', $this->user->id)->count())->toBe(1200);

    // Exactly one TransactionImported event per inserted row.
    expect($recorded)->toHaveCount(1200);
});

it('ignores duplicates straddling a chunk boundary on re-run with no events', function () use (&$recorded): void {
    $action = $this->app->make(RecordTransactions::class);

    $rows = ($this->distinctBatch)(1200);

    // First run commits everything (1,200 events fire here).
    $first = $action($rows, $this->user);

    expect($first->inserted)->toBe(1200);
    expect($first->duplicates)->toBe(0);
    expect($recorded)->toHaveCount(1200);

    // Reset so the re-run's event count is measured cleanly.
    $recorded = [];

    // Re-run the identical batch. The duplicates span every chunk boundary
    // (rows 499/500 and 999/1000 straddle a 500-row chunk edge), so this
    // proves insertOrIgnore dedups across chunk commits, not just within one.
    $second = $action($rows, $this->user);

    expect($second->inserted)->toBe(0);
    expect($second->duplicates)->toBe(1200);

    // No row-count growth and no events for ignored duplicates.
    expect(Transaction::query()->where('user_id', $this->user->id)->count())->toBe(1200);
    expect($recorded)->toHaveCount(0);
});
