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

/**
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

    // LU89751000135104200E is PayPal SARL Luxembourg's real institutional IBAN.
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

    // The empty counterparty IBAN is faithful: a PayPal CSV carries the funding
    // source on the child events, never on the rolled-up parent row.
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

    $asnTyped = $classifier->run($asnRaw, $world['user']);
    $paypalTyped = $classifier->run($paypalRaw, $world['user']);
    expect($asnTyped->type)->toBe('transfer_out');
    expect($paypalTyped->type)->toBe('transfer_in');

    // Order matters: each insert fires TransactionImported synchronously into
    // PairTransferCandidates. The PayPal leg goes first because its empty
    // counterparty_iban short-circuits the listener; the ASN leg then resolves
    // the partner through the alias bridge and pairs both.
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
    // The ICS leg lives in card_statements, so there is no row to pair against.
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
