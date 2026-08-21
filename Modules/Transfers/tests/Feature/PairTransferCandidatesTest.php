<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\Dispatcher;
use Modules\Core\Models\User;
use Modules\Import\Public\Events\TransactionImported;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\ImportRun;
use Modules\Ledger\Models\Transaction;
use Modules\Transfers\Internal\Listeners\PairTransferCandidates;

beforeEach(function (): void {
    $this->primaryUser = User::query()->create([
        'username' => 'pair-primary',
        'password' => 'fixture-password',
        'period_start_day' => 1,
    ]);

    $this->asnAccount = Account::create([
        'user_id' => $this->primaryUser->id,
        'name' => 'ASN',
        'slug' => 'pair-asn',
        'kind' => 'bank',
        'iban' => 'NL57ASNB0123456789',
        'default_currency' => 'EUR',
    ]);

    $this->icsAccount = Account::create([
        'user_id' => $this->primaryUser->id,
        'name' => 'ICS card',
        'slug' => 'pair-ics',
        'kind' => 'ics_card',
        'iban' => 'ICS-CARD',
        'default_currency' => 'EUR',
    ]);

    $this->importRun = ImportRun::create([
        'user_id' => $this->primaryUser->id,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/pair-fixture.csv',
        'sha256' => str_repeat('a', 64),
        'uploaded_at' => CarbonImmutable::now(),
        'status' => 'previewed',
    ]);

    /** @var Dispatcher $events */
    $events = $this->app->make(Dispatcher::class);
    $this->events = $events;
});

/**
 * @param  array<string, mixed>  $overrides
 */
function pairTx(
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
        'fingerprint' => str_pad((string) $rowIndex, 64, 'p', STR_PAD_LEFT),
        'fingerprint_version' => 3,
    ], $overrides));
}

it('pairs an ASN→ICS settlement when both legs are present', function (): void {
    $asnLeg = pairTx($this->primaryUser, $this->asnAccount, $this->importRun, [
        'type' => 'transfer_out',
        'amount_minor' => -12345,
        'settled_amount_minor' => -12345,
        'counterparty_iban' => 'ICS-CARD',
    ]);
    $icsLeg = pairTx($this->primaryUser, $this->icsAccount, $this->importRun, [
        'type' => 'transfer_in',
        'amount_minor' => 12345,
        'settled_amount_minor' => 12345,
        'counterparty_iban' => 'NL57ASNB0123456789',
    ]);

    $this->events->dispatch(new TransactionImported($asnLeg, $this->primaryUser));
    $this->events->dispatch(new TransactionImported($icsLeg, $this->primaryUser));

    /** @var Transaction $asnReloaded */
    $asnReloaded = Transaction::query()->findOrFail($asnLeg->id);
    /** @var Transaction $icsReloaded */
    $icsReloaded = Transaction::query()->findOrFail($icsLeg->id);
    expect($asnReloaded->pair_transaction_id)->toBe($icsLeg->id);
    expect($icsReloaded->pair_transaction_id)->toBe($asnLeg->id);
});

it('leaves a half-pair unpaired when only one leg is in the ledger', function (): void {
    $asnLeg = pairTx($this->primaryUser, $this->asnAccount, $this->importRun, [
        'type' => 'transfer_out',
        'amount_minor' => -500,
        'settled_amount_minor' => -500,
        'counterparty_iban' => 'ICS-CARD',
    ]);

    $this->events->dispatch(new TransactionImported($asnLeg, $this->primaryUser));

    /** @var Transaction $asnReloaded */
    $asnReloaded = Transaction::query()->findOrFail($asnLeg->id);
    expect($asnReloaded->pair_transaction_id)->toBeNull();
    expect($asnReloaded->type)->toBe('transfer_out');
});

it('pairs partner rows that are exactly 3 calendar days away regardless of time-of-day (symmetric window)', function (): void {
    // Noon on May 15 against 23:59 on May 18: three calendar days apart, but a
    // naive subDays/addDays off a 12:00 booked_at puts it 11:59 past the bound.
    $asnLeg = pairTx($this->primaryUser, $this->asnAccount, $this->importRun, [
        'type' => 'transfer_out',
        'amount_minor' => -500,
        'settled_amount_minor' => -500,
        'counterparty_iban' => 'ICS-CARD',
        'booked_at' => '2026-05-15 12:00:00',
    ]);
    $icsLeg = pairTx($this->primaryUser, $this->icsAccount, $this->importRun, [
        'type' => 'transfer_in',
        'amount_minor' => 500,
        'settled_amount_minor' => 500,
        'counterparty_iban' => 'NL57ASNB0123456789',
        'booked_at' => '2026-05-18 23:59:00',
    ]);

    $this->events->dispatch(new TransactionImported($asnLeg, $this->primaryUser));
    $this->events->dispatch(new TransactionImported($icsLeg, $this->primaryUser));

    /** @var Transaction $asnReloaded */
    $asnReloaded = Transaction::query()->findOrFail($asnLeg->id);
    /** @var Transaction $icsReloaded */
    $icsReloaded = Transaction::query()->findOrFail($icsLeg->id);
    expect($asnReloaded->pair_transaction_id)->toBe($icsLeg->id);
    expect($icsReloaded->pair_transaction_id)->toBe($asnLeg->id);
});

