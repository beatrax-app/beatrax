<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\Import\Database\Seeders\DefaultKnownCounterpartyIbansSeeder;
use Modules\Import\Internal\Pipeline\Stages\ClassifyTransactionType;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\ImportRun;
use Modules\Ledger\Models\Transaction;
use Modules\Ledger\Public\Contracts\RecordsTransactions;
use Modules\Ledger\Public\Dto\CanonicalTransaction;

uses(RefreshDatabase::class);

/*
 * End-to-end coverage for the cross-account-hop chain:
 *
 *   ASN expense row (counterparty = real institution IBAN)
 *     -> ClassifyTransactionType (alias bridge -> transfer_out)
 *     -> RecordTransactions (insert + TransactionImported event)
 *     -> PairTransferCandidates (alias-bridge fallback finds partner)
 *     -> bidirectional pair_transaction_id on both legs
 *
 *   plus the PayPal-side Bankstorting -> transfer_in path delivered
 *   by Plan 02 (PayPalCsvEventTypeMap).
 *
 * The test proves that Plans 02 + 03 + 04 close the PayPal funding
 * chain detection end-to-end through the real classifier and the
 * real recorder, not just isolated stages.
 */

/**
 * Build a user with bank + paypal + ics_card accounts and optionally
 * run the alias seeder. Mirrors the helper shape used by the resolver
 * + classifier alias-bridge tests.
 *
 * @return array{user: User, bank: Account, paypal: Account, icsCard: Account, importRun: ImportRun}
 */
function endToEndSeedUserWithAccounts(string $username, bool $runAliasSeeder = true): array
{
    $user = User::query()->create([
        'username' => $username,
        'password' => 'fixture-password',
        'period_start_day' => 1,
    ]);

    $bank = Account::create([
        'user_id' => $user->id,
        'name' => $username.' ASN',
        'slug' => $username.'-asn',
        'kind' => 'bank',
        'iban' => 'NL57ASNB'.str_pad((string) random_int(1000000, 9999999), 10, '0', STR_PAD_LEFT),
        'default_currency' => 'EUR',
    ]);
    $paypal = Account::create([
        'user_id' => $user->id,
        'name' => $username.' PayPal',
        'slug' => $username.'-paypal',
        'kind' => 'paypal',
        'iban' => 'PAYPAL',
        'default_currency' => 'EUR',
    ]);
    $icsCard = Account::create([
        'user_id' => $user->id,
        'name' => $username.' ICS',
        'slug' => $username.'-ics',
        'kind' => 'ics_card',
        'iban' => 'ICS-CARD',
        'default_currency' => 'EUR',
    ]);

    $importRun = ImportRun::create([
        'user_id' => $user->id,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/'.$username.'.csv',
        'sha256' => str_repeat('e', 64),
        'uploaded_at' => CarbonImmutable::now(),
        'status' => 'previewed',
    ]);

    if ($runAliasSeeder) {
        app(DefaultKnownCounterpartyIbansSeeder::class)->run($user);
    }

    return compact('user', 'bank', 'paypal', 'icsCard', 'importRun');
}

/**
 * Build a CanonicalTransaction the recorder will accept. Caller
 * overrides only the keys the test exercises.
 *
 * @param  array<string, mixed>  $overrides
 */
function endToEndCanonical(array $overrides = []): CanonicalTransaction
{
    static $rowIndex = 0;
    $rowIndex++;

    /** @var array<string, mixed> $defaults */
    $defaults = [
        'userId' => null,
        'accountId' => 1,
        'type' => 'expense',
        'postedAt' => CarbonImmutable::parse('2026-04-15'),
        'bookedAt' => CarbonImmutable::parse('2026-04-15 12:00:00'),
        'valueDate' => CarbonImmutable::parse('2026-04-15'),
        'amountMinor' => -5000,
        'currency' => 'EUR',
        'settledAmountMinor' => -5000,
        'settledCurrency' => 'EUR',
        'fxRateUsed' => null,
        'counterpartyName' => 'PayPal',
        'counterpartyIban' => null,
        'counterpartyNormalized' => 'paypal',
        'normalizationVersion' => 1,
        'description' => 'PayPal top-up',
        'categoryId' => null,
        'sourceFormat' => 'asn-csv',
        'importRunId' => 1,
        'sourceRowIndex' => $rowIndex,
        'sourceRef' => 'E2E-'.$rowIndex,
        'rawPayload' => null,
    ];

    $m = array_merge($defaults, $overrides);

    return new CanonicalTransaction(
        userId: $m['userId'],
        accountId: $m['accountId'],
        type: $m['type'],
        postedAt: $m['postedAt'],
        bookedAt: $m['bookedAt'],
        valueDate: $m['valueDate'],
        amountMinor: $m['amountMinor'],
        currency: $m['currency'],
        settledAmountMinor: $m['settledAmountMinor'],
        settledCurrency: $m['settledCurrency'],
        fxRateUsed: $m['fxRateUsed'],
        counterpartyName: $m['counterpartyName'],
        counterpartyIban: $m['counterpartyIban'],
        counterpartyNormalized: $m['counterpartyNormalized'],
        normalizationVersion: $m['normalizationVersion'],
        description: $m['description'],
        categoryId: $m['categoryId'],
        sourceFormat: $m['sourceFormat'],
        importRunId: $m['importRunId'],
        sourceRowIndex: $m['sourceRowIndex'],
        sourceRef: $m['sourceRef'],
        rawPayload: $m['rawPayload'],
    );
}

