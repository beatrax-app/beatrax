<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Database\Events\TransactionCommitted;
use Modules\Core\Models\User;
use Modules\Import\Public\Events\TransactionImported;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\ImportRun;
use Modules\Ledger\Models\Transaction;

// A pair is one fact written to two rows. Written as two loose statements, a
// crash between them leaves a.pair -> b while b.pair is null, and neither
// sweep can heal it: pairOrphansForUser finds b, and counterLegOnAccount's
// unpairedOnly narrowing excludes a. So b orphans permanently.

function unprotectedPairUser(): User
{
    return User::query()->create([
        'username' => 'pair-atomic-'.bin2hex(random_bytes(4)),
        'password' => 'fixture-password',
        'period_start_day' => 1,
    ]);
}

function unprotectedPairTx(User $user, Account $account, ImportRun $run, array $overrides): Transaction
{
    static $rowIndex = 5000;
    $rowIndex++;

    return Transaction::create(array_merge([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'type' => 'transfer_out',
        'posted_at' => '2026-05-15',
        'booked_at' => '2026-05-15 12:00:00',
        'value_date' => '2026-05-15',
        'amount_minor' => -12345,
        'currency' => 'EUR',
        'settled_amount_minor' => -12345,
        'settled_currency' => 'EUR',
        'counterparty_iban' => null,
        'counterparty_name' => 'Partner',
        'counterparty_normalized' => 'partner',
        'normalization_version' => 1,
        'source_format' => 'asn-csv',
        'import_run_id' => $run->id,
        'source_row_index' => $rowIndex,
        'fingerprint' => str_pad((string) $rowIndex, 64, 'q', STR_PAD_LEFT),
        'fingerprint_version' => 3,
    ], $overrides));
}

it('writes both sides of the pair inside one transaction', function (): void {
    CarbonImmutable::setTestNow('2026-05-20 09:00:00');
    $user = unprotectedPairUser();

    $bank = Account::create([
        'user_id' => $user->id, 'name' => 'Bank', 'slug' => 'atomic-bank', 'kind' => 'bank',
        'iban' => 'NL57ASNB0123456789', 'default_currency' => 'EUR',
    ]);
    $card = Account::create([
        'user_id' => $user->id, 'name' => 'Card', 'slug' => 'atomic-card', 'kind' => 'ics_card',
        'iban' => 'ICS-CARD', 'default_currency' => 'EUR',
    ]);
    $run = ImportRun::create([
        'user_id' => $user->id, 'source_format' => 'asn-csv', 'raw_file_path' => '/tmp/atomic.csv',
        'sha256' => str_repeat('q', 64), 'uploaded_at' => CarbonImmutable::now(), 'status' => 'previewed',
    ]);

    $out = unprotectedPairTx($user, $bank, $run, [
        'type' => 'transfer_out', 'amount_minor' => -12345,
        'settled_amount_minor' => -12345, 'counterparty_iban' => 'ICS-CARD',
    ]);
    $in = unprotectedPairTx($user, $card, $run, [
        'type' => 'transfer_in', 'amount_minor' => 12345,
        'settled_amount_minor' => 12345, 'counterparty_iban' => 'NL57ASNB0123456789',
    ]);

    /** @var Dispatcher $events */
    $events = $this->app->make(Dispatcher::class);

    $log = [];
    $events->listen(TransactionBeginning::class, function () use (&$log): void {
        $log[] = 'begin';
    });
    $events->listen(TransactionCommitted::class, function () use (&$log): void {
        $log[] = 'commit';
    });

    $events->listen(QueryExecuted::class, function (QueryExecuted $query) use (&$log): void {
        if (str_contains($query->sql, 'update "transactions"') && str_contains($query->sql, 'pair_transaction_id')) {
            $log[] = 'pair-write';
        }
    });

    $events->dispatch(new TransactionImported($out, $user));

    expect(Transaction::query()->findOrFail($out->id)->pair_transaction_id)->toBe($in->id);
    expect(Transaction::query()->findOrFail($in->id)->pair_transaction_id)->toBe($out->id);

    $writes = array_keys($log, 'pair-write', true);
    expect($writes)->toHaveCount(2);

    foreach ($writes as $write) {
        $before = array_slice($log, 0, $write);
        $depth = count(array_keys($before, 'begin', true)) - count(array_keys($before, 'commit', true));

        expect($depth)->toBeGreaterThan(0);
    }
});
