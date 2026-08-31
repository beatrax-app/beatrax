<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Modules\Core\Models\User;
use Modules\Import\Models\KnownCounterpartyIban;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\ImportRun;
use Modules\Ledger\Models\Transaction;
use Modules\Ledger\Public\Enums\AccountKind;
use Modules\Ledger\Public\Enums\TransactionType;
use Modules\Transfers\Public\Contracts\PairsTransferLegs;

// A transfer is money crossing from one of the reader's accounts to another,
// so its two legs never sit on the same account. The reverse arm says so in
// SQL (account_id != the firing leg's); the forward arm resolved a partner
// ACCOUNT and then searched it without ever asking whether it had resolved
// back to the account the firing leg is already on. Two ordinary rows on one
// account then link as a transfer and the dashboard nets both away.

beforeEach(function (): void {
    $this->user = User::query()->create([
        'username' => 'two-accounts',
        'password' => 'fixture-password',
        'period_start_day' => 1,
    ]);

    $this->run = ImportRun::query()->create([
        'user_id' => $this->user->id,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/two-accounts.csv',
        'sha256' => hash('sha256', 'two-accounts'),
        'uploaded_at' => CarbonImmutable::now(),
        'status' => 'previewed',
    ]);

    /** @var PairsTransferLegs $pairer */
    $pairer = $this->app->make(PairsTransferLegs::class);
    $this->pairer = $pairer;
});

/**
 * @param  array<string, mixed>  $overrides
 */
function twoAccountsTx(User $user, Account $account, ImportRun $run, array $overrides = []): Transaction
{
    static $row = 0;
    $row++;

    return Transaction::query()->create(array_merge([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'type' => TransactionType::TransferOut->value,
        'posted_at' => '2026-05-15',
        'booked_at' => '2026-05-15 12:00:00',
        'value_date' => '2026-05-15',
        'amount_minor' => -5000,
        'currency' => 'EUR',
        'settled_amount_minor' => -5000,
        'settled_currency' => 'EUR',
        'counterparty_iban' => null,
        'counterparty_name' => 'Two accounts '.$row,
        'counterparty_normalized' => 'two-accounts-'.$row,
        'normalization_version' => 3,
        'source_format' => 'asn-csv',
        'import_run_id' => $run->id,
        'source_row_index' => $row,
        'fingerprint' => str_pad((string) $row, 64, 't', STR_PAD_LEFT),
        'fingerprint_version' => 3,
    ], $overrides));
}

it('refuses a counter-leg sitting on the firing leg\'s own account when the IBAN matched it literally', function (): void {
    $bank = Account::query()->create([
        'user_id' => $this->user->id,
        'name' => 'ASN',
        'slug' => 'two-accounts-asn',
        'kind' => AccountKind::Bank->value,
        'iban' => 'NL57ASNB0123456789',
        'default_currency' => 'EUR',
    ]);

    $firing = twoAccountsTx($this->user, $bank, $this->run, [
        'type' => TransactionType::TransferOut->value,
        'amount_minor' => -5000,
        'settled_amount_minor' => -5000,
        'counterparty_iban' => 'NL57ASNB0123456789',
    ]);
    $neighbour = twoAccountsTx($this->user, $bank, $this->run, [
        'type' => TransactionType::TransferIn->value,
        'amount_minor' => 5000,
        'settled_amount_minor' => 5000,
    ]);

    expect($this->pairer->pairOne($firing, $this->user))
        ->toBeNull('paired with row '.$neighbour->id.', which sits on account '.$bank->id.' too');
    expect($neighbour->refresh()->pair_transaction_id)->toBeNull();
});

it('refuses a counter-leg sitting on the firing leg\'s own account when the alias bridge resolved back to it', function (): void {
    // Two cards of one kind is what makes this reachable: the alias resolves a
    // kind to the LOWEST-id account of it, so a row on that very card asks for
    // a partner on itself.
    $firstCard = Account::query()->create([
        'user_id' => $this->user->id,
        'name' => 'ICS card one',
        'slug' => 'two-accounts-ics-1',
        'kind' => AccountKind::IcsCard->value,
        'iban' => 'ICS-CARD-ONE',
        'default_currency' => 'EUR',
    ]);
    Account::query()->create([
        'user_id' => $this->user->id,
        'name' => 'ICS card two',
        'slug' => 'two-accounts-ics-2',
        'kind' => AccountKind::IcsCard->value,
        'iban' => 'ICS-CARD-TWO',
        'default_currency' => 'EUR',
    ]);
    KnownCounterpartyIban::withoutGlobalScopes()->create([
        'user_id' => $this->user->id,
        'real_iban' => 'NL08ABNA0526650664',
        'target_account_kind' => AccountKind::IcsCard->value,
    ]);

    $firing = twoAccountsTx($this->user, $firstCard, $this->run, [
        'type' => TransactionType::TransferIn->value,
        'amount_minor' => 12345,
        'settled_amount_minor' => 12345,
        'counterparty_iban' => 'NL08ABNA0526650664',
    ]);
    $neighbour = twoAccountsTx($this->user, $firstCard, $this->run, [
        'type' => TransactionType::TransferOut->value,
        'amount_minor' => -12345,
        'settled_amount_minor' => -12345,
    ]);

    expect($this->pairer->pairOne($firing, $this->user))
        ->toBeNull('paired with row '.$neighbour->id.', which sits on account '.$firstCard->id.' too');
    expect($neighbour->refresh()->pair_transaction_id)->toBeNull();
});

it('still pairs across two accounts once the same-account partner is refused', function (): void {
    $bank = Account::query()->create([
        'user_id' => $this->user->id,
        'name' => 'ASN',
        'slug' => 'two-accounts-asn-b',
        'kind' => AccountKind::Bank->value,
        'iban' => 'NL57ASNB0123456789',
        'default_currency' => 'EUR',
    ]);
    $card = Account::query()->create([
        'user_id' => $this->user->id,
        'name' => 'ICS card',
        'slug' => 'two-accounts-ics-b',
        'kind' => AccountKind::IcsCard->value,
        'iban' => 'ICS-CARD',
        'default_currency' => 'EUR',
    ]);

    $far = twoAccountsTx($this->user, $card, $this->run, [
        'type' => TransactionType::TransferIn->value,
        'amount_minor' => 7500,
        'settled_amount_minor' => 7500,
    ]);
    $firing = twoAccountsTx($this->user, $bank, $this->run, [
        'type' => TransactionType::TransferOut->value,
        'amount_minor' => -7500,
        'settled_amount_minor' => -7500,
        'counterparty_iban' => 'ICS-CARD',
    ]);

    expect($this->pairer->pairOne($firing, $this->user))->toBe($far->id);
});