it('does not pair partners that are 4+ calendar days away', function (): void {
    $asnLeg = pairTx($this->primaryUser, $this->asnAccount, $this->importRun, [
        'type' => 'transfer_out',
        'amount_minor' => -500,
        'settled_amount_minor' => -500,
        'counterparty_iban' => 'ICS-CARD',
        'booked_at' => '2026-05-15 12:00:00',
    ]);
    $icsLeg = pairTx($this->primaryUser, $this->icsAccount, $this->importRun, [
        'type' => 'transfer_in',
        'amount_minor' => 500,
        'settled_amount_minor' => 500,
        'counterparty_iban' => 'NL57ASNB0123456789',
        'booked_at' => '2026-05-19 00:00:00',
    ]);

    $this->events->dispatch(new TransactionImported($asnLeg, $this->primaryUser));
    $this->events->dispatch(new TransactionImported($icsLeg, $this->primaryUser));

    /** @var Transaction $asnReloaded */
    $asnReloaded = Transaction::query()->findOrFail($asnLeg->id);
    /** @var Transaction $icsReloaded */
    $icsReloaded = Transaction::query()->findOrFail($icsLeg->id);
    expect($asnReloaded->pair_transaction_id)->toBeNull();
    expect($icsReloaded->pair_transaction_id)->toBeNull();
});

it('closes the half-pair when the partner arrives in a later import', function (): void {
    $asnLeg = pairTx($this->primaryUser, $this->asnAccount, $this->importRun, [
        'type' => 'transfer_out',
        'amount_minor' => -7777,
        'settled_amount_minor' => -7777,
        'counterparty_iban' => 'ICS-CARD',
    ]);
    $this->events->dispatch(new TransactionImported($asnLeg, $this->primaryUser));

    $secondRun = ImportRun::create([
        'user_id' => $this->primaryUser->id,
        'source_format' => 'ics-pdf',
        'raw_file_path' => '/tmp/second.pdf',
        'sha256' => str_repeat('b', 64),
        'uploaded_at' => CarbonImmutable::now(),
        'status' => 'previewed',
    ]);
    $icsLeg = pairTx($this->primaryUser, $this->icsAccount, $secondRun, [
        'type' => 'transfer_in',
        'amount_minor' => 7777,
        'settled_amount_minor' => 7777,
        'counterparty_iban' => 'NL57ASNB0123456789',
        'booked_at' => '2026-05-16 12:00:00',
    ]);
    $this->events->dispatch(new TransactionImported($icsLeg, $this->primaryUser));

    /** @var Transaction $asnReloaded */
    $asnReloaded = Transaction::query()->findOrFail($asnLeg->id);
    /** @var Transaction $icsReloaded */
    $icsReloaded = Transaction::query()->findOrFail($icsLeg->id);
    expect($asnReloaded->pair_transaction_id)->toBe($icsLeg->id);
    expect($icsReloaded->pair_transaction_id)->toBe($asnLeg->id);
});

it('does not re-type or re-pair rows that are already paired (idempotency)', function (): void {
    $asnLeg = pairTx($this->primaryUser, $this->asnAccount, $this->importRun, [
        'type' => 'transfer_out',
        'amount_minor' => -1111,
        'settled_amount_minor' => -1111,
        'counterparty_iban' => 'ICS-CARD',
    ]);
    $icsLeg = pairTx($this->primaryUser, $this->icsAccount, $this->importRun, [
        'type' => 'transfer_in',
        'amount_minor' => 1111,
        'settled_amount_minor' => 1111,
        'counterparty_iban' => 'NL57ASNB0123456789',
    ]);

    $this->events->dispatch(new TransactionImported($asnLeg, $this->primaryUser));
    $this->events->dispatch(new TransactionImported($icsLeg, $this->primaryUser));

    $asnReloaded = Transaction::query()->findOrFail($asnLeg->id);
    $this->events->dispatch(new TransactionImported($asnReloaded, $this->primaryUser));

    /** @var Transaction $asnFinal */
    $asnFinal = Transaction::query()->findOrFail($asnLeg->id);
    /** @var Transaction $icsFinal */
    $icsFinal = Transaction::query()->findOrFail($icsLeg->id);
    expect($asnFinal->pair_transaction_id)->toBe($icsLeg->id);
    expect($icsFinal->pair_transaction_id)->toBe($asnLeg->id);
    expect($asnFinal->type)->toBe('transfer_out');
    expect($icsFinal->type)->toBe('transfer_in');
});