it('ASN bank row pointing at PayPal SARL Luxembourg becomes transfer_out; PayPal-side Bankstorting becomes transfer_in; PairTransferCandidates links them via alias fallback', function (): void {
    $world = endToEndSeedUserWithAccounts('e2e-paypal');

    /** @var ClassifyTransactionType $classifier */
    $classifier = app(ClassifyTransactionType::class);
    /** @var RecordsTransactions $recorder */
    $recorder = app(RecordsTransactions::class);

    // ASN-side: -5000 EUR going to PayPal SARL Luxembourg's real IBAN.
    $asnRaw = endToEndCanonical([
        'userId' => $world['user']->id,
        'accountId' => $world['bank']->id,
        'counterpartyIban' => 'LU89751000135104200E',
        'counterpartyName' => 'PayPal Europe Sarl',
        'counterpartyNormalized' => 'paypal europe sarl',
        'amountMinor' => -5000,
        'settledAmountMinor' => -5000,
        'sourceFormat' => 'asn-csv',
        'importRunId' => $world['importRun']->id,
    ]);

    // PayPal-side Bankstorting: parent rolled-up event whose
    // PaypalCsvEventTypeMap (Plan 02) classifies as transfer_in. The
    // PayPal CSV does NOT set a per-row counterparty IBAN — the
    // funding source IBAN flows through child events, not the parent
    // row itself.
    $paypalRaw = endToEndCanonical([
        'userId' => $world['user']->id,
        'accountId' => $world['paypal']->id,
        'counterpartyIban' => '',
        'counterpartyName' => 'Bankstorting',
        'counterpartyNormalized' => 'bankstorting',
        'amountMinor' => 5000,
        'settledAmountMinor' => 5000,
        'sourceFormat' => 'paypal-csv',
        'importRunId' => $world['importRun']->id,
        'rawPayload' => [
            'format' => 'paypal-csv',
            'language' => 'nl',
            'events' => [['type' => 'Bankstorting']],
        ],
    ]);

    // Drive both through the classifier first to confirm typing.
    $asnTyped = $classifier->run($asnRaw, $world['user']);
    $paypalTyped = $classifier->run($paypalRaw, $world['user']);
    expect($asnTyped->type)->toBe('transfer_out');
    expect($paypalTyped->type)->toBe('transfer_in');

    // Persist via the recorder. Each row's persistence fires
    // TransactionImported synchronously, which invokes
    // PairTransferCandidates::handle(). Persist the PayPal side first
    // — its empty counterparty_iban makes the listener short-circuit
    // (no work). Then persist the ASN side; its alias-bridge fallback
    // resolves the PayPal partner account and pairs both legs.
    $recorder([$paypalTyped], $world['user']);
    $recorder([$asnTyped], $world['user']);

    /** @var Transaction $asnPersisted */
    $asnPersisted = Transaction::query()
        ->where('user_id', $world['user']->id)
        ->where('account_id', $world['bank']->id)
        ->firstOrFail();
    /** @var Transaction $paypalPersisted */
    $paypalPersisted = Transaction::query()
        ->where('user_id', $world['user']->id)
        ->where('account_id', $world['paypal']->id)
        ->firstOrFail();

    expect($asnPersisted->type)->toBe('transfer_out');
    expect($paypalPersisted->type)->toBe('transfer_in');
    expect($asnPersisted->pair_transaction_id)->toBe($paypalPersisted->id);
    expect($paypalPersisted->pair_transaction_id)->toBe($asnPersisted->id);
});

