<?php

declare(strict_types=1);

use App\Models\User;
use Carbon\CarbonImmutable;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\ImportRun;
use Modules\Ledger\Models\Transaction;
use Modules\Ledger\Public\Actions\RecordTransactions;
use Modules\Ledger\Public\Contracts\RecordsTransactions;
use Modules\Ledger\Public\Dto\RecordResult;
use Modules\Ledger\Public\Services\FingerprintComposer;

beforeEach(function (): void {
    $this->user = User::query()->create([
        'username' => 'recorder',
        'password' => 'fixture-password',
        'period_start_day' => 1,
    ]);
    $this->account = Account::create([
        'user_id' => $this->user->id,
        'name' => 'ASN', 'slug' => 'asn', 'kind' => 'bank',
        'iban' => 'NL57ASNB0123456789', 'default_currency' => 'EUR',
    ]);
    $this->importRun = ImportRun::create([
        'user_id' => $this->user->id,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/x.csv',
        'sha256' => str_repeat('a', 64),
        'uploaded_at' => CarbonImmutable::now(),
        'status' => 'previewed',
    ]);
});

it('inserts new rows and counts them', function (): void {
    $action = $this->app->make(RecordTransactions::class);
    $result = $action([$this->canonical([
        'userId' => $this->user->id,
        'accountId' => $this->account->id,
        'importRunId' => $this->importRun->id,
    ])], $this->user);

    expect($result)->toBeInstanceOf(RecordResult::class);
    expect($result->inserted)->toBe(1);
    expect($result->duplicates)->toBe(0);
    expect(Transaction::count())->toBe(1);
});

it('treats a re-insertion of the same canonical as a duplicate', function (): void {
    $action = $this->app->make(RecordTransactions::class);
    $row = $this->canonical([
        'userId' => $this->user->id,
        'accountId' => $this->account->id,
        'importRunId' => $this->importRun->id,
    ]);

    $first = $action([$row], $this->user);
    $second = $action([$row], $this->user);

    expect($first->inserted)->toBe(1);
    expect($first->duplicates)->toBe(0);
    expect($second->inserted)->toBe(0);
    expect($second->duplicates)->toBe(1);
    expect(Transaction::count())->toBe(1);
});

it('rolls back the whole batch when one row has an invalid type', function (): void {
    $action = $this->app->make(RecordTransactions::class);

    $batch = [
        $this->canonical([
            'userId' => $this->user->id,
            'accountId' => $this->account->id,
            'importRunId' => $this->importRun->id,
            'amountMinor' => -100,
            'sourceRef' => 'ASN-REF-A',
        ]),
        $this->canonical([
            'userId' => $this->user->id,
            'accountId' => $this->account->id,
            'importRunId' => $this->importRun->id,
            'type' => 'BOGUS',
            'amountMinor' => -200,
            'sourceRef' => 'ASN-REF-B',
        ]),
        $this->canonical([
            'userId' => $this->user->id,
            'accountId' => $this->account->id,
            'importRunId' => $this->importRun->id,
            'amountMinor' => -300,
            'sourceRef' => 'ASN-REF-C',
        ]),
    ];

    expect(fn () => $action($batch, $this->user))->toThrow(InvalidArgumentException::class);
    expect(Transaction::count())->toBe(0);
});

it('persists the SHA-256 fingerprint stamped with the current normalization version', function (): void {
    $action = $this->app->make(RecordTransactions::class);
    $action([$this->canonical([
        'userId' => $this->user->id,
        'accountId' => $this->account->id,
        'importRunId' => $this->importRun->id,
    ])], $this->user);

    $tx = Transaction::query()->first();
    expect($tx->fingerprint)->toHaveLength(64);
    expect($tx->fingerprint)->toMatch('/^[0-9a-f]{64}$/');
    expect($tx->fingerprint_version)->toBe(FingerprintComposer::NORMALIZATION_VERSION);
});

it('binds the RecordsTransactions contract to RecordTransactions', function (): void {
    $resolved = $this->app->make(RecordsTransactions::class);

    expect($resolved)->toBeInstanceOf(RecordTransactions::class);
});

it('refuses a batch that contains a row with a null user_id', function (): void {
    $action = $this->app->make(RecordTransactions::class);

    $batch = [
        $this->canonical([
            'userId' => null,
            'accountId' => $this->account->id,
            'importRunId' => $this->importRun->id,
        ]),
    ];

    expect(fn () => $action($batch, $this->user))->toThrow(
        InvalidArgumentException::class,
        'CanonicalTransaction.userId must not be null',
    );
    expect(Transaction::count())->toBe(0);
});
