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

    // The container-bound Dispatcher, so this listener sits on the same
    // instance RecordTransactions has injected.
    /** @var Dispatcher $events */
    $events = $this->app->make(Dispatcher::class);
    $events->listen(
        TransactionImported::class,
        static function (TransactionImported $event) use (&$recorded): void {
            $recorded[] = $event;
        },
    );

    // Every row varies the fingerprint tuple, so each is a genuine insert and a
    // re-run's duplicates line up one-for-one. A $this-bound closure because
    // canonical() is protected.
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

    expect(Transaction::query()->where('user_id', $this->user->id)->count())->toBe(1200);

    expect($recorded)->toHaveCount(1200);
});

it('ignores duplicates straddling a chunk boundary on re-run with no events', function () use (&$recorded): void {
    $action = $this->app->make(RecordTransactions::class);

    $rows = ($this->distinctBatch)(1200);

    $first = $action($rows, $this->user);

    expect($first->inserted)->toBe(1200);
    expect($first->duplicates)->toBe(0);
    expect($recorded)->toHaveCount(1200);

    $recorded = [];

    // The duplicates straddle both 500-row chunk edges, so this proves
    // insertOrIgnore dedups across chunk commits, not only within one.
    $second = $action($rows, $this->user);

    expect($second->inserted)->toBe(0);
    expect($second->duplicates)->toBe(1200);

    expect(Transaction::query()->where('user_id', $this->user->id)->count())->toBe(1200);
    expect($recorded)->toHaveCount(0);
});
