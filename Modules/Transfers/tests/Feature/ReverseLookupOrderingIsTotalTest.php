<?php

declare(strict_types=1);

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Core\Models\User;
use Modules\Import\Public\Events\TransactionImported;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\ImportRun;
use Modules\Ledger\Models\Transaction;

uses(RefreshDatabase::class);

// The reverse arm narrows to a small candidate set in SQL and then decrypts and
// matches in PHP, taking the first row that matches. It ordered by booked_at
// alone — and two legs of one transfer routinely book on the same day, which is
// the ordinary case, not an edge one. With the key equal, which row arrives
// first is the engine's to choose: it can differ between runs, between SQLite
// builds, and after a vacuum. Whichever leg it picks then gets written into
// pair_transaction_id, so the arbitrary answer is the one that persists.

function reverseOrderUser(): User
{
    return User::query()->create([
        'username' => 'reverse-order',
        'password' => 'fixture-password',
        'period_start_day' => 1,
    ]);
}

function reverseOrderTx(User $user, Account $account, ImportRun $run, array $overrides): Transaction
{
    static $row = 0;
    $row++;

    return Transaction::create(array_merge([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'type' => 'transfer_out',
        'posted_at' => '2026-04-15',
        'booked_at' => '2026-04-15 12:00:00',
        'value_date' => '2026-04-15',
        'amount_minor' => -5000,
        'currency' => 'EUR',
        'settled_amount_minor' => -5000,
        'settled_currency' => 'EUR',
        'counterparty_iban' => null,
        'counterparty_name' => 'Partner',
        'counterparty_normalized' => 'partner',
        'normalization_version' => 1,
        'source_format' => 'asn-csv',
        'import_run_id' => $run->id,
        'source_row_index' => $row,
        'fingerprint' => str_pad((string) $row, 64, 'r', STR_PAD_LEFT),
        'fingerprint_version' => 3,
    ], $overrides));
}

it('settles two candidates sharing a booked_at on the lower id', function (): void {
    $user = reverseOrderUser();

    $bank = Account::create([
        'user_id' => $user->id,
        'name' => 'ASN bank',
        'slug' => 'reverse-order-bank',
        'kind' => 'bank',
        'iban' => 'NL57ASNB0123456789',
        'default_currency' => 'EUR',
    ]);

    // Two accounts, because the fingerprint tuple is unique per account — and
    // two accounts paying the same amount on the same day is the ordinary
    // shape of this tie, not a contrived one.
    $savings = Account::create([
        'user_id' => $user->id,
        'name' => 'ASN savings',
        'slug' => 'reverse-order-savings',
        'kind' => 'bank',
        'iban' => 'NL91ASNB0417164300',
        'default_currency' => 'EUR',
    ]);

    $second = Account::create([
        'user_id' => $user->id,
        'name' => 'ASN buffer',
        'slug' => 'reverse-order-buffer',
        'kind' => 'bank',
        'iban' => 'NL20ASNB0123456790',
        'default_currency' => 'EUR',
    ]);

    $run = ImportRun::create([
        'user_id' => $user->id,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/reverse-order.csv',
        'sha256' => hash('sha256', 'reverse-order'),
        'uploaded_at' => now(),
        'status' => 'previewed',
    ]);

    // Both name the bank account, both book at the same instant, both are
    // unpaired: nothing but the ordering separates them.
    $first = reverseOrderTx($user, $savings, $run, [
        'type' => 'transfer_out',
        'amount_minor' => -5000,
        'settled_amount_minor' => -5000,
        'counterparty_iban' => 'NL57ASNB0123456789',
    ]);

    reverseOrderTx($user, $second, $run, [
        'type' => 'transfer_out',
        'amount_minor' => -5000,
        'settled_amount_minor' => -5000,
        'counterparty_iban' => 'NL57ASNB0123456789',
    ]);

    // The incoming leg carries no counterparty IBAN, which is what sends it
    // down the reverse arm rather than the forward one.
    $incoming = reverseOrderTx($user, $bank, $run, [
        'type' => 'transfer_in',
        'amount_minor' => 5000,
        'settled_amount_minor' => 5000,
        'counterparty_iban' => '',
    ]);

    app(Dispatcher::class)->dispatch(new TransactionImported($incoming, $user));

    /** @var Transaction $paired */
    $paired = Transaction::query()->findOrFail($incoming->id);

    expect($paired->pair_transaction_id)->toBe($first->id);
});

// The behavioural pin above passes on this build today — which is the whole
// problem. SQLite is free to answer a tie either way, so a green run proves
// only what it did this time. What can be asserted is that the query leaves it
// no choice: the candidate read has to end on a column no two rows share.
it('orders the candidate read on something no two rows can tie on', function (): void {
    $user = reverseOrderUser();

    $bank = Account::create([
        'user_id' => $user->id,
        'name' => 'ASN bank',
        'slug' => 'reverse-total-bank',
        'kind' => 'bank',
        'iban' => 'NL57ASNB0123456789',
        'default_currency' => 'EUR',
    ]);

    $run = ImportRun::create([
        'user_id' => $user->id,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/reverse-total.csv',
        'sha256' => hash('sha256', 'reverse-total'),
        'uploaded_at' => now(),
        'status' => 'previewed',
    ]);

    $incoming = reverseOrderTx($user, $bank, $run, [
        'type' => 'transfer_in',
        'amount_minor' => 5000,
        'settled_amount_minor' => 5000,
        'counterparty_iban' => '',
    ]);

    $candidateReads = [];
    DB::listen(static function (QueryExecuted $query) use (&$candidateReads): void {
        if (str_contains($query->sql, 'pair_transaction_id" is null') && str_contains($query->sql, 'order by')) {
            $candidateReads[] = $query->sql;
        }
    });

    app(Dispatcher::class)->dispatch(new TransactionImported($incoming, $user));

    expect($candidateReads)->not->toBeEmpty('the reverse arm never ran its candidate read');

    // toContain() is variadic over NEEDLES, so a failure message passed beside
    // the needle silently becomes a second one. The offending orderings are
    // collected instead, which also names every one of them at once.
    $leavingATie = [];
    foreach ($candidateReads as $sql) {
        $orderBy = substr($sql, (int) strrpos($sql, 'order by'));

        if (! str_contains($orderBy, '"id"')) {
            $leavingATie[] = $orderBy;
        }
    }

    expect($leavingATie)->toBe(
        [],
        "These orderings leave a tie to SQLite:\n  ".implode("\n  ", $leavingATie)
    );
});
