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

/*
 * Coverage for the alias-bridge fallback arm of
 * PairTransferCandidates::handle(). The literal own-IBAN match runs
 * first (and is covered by the existing PairTransferCandidatesTest);
 * when it misses, the listener falls back to
 * ResolvesKnownCounterpartyIban so a real institution IBAN appearing
 * on the ASN side of a cross-account hop resolves to the user's own
 * synthetic-IBAN partner account.
 */

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
 * Persist a Transaction with the test-fixture defaults; caller
 * overrides only the keys the test exercises. Matches the shape used
 * by the existing PairTransferCandidatesTest::pairTx() helper.
 *
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
    // The empirical PayPal Activity Download CSV does NOT carry a
    // per-row counterparty IBAN — the funding source IBAN flows
    // through the funding-leg child row, not the parent
    // (Bankstorting). So in the canonical ledger state the PayPal
    // side has counterparty_iban = '' and the listener short-
    // circuits on that side. The ONLY route to a pair is the ASN
    // side firing with its real-institution counterparty IBAN
    // ('LU89751000135104200E') AND the listener consulting the
    // alias bridge to resolve the partner account. Arm A (literal)
    // can never succeed here: 'LU89751000135104200E' does not match
    // any of the user's Account.iban values.
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
    // The PayPal side's empty counterparty_iban makes the listener
    // short-circuit; dispatching the event is a no-op but mirrors
    // the real import pipeline shape.
    $this->events->dispatch(new TransactionImported($paypalLeg, $this->user));

    /** @var Transaction $asn */
    $asn = Transaction::query()->findOrFail($asnLeg->id);
    /** @var Transaction $paypal */
    $paypal = Transaction::query()->findOrFail($paypalLeg->id);
    expect($asn->pair_transaction_id)->toBe($paypalLeg->id);
    expect($paypal->pair_transaction_id)->toBe($asnLeg->id);
});

it('pairs when the ASN-side leg lands strictly BEFORE the PayPal-side leg — reverse alias lookup closes the pair', function (): void {
    // Regression for the ordering-dependent half-state where the ASN
    // side persisted first (with its real institution counterparty
    // IBAN) but found no partner because the PayPal side had not yet
    // arrived, and then the PayPal side persisted second (with its
    // empty counterparty_iban) and previously short-circuited rather
    // than running the reverse alias lookup. The pair MUST close on
    // the PayPal-side fire.
    $asnLeg = aliasPairTx($this->user, $this->bank, $this->importRun, [
        'type' => 'transfer_out',
        'amount_minor' => -7777,
        'settled_amount_minor' => -7777,
        'counterparty_iban' => 'LU89751000135104200E',
    ]);

    // ASN first — its alias-resolved partner account is PayPal but no
    // PayPal-side leg exists yet, so the listener writes nothing.
    $this->events->dispatch(new TransactionImported($asnLeg, $this->user));

    /** @var Transaction $asnAfterFirst */
    $asnAfterFirst = Transaction::query()->findOrFail($asnLeg->id);
    expect($asnAfterFirst->pair_transaction_id)->toBeNull();

    // PayPal second — empty counterparty_iban, but the reverse alias
    // lookup builds the candidate IBAN set
    // {'PAYPAL', 'LU89751000135104200E'} (own iban + every alias whose
    // target_account_kind === 'paypal') and finds the now-persisted
    // ASN partner.
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

    // PayPal first — its counterparty_iban is empty so the listener
    // runs the reverse alias lookup. The ASN partner is ALREADY
    // persisted (both rows exist via Transaction::create() before
    // either event fires — this mirrors the canonical in-batch shape
    // RecordTransactions produces inside its outer DB transaction),
    // so the reverse lookup finds it and the pair closes on this
    // single dispatch. A subsequent ASN-side dispatch is then a
    // defensive no-op because the row is already paired.
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
    // Two bank accounts whose literal IBANs match each other's
    // counterparty — Arm A (literal) succeeds, the alias resolver
    // MUST NOT be consulted. Spy the resolver to assert it is never
    // called.
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

    // The literal arm succeeded on BOTH legs — the alias bridge was
    // never consulted in either handle() call.
    expect($spy->callCount)->toBe(0);
});

it('alias-bridge fallback respects per-user scoping', function (): void {
    // Bob has bank + paypal accounts but NO alias rows. An ASN
    // transfer_out for Bob with the PayPal Luxembourg counterparty
    // IBAN must NOT pair because Bob's alias resolver returns null.
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
