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
use Modules\Transfers\Internal\Support\EarliestLegFirst;

uses(RefreshDatabase::class);

// The reverse arm narrows to a small candidate set in SQL and then decrypts and
// matches in PHP, taking the first row that matches. Two legs of one transfer
// routinely book on the same day, which is the ordinary case, not an edge one.

// The tie used to be settled on the id, which is an autoincrement each device
// counts for itself: the same logical row is a different number on the peer, so
// the two devices picked different legs and both wrote the answer into
// pair_transaction_id, which travels as last-write-wins.

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

it('settles two candidates sharing a booked_at on a key the peer computes alike', function (): void {
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
    // unpaired: nothing off the statement separates them but the account they
    // sit on. The savings row is created first and so holds the LOWER id, while
    // the buffer row holds the first IBAN — the two rules answer differently.
    $lowerId = reverseOrderTx($user, $savings, $run, [
        'type' => 'transfer_out',
        'amount_minor' => -5000,
        'settled_amount_minor' => -5000,
        'counterparty_iban' => 'NL57ASNB0123456789',
    ]);

    $firstIban = reverseOrderTx($user, $second, $run, [
        'type' => 'transfer_out',
        'amount_minor' => -5000,
        'settled_amount_minor' => -5000,
        'counterparty_iban' => 'NL57ASNB0123456789',
    ]);

    expect($firstIban->id)->toBeGreaterThan($lowerId->id, 'the fixture no longer inverts id order against IBAN order');

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

    expect($paired->pair_transaction_id)->toBe($firstIban->id);
});

// The behavioural pin above proves which leg THIS build picks. What it cannot
// prove is that the clause names no column the peer numbers differently, since
// both devices run the same build against different numbers. That is a fact
// about the SQL, so it is asserted against the SQL.
it('orders the candidate read on columns both devices hold alike', function (): void {
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
    $agreed = [EarliestLegFirst::ACROSS_ACCOUNTS, EarliestLegFirst::ON_ONE_ACCOUNT];

    $countedPerDevice = [];
    foreach ($candidateReads as $sql) {
        $orderBy = trim(substr($sql, (int) strrpos($sql, 'order by') + strlen('order by')));
        $orderBy = trim((string) preg_replace('/\s+limit\s+\d+$/', '', $orderBy));

        if (! in_array($orderBy, $agreed, true)) {
            $countedPerDevice[] = $orderBy;
        }
    }

    expect($countedPerDevice)->toBe(
        [],
        "These orderings name a column the peer counts for itself:\n  ".implode("\n  ", $countedPerDevice)
    );
});
