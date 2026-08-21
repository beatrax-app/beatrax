<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Modules\Core\Models\User;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\ImportRun;
use Modules\Ledger\Models\Transaction;
use Modules\Transfers\Public\Services\PairLookup;

// Every PairLookup query filters on user_id first, so a downstream consumer that
// hands in the wrong User object still cannot leak partner ids across users.

beforeEach(function (): void {
    $this->primaryUser = User::query()->create([
        'username' => 'pair-lookup-primary',
        'password' => 'fixture-password',
        'period_start_day' => 1,
    ]);

    $this->otherUser = User::query()->create([
        'username' => 'pair-lookup-other',
        'password' => 'fixture-password',
        'period_start_day' => 1,
    ]);

    $this->primaryAccount = Account::create([
        'user_id' => $this->primaryUser->id,
        'name' => 'ASN primary',
        'slug' => 'pl-asn-primary',
        'kind' => 'bank',
        'iban' => 'NL57ASNB0123456789',
        'default_currency' => 'EUR',
    ]);

    $this->otherAccount = Account::create([
        'user_id' => $this->otherUser->id,
        'name' => 'ASN other',
        'slug' => 'pl-asn-other',
        'kind' => 'bank',
        'iban' => 'NL91ASNB9876543210',
        'default_currency' => 'EUR',
    ]);

    $this->primaryRun = ImportRun::create([
        'user_id' => $this->primaryUser->id,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/pl-primary.csv',
        'sha256' => str_repeat('p', 64),
        'uploaded_at' => CarbonImmutable::now(),
        'status' => 'previewed',
    ]);

    $this->otherRun = ImportRun::create([
        'user_id' => $this->otherUser->id,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/pl-other.csv',
        'sha256' => str_repeat('o', 64),
        'uploaded_at' => CarbonImmutable::now(),
        'status' => 'previewed',
    ]);

    $this->lookup = $this->app->make(PairLookup::class);
});

/**
 * @param  array<string, mixed>  $overrides
 */
function pairLookupTx(
    User $user,
    Account $account,
    ImportRun $run,
    array $overrides = [],
): Transaction {
    static $rowIndex = 0;
    $rowIndex++;

    return Transaction::create(array_merge([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'type' => 'transfer_out',
        'posted_at' => '2026-05-15',
        'booked_at' => '2026-05-15 12:00:00',
        'value_date' => '2026-05-15',
        // Varied per row so fixture rows never collide on the (user, account,
        // posted_at, booked_at, amount, currency, counterparty) uniqueness tuple.
        'amount_minor' => -1500 - $rowIndex,
        'currency' => 'EUR',
        'settled_amount_minor' => -1500 - $rowIndex,
        'settled_currency' => 'EUR',
        'counterparty_iban' => null,
        'counterparty_name' => 'Partner '.$rowIndex,
        'counterparty_normalized' => 'partner-'.$rowIndex,
        'normalization_version' => 1,
        'source_format' => 'asn-csv',
        'import_run_id' => $run->id,
        'source_row_index' => $rowIndex,
        'fingerprint' => str_pad((string) $rowIndex, 64, 'l', STR_PAD_LEFT),
        'fingerprint_version' => 3,
    ], $overrides));
}

it('returns true for isPaired() when pair_transaction_id is non-null and the user matches', function (): void {
    $a = pairLookupTx($this->primaryUser, $this->primaryAccount, $this->primaryRun);
    $b = pairLookupTx($this->primaryUser, $this->primaryAccount, $this->primaryRun);
    $a->pair_transaction_id = $b->id;
    $a->save();

    expect($this->lookup->isPaired($a->id, $this->primaryUser))->toBeTrue();
});

it('returns false for isPaired() when pair_transaction_id is null', function (): void {
    $a = pairLookupTx($this->primaryUser, $this->primaryAccount, $this->primaryRun);

    expect($this->lookup->isPaired($a->id, $this->primaryUser))->toBeFalse();
});

it('returns the partner integer id from partnerId() when the row is paired', function (): void {
    $a = pairLookupTx($this->primaryUser, $this->primaryAccount, $this->primaryRun);
    $b = pairLookupTx($this->primaryUser, $this->primaryAccount, $this->primaryRun);
    $a->pair_transaction_id = $b->id;
    $a->save();

    expect($this->lookup->partnerId($a->id, $this->primaryUser))->toBe($b->id);
});

it('returns null from partnerId() when the row is unpaired', function (): void {
    $a = pairLookupTx($this->primaryUser, $this->primaryAccount, $this->primaryRun);

    expect($this->lookup->partnerId($a->id, $this->primaryUser))->toBeNull();
});

it('isolates cross-user access — partnerId returns null when the txId belongs to another user', function (): void {
    $primaryA = pairLookupTx($this->primaryUser, $this->primaryAccount, $this->primaryRun);
    $primaryB = pairLookupTx($this->primaryUser, $this->primaryAccount, $this->primaryRun);
    $primaryA->pair_transaction_id = $primaryB->id;
    $primaryA->save();

    expect($this->lookup->isPaired($primaryA->id, $this->otherUser))->toBeFalse();
    expect($this->lookup->partnerId($primaryA->id, $this->otherUser))->toBeNull();
});

it('isolates cross-user access — isPaired returns false even when the other user also has a paired row of their own', function (): void {
    $primaryA = pairLookupTx($this->primaryUser, $this->primaryAccount, $this->primaryRun);
    $primaryB = pairLookupTx($this->primaryUser, $this->primaryAccount, $this->primaryRun);
    $primaryA->pair_transaction_id = $primaryB->id;
    $primaryA->save();

    $otherA = pairLookupTx($this->otherUser, $this->otherAccount, $this->otherRun);
    $otherB = pairLookupTx($this->otherUser, $this->otherAccount, $this->otherRun);
    $otherA->pair_transaction_id = $otherB->id;
    $otherA->save();

    expect($this->lookup->isPaired($primaryA->id, $this->otherUser))->toBeFalse();
    expect($this->lookup->partnerId($primaryA->id, $this->otherUser))->toBeNull();

    expect($this->lookup->isPaired($otherA->id, $this->otherUser))->toBeTrue();
    expect($this->lookup->partnerId($otherA->id, $this->otherUser))->toBe($otherB->id);
});
