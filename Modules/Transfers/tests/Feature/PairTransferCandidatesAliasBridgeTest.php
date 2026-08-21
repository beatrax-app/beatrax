<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\Import\Database\Seeders\DefaultKnownCounterpartyIbansSeeder;
use Modules\Import\Internal\Services\KnownCounterpartyIbanResolver;
use Modules\Import\Public\Contracts\ResolvesKnownCounterpartyIban;
use Modules\Import\Public\Events\TransactionImported;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\ImportRun;
use Modules\Ledger\Models\Transaction;

uses(RefreshDatabase::class);

// PairTransferCandidatesTest covers the literal own-IBAN arm; this covers the
// fallback, where a real institution IBAN maps onto a synthetic-IBAN account.

beforeEach(function (): void {
    $this->user = User::query()->create([
        'username' => 'pair-alias',
        'password' => 'fixture-password',
        'period_start_day' => 1,
    ]);

    $this->bank = Account::create([
        'user_id' => $this->user->id,
        'name' => 'ASN bank',
        'slug' => 'pair-alias-bank',
        'kind' => 'bank',
        'iban' => 'NL57ASNB0123456789',
        'default_currency' => 'EUR',
    ]);

    $this->paypal = Account::create([
        'user_id' => $this->user->id,
        'name' => 'PayPal',
        'slug' => 'pair-alias-paypal',
        'kind' => 'paypal',
        'iban' => 'PAYPAL',
        'default_currency' => 'EUR',
    ]);

    $this->icsCard = Account::create([
        'user_id' => $this->user->id,
        'name' => 'ICS card',
        'slug' => 'pair-alias-ics',
        'kind' => 'ics_card',
        'iban' => 'ICS-CARD',
        'default_currency' => 'EUR',
    ]);

    app(DefaultKnownCounterpartyIbansSeeder::class)->run($this->user);

    $this->importRun = ImportRun::create([
        'user_id' => $this->user->id,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/pair-alias.csv',
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
function aliasPairTx(User $user, Account $account, ImportRun $run, array $overrides = []): Transaction
{
    static $rowIndex = 0;
    $rowIndex++;

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
        'source_row_index' => $rowIndex,
        'fingerprint' => str_pad((string) $rowIndex, 64, 'q', STR_PAD_LEFT),
        'fingerprint_version' => 3,
    ], $overrides));
}

it('pairs ASN transfer_out with PayPal transfer_in via alias fallback when PayPal side carries no counterparty IBAN', function (): void {
    // The PayPal CSV carries no per-row counterparty IBAN, so that side stores
    // '' and short-circuits. Only the ASN side fires, and its institution IBAN
    // matches no Account.iban, leaving the alias bridge as the only route.
    $asnLeg = aliasPairTx($this->user, $this->bank, $this->importRun, [
        'type' => 'transfer_out',
        'amount_minor' => -5000,
        'settled_amount_minor' => -5000,
        'counterparty_iban' => 'LU89751000135104200E',
    ]);

    $paypalLeg = aliasPairTx($this->user, $this->paypal, $this->importRun, [
        'type' => 'transfer_in',
        'amount_minor' => 5000,
        'settled_amount_minor' => 5000,
        'counterparty_iban' => '',
    ]);

    $this->events->dispatch(new TransactionImported($asnLeg, $this->user));
    // A no-op, kept because the real import pipeline dispatches for every row.
    $this->events->dispatch(new TransactionImported($paypalLeg, $this->user));

    /** @var Transaction $asn */
    $asn = Transaction::query()->findOrFail($asnLeg->id);
    /** @var Transaction $paypal */
    $paypal = Transaction::query()->findOrFail($paypalLeg->id);
    expect($asn->pair_transaction_id)->toBe($paypalLeg->id);
    expect($paypal->pair_transaction_id)->toBe($asnLeg->id);
});

it('pairs when the ASN-side leg lands strictly BEFORE the PayPal-side leg — reverse alias lookup closes the pair', function (): void {
    // The PayPal side arriving second used to short-circuit on its empty
    // counterparty_iban instead of running the reverse alias lookup.
    $asnLeg = aliasPairTx($this->user, $this->bank, $this->importRun, [
        'type' => 'transfer_out',
        'amount_minor' => -7777,
        'settled_amount_minor' => -7777,
        'counterparty_iban' => 'LU89751000135104200E',
    ]);

    // ASN first: no PayPal leg exists yet, so the listener writes nothing.
    $this->events->dispatch(new TransactionImported($asnLeg, $this->user));

    /** @var Transaction $asnAfterFirst */
    $asnAfterFirst = Transaction::query()->findOrFail($asnLeg->id);
    expect($asnAfterFirst->pair_transaction_id)->toBeNull();

    // PayPal second: the reverse lookup spans the account's own iban plus every
    // alias with target_account_kind 'paypal', so it finds the ASN partner.
    $paypalLeg = aliasPairTx($this->user, $this->paypal, $this->importRun, [
        'type' => 'transfer_in',
        'amount_minor' => 7777,
        'settled_amount_minor' => 7777,
        'counterparty_iban' => '',
    ]);
    $this->events->dispatch(new TransactionImported($paypalLeg, $this->user));

    /** @var Transaction $asn */
    $asn = Transaction::query()->findOrFail($asnLeg->id);
    /** @var Transaction $paypal */
    $paypal = Transaction::query()->findOrFail($paypalLeg->id);
    expect($asn->pair_transaction_id)->toBe($paypalLeg->id);
    expect($paypal->pair_transaction_id)->toBe($asnLeg->id);
});

it('pairs symmetrically when PayPal-side leg fires first AND both rows are already persisted — reverse alias lookup closes the pair immediately', function (): void {
    $asnLeg = aliasPairTx($this->user, $this->bank, $this->importRun, [
        'type' => 'transfer_out',
        'amount_minor' => -2500,
        'settled_amount_minor' => -2500,
        'counterparty_iban' => 'LU89751000135104200E',
    ]);
    $paypalLeg = aliasPairTx($this->user, $this->paypal, $this->importRun, [
        'type' => 'transfer_in',
        'amount_minor' => 2500,
        'settled_amount_minor' => 2500,
        'counterparty_iban' => '',
    ]);

    // The in-batch shape RecordTransactions produces inside its outer
    // transaction: both rows exist before either event fires, so the pair
    // closes on a single dispatch.
    $this->events->dispatch(new TransactionImported($paypalLeg, $this->user));

    /** @var Transaction $asn */
    $asn = Transaction::query()->findOrFail($asnLeg->id);
    /** @var Transaction $paypal */
    $paypal = Transaction::query()->findOrFail($paypalLeg->id);
    expect($asn->pair_transaction_id)->toBe($paypalLeg->id);
    expect($paypal->pair_transaction_id)->toBe($asnLeg->id);

    // ASN-side re-fire is a no-op because both rows are already paired.
    $this->events->dispatch(new TransactionImported($asn, $this->user));

    /** @var Transaction $asnAfter */
    $asnAfter = Transaction::query()->findOrFail($asnLeg->id);
    /** @var Transaction $paypalAfter */
    $paypalAfter = Transaction::query()->findOrFail($paypalLeg->id);
    expect($asnAfter->pair_transaction_id)->toBe($paypalLeg->id);
    expect($paypalAfter->pair_transaction_id)->toBe($asnLeg->id);
});

it('falls back to alias bridge ONLY when literal lookup misses', function (): void {
    // Literal IBANs matching each other's counterparty: the spy proves the
    // alias resolver is never consulted.
    $bankB = Account::create([
        'user_id' => $this->user->id,
        'name' => 'Second ASN',
        'slug' => 'pair-alias-bankb',
        'kind' => 'bank',
        'iban' => 'NL00BANKB',
        'default_currency' => 'EUR',
    ]);

    $spy = new class(app(KnownCounterpartyIbanResolver::class)) implements ResolvesKnownCounterpartyIban
    {
        public int $callCount = 0;

        public function __construct(private readonly KnownCounterpartyIbanResolver $inner) {}

        public function resolveAccount(string $iban, int $userId): ?Account
        {
            $this->callCount++;

            return $this->inner->resolveAccount($iban, $userId);
        }
    };
    $this->app->instance(ResolvesKnownCounterpartyIban::class, $spy);

    $asnLeg = aliasPairTx($this->user, $this->bank, $this->importRun, [
        'type' => 'transfer_out',
        'amount_minor' => -1111,
        'settled_amount_minor' => -1111,
        'counterparty_iban' => 'NL00BANKB',
    ]);
    $partnerLeg = aliasPairTx($this->user, $bankB, $this->importRun, [
        'type' => 'transfer_in',
        'amount_minor' => 1111,
        'settled_amount_minor' => 1111,
        'counterparty_iban' => 'NL57ASNB0123456789',
    ]);

    $this->events->dispatch(new TransactionImported($asnLeg, $this->user));
    $this->events->dispatch(new TransactionImported($partnerLeg, $this->user));

    /** @var Transaction $asn */
    $asn = Transaction::query()->findOrFail($asnLeg->id);
    /** @var Transaction $partner */
    $partner = Transaction::query()->findOrFail($partnerLeg->id);
    expect($asn->pair_transaction_id)->toBe($partnerLeg->id);
    expect($partner->pair_transaction_id)->toBe($asnLeg->id);

    expect($spy->callCount)->toBe(0);
});

it('alias-bridge fallback respects per-user scoping', function (): void {
    // Bob has the accounts but no alias rows, so the same ASN leg must not pair.
    $bob = User::query()->create([
        'username' => 'pair-alias-bob',
        'password' => 'fixture-password',
        'period_start_day' => 1,
    ]);
    $bobBank = Account::create([
        'user_id' => $bob->id,
        'name' => 'Bob ASN',
        'slug' => 'pair-alias-bob-bank',
        'kind' => 'bank',
        'iban' => 'NL00BOB0BANK',
        'default_currency' => 'EUR',
    ]);
    Account::create([
        'user_id' => $bob->id,
        'name' => 'Bob PayPal',
        'slug' => 'pair-alias-bob-paypal',
        'kind' => 'paypal',
        'iban' => 'PAYPAL',
        'default_currency' => 'EUR',
    ]);

    $bobRun = ImportRun::create([
        'user_id' => $bob->id,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/pair-alias-bob.csv',
        'sha256' => str_repeat('b', 64),
        'uploaded_at' => CarbonImmutable::now(),
        'status' => 'previewed',
    ]);

    $bobLeg = aliasPairTx($bob, $bobBank, $bobRun, [
        'type' => 'transfer_out',
        'amount_minor' => -9999,
        'settled_amount_minor' => -9999,
        'counterparty_iban' => 'LU89751000135104200E',
    ]);

    $this->events->dispatch(new TransactionImported($bobLeg, $bob));

    /** @var Transaction $reloaded */
    $reloaded = Transaction::query()->findOrFail($bobLeg->id);
    expect($reloaded->pair_transaction_id)->toBeNull();
});