it('ASN bank row pointing at ICS at ABN AMRO becomes transfer_out (asymmetric leg — ICS side does NOT need a paired row)', function (): void {
    $world = endToEndSeedUserWithAccounts('e2e-ics');

    /** @var ClassifyTransactionType $classifier */
    $classifier = app(ClassifyTransactionType::class);
    /** @var RecordsTransactions $recorder */
    $recorder = app(RecordsTransactions::class);

    $asnRaw = endToEndCanonical([
        'userId' => $world['user']->id,
        'accountId' => $world['bank']->id,
        'counterpartyIban' => 'NL08ABNA0526650664',
        'counterpartyName' => 'ICS bulk settlement',
        'counterpartyNormalized' => 'ics bulk settlement',
        'amountMinor' => -23456,
        'settledAmountMinor' => -23456,
        'sourceFormat' => 'asn-csv',
        'importRunId' => $world['importRun']->id,
    ]);

    $asnTyped = $classifier->run($asnRaw, $world['user']);
    expect($asnTyped->type)->toBe('transfer_out');

    $recorder([$asnTyped], $world['user']);

    /** @var Transaction $asnPersisted */
    $asnPersisted = Transaction::query()
        ->where('user_id', $world['user']->id)
        ->where('account_id', $world['bank']->id)
        ->firstOrFail();

    expect($asnPersisted->type)->toBe('transfer_out');
    // No ICS-side counterpart exists — the ICS leg is statement-level
    // via card_statements (Plan 05 will wire IcsSettlementResolver to
    // consume this transfer_out directly).
    expect($asnPersisted->pair_transaction_id)->toBeNull();
});

it('without the alias seeder the same ASN row stays expense (regression guard for the unseeded baseline)', function (): void {
    $world = endToEndSeedUserWithAccounts('e2e-no-seed', runAliasSeeder: false);

    /** @var ClassifyTransactionType $classifier */
    $classifier = app(ClassifyTransactionType::class);
    /** @var RecordsTransactions $recorder */
    $recorder = app(RecordsTransactions::class);

    $asnRaw = endToEndCanonical([
        'userId' => $world['user']->id,
        'accountId' => $world['bank']->id,
        'counterpartyIban' => 'LU89751000135104200E',
        'counterpartyName' => 'PayPal Europe Sarl',
        'counterpartyNormalized' => 'paypal europe sarl',
        'amountMinor' => -5000,
        'settledAmountMinor' => -5000,
        'sourceFormat' => 'asn-csv',
        'importRunId' => $world['importRun']->id,
    ]);

    $asnTyped = $classifier->run($asnRaw, $world['user']);
    expect($asnTyped->type)->toBe('expense');

    $recorder([$asnTyped], $world['user']);

    /** @var Transaction $persisted */
    $persisted = Transaction::query()
        ->where('user_id', $world['user']->id)
        ->where('account_id', $world['bank']->id)
        ->firstOrFail();
    expect($persisted->type)->toBe('expense');
    expect($persisted->pair_transaction_id)->toBeNull();
});

it('alias bridge does not retype rows belonging to a different user even when the same IBAN appears in their row', function (): void {
    // Alice has alias rows seeded; Bob does not.
    endToEndSeedUserWithAccounts('e2e-scope-alice', runAliasSeeder: true);
    $bob = endToEndSeedUserWithAccounts('e2e-scope-bob', runAliasSeeder: false);

    /** @var ClassifyTransactionType $classifier */
    $classifier = app(ClassifyTransactionType::class);
    /** @var RecordsTransactions $recorder */
    $recorder = app(RecordsTransactions::class);

    $bobRaw = endToEndCanonical([
        'userId' => $bob['user']->id,
        'accountId' => $bob['bank']->id,
        'counterpartyIban' => 'LU89751000135104200E',
        'counterpartyName' => 'PayPal Europe Sarl',
        'counterpartyNormalized' => 'paypal europe sarl',
        'amountMinor' => -5000,
        'settledAmountMinor' => -5000,
        'sourceFormat' => 'asn-csv',
        'importRunId' => $bob['importRun']->id,
    ]);

    $bobTyped = $classifier->run($bobRaw, $bob['user']);
    expect($bobTyped->type)->not->toBe('transfer_out');
    expect($bobTyped->type)->toBe('expense');

    $recorder([$bobTyped], $bob['user']);

    /** @var Transaction $persisted */
    $persisted = Transaction::query()
        ->where('user_id', $bob['user']->id)
        ->where('account_id', $bob['bank']->id)
        ->firstOrFail();
    expect($persisted->type)->toBe('expense');
    expect($persisted->pair_transaction_id)->toBeNull();
});
