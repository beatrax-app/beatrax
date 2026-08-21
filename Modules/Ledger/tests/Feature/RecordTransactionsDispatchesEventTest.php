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
        'username' => 'paypal-importer',
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
        'raw_file_path' => '/tmp/dispatch-test.csv',
        'sha256' => str_repeat('b', 64),
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
});

it('dispatches one TransactionImported event per newly-inserted row', function () use (&$recorded): void {
    $action = $this->app->make(RecordTransactions::class);
    $row = $this->canonical([
        'userId' => $this->user->id,
        'accountId' => $this->account->id,
        'importRunId' => $this->importRun->id,
    ]);

    $result = $action([$row], $this->user);

    expect($result->inserted)->toBe(1);
    expect(Transaction::count())->toBe(1);
    expect($recorded)->toHaveCount(1);

    /** @var Transaction $persistedRow */
    $persistedRow = Transaction::query()->firstOrFail();
    expect($recorded[0]->transaction->id)->toBe($persistedRow->id);
    expect($recorded[0]->user->id)->toBe($this->user->id);
});

it('does not dispatch when the row is a fingerprint duplicate (effected === 0)', function () use (&$recorded): void {
    $action = $this->app->make(RecordTransactions::class);
    $row = $this->canonical([
        'userId' => $this->user->id,
        'accountId' => $this->account->id,
        'importRunId' => $this->importRun->id,
    ]);

    $first = $action([$row], $this->user);
    $second = $action([$row], $this->user);

    expect($first->inserted)->toBe(1);
    expect($second->inserted)->toBe(0);
    expect($second->duplicates)->toBe(1);
    expect(Transaction::count())->toBe(1);
    expect($recorded)->toHaveCount(1);
});

it('keeps the event payload synchronous and in-transaction (no ShouldHandleEventsAfterCommit)', function (): void {
    expect(new ReflectionClass(TransactionImported::class)->getInterfaceNames())
        ->not->toContain('Illuminate\\Contracts\\Events\\ShouldHandleEventsAfterCommit')
        ->not->toContain('Illuminate\\Contracts\\Queue\\ShouldQueue');
});