it('does not self-pair (the id-not-equal filter blocks degenerate matches)', function (): void {
    // A contrived transfer_out whose own account IBAN is also its counterparty.
    $self = pairTx($this->primaryUser, $this->asnAccount, $this->importRun, [
        'type' => 'transfer_out',
        'amount_minor' => -50,
        'settled_amount_minor' => -50,
        'counterparty_iban' => 'ICS-CARD',
    ]);

    $this->events->dispatch(new TransactionImported($self, $this->primaryUser));

    /** @var Transaction $reloaded */
    $reloaded = Transaction::query()->findOrFail($self->id);
    expect($reloaded->pair_transaction_id)->toBeNull();
});

it('never pairs across users (cross-user safety invariant)', function (): void {
    $userB = User::query()->create([
        'username' => 'pair-other',
        'password' => 'fixture-password',
        'period_start_day' => 1,
    ]);
    $bAsn = Account::create([
        'user_id' => $userB->id,
        'name' => 'B-ASN',
        'slug' => 'b-asn',
        'kind' => 'bank',
        'iban' => 'NL57ASNB0123456789', // matches User-A's ASN IBAN but separate row
        'default_currency' => 'EUR',
    ]);
    $bIcs = Account::create([
        'user_id' => $userB->id,
        'name' => 'B-ICS',
        'slug' => 'b-ics',
        'kind' => 'ics_card',
        'iban' => 'ICS-CARD',
        'default_currency' => 'EUR',
    ]);
    $bRun = ImportRun::create([
        'user_id' => $userB->id,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/userb.csv',
        'sha256' => str_repeat('c', 64),
        'uploaded_at' => CarbonImmutable::now(),
        'status' => 'previewed',
    ]);
    // User B already has a transfer_in waiting that would pair with user A's
    // transfer_out if the queries were not user-scoped.
    $bIcsLeg = pairTx($userB, $bIcs, $bRun, [
        'type' => 'transfer_in',
        'amount_minor' => 9999,
        'settled_amount_minor' => 9999,
        'counterparty_iban' => 'NL57ASNB0123456789',
    ]);

    $aAsnLeg = pairTx($this->primaryUser, $this->asnAccount, $this->importRun, [
        'type' => 'transfer_out',
        'amount_minor' => -9999,
        'settled_amount_minor' => -9999,
        'counterparty_iban' => 'ICS-CARD',
    ]);

    $this->events->dispatch(new TransactionImported($aAsnLeg, $this->primaryUser));

    /** @var Transaction $aReloaded */
    $aReloaded = Transaction::query()->findOrFail($aAsnLeg->id);
    /** @var Transaction $bReloaded */
    $bReloaded = Transaction::query()->findOrFail($bIcsLeg->id);
    expect($aReloaded->pair_transaction_id)->toBeNull();
    expect($bReloaded->pair_transaction_id)->toBeNull();
});

it('writes both sides symmetrically and the Eloquent pair() relation walks both directions', function (): void {
    $asnLeg = pairTx($this->primaryUser, $this->asnAccount, $this->importRun, [
        'type' => 'transfer_out',
        'amount_minor' => -3333,
        'settled_amount_minor' => -3333,
        'counterparty_iban' => 'ICS-CARD',
    ]);
    $icsLeg = pairTx($this->primaryUser, $this->icsAccount, $this->importRun, [
        'type' => 'transfer_in',
        'amount_minor' => 3333,
        'settled_amount_minor' => 3333,
        'counterparty_iban' => 'NL57ASNB0123456789',
    ]);

    $this->events->dispatch(new TransactionImported($asnLeg, $this->primaryUser));
    $this->events->dispatch(new TransactionImported($icsLeg, $this->primaryUser));

    /** @var Transaction $asn */
    $asn = Transaction::query()->findOrFail($asnLeg->id);
    /** @var Transaction $ics */
    $ics = Transaction::query()->findOrFail($icsLeg->id);
    expect($asn->pair?->id)->toBe($ics->id);
    expect($ics->pair?->id)->toBe($asn->id);
});

it('refuses to pair when the event payload\'s user does not match the transaction\'s user_id (cross-user safety)', function (): void {
    $userB = User::query()->create([
        'username' => 'pair-tamper',
        'password' => 'fixture-password',
        'period_start_day' => 1,
    ]);

    $asnLeg = pairTx($this->primaryUser, $this->asnAccount, $this->importRun, [
        'type' => 'transfer_out',
        'amount_minor' => -42,
        'settled_amount_minor' => -42,
        'counterparty_iban' => 'ICS-CARD',
    ]);

    // The listener asserts event.transaction.user_id === event.user.id, so a
    // tampered payload has to throw.
    expect(fn () => $this->events->dispatch(new TransactionImported($asnLeg, $userB)))
        ->toThrow(RuntimeException::class);
});

it('registers the listener via TransfersServiceProvider on boot', function (): void {
    /** @var Dispatcher $events */
    $events = $this->app->make(Dispatcher::class);
    expect($events->hasListeners(TransactionImported::class))->toBeTrue();

    /** @var PairTransferCandidates $listener */
    $listener = $this->app->make(PairTransferCandidates::class);
    expect($listener)->toBeInstanceOf(PairTransferCandidates::class);
});
