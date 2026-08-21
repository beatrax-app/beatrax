<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\Import\Database\Seeders\DefaultKnownCounterpartyIbansSeeder;
use Modules\Import\Internal\Pipeline\Stages\ClassifyTransactionType;
use Modules\Import\Models\KnownCounterpartyIban;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Public\Dto\CanonicalTransaction;

uses(RefreshDatabase::class);

// The alias arm runs before the literal own-account-IBAN equality check, so a
// real institution IBAN on the ASN side of a cross-account hop (PayPal
// Luxembourg, ICS at ABN AMRO) retypes without ever matching an account's own
// synthetic IBAN.

/**
 * @return array{user: User, bank: Account, paypal: Account, icsCard: Account}
 */
function classifyAliasSeedUser(string $username, bool $runAliasSeeder = true): array
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
        'iban' => 'NL12ASNB'.str_pad((string) random_int(1000000, 9999999), 10, '0', STR_PAD_LEFT),
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

    if ($runAliasSeeder) {
        app(DefaultKnownCounterpartyIbansSeeder::class)->run($user);
    }

    return compact('user', 'bank', 'paypal', 'icsCard');
}

/**
 * @param  array<string, mixed>  $overrides
 */
function classifyAliasTx(array $overrides = []): CanonicalTransaction
{
    /** @var array<string, mixed> $defaults */
    $defaults = [
        'userId' => null,
        'accountId' => 1,
        'type' => 'expense',
        'postedAt' => CarbonImmutable::parse('2026-04-15'),
        'bookedAt' => CarbonImmutable::parse('2026-04-15 12:00:00'),
        'valueDate' => CarbonImmutable::parse('2026-04-15'),
        'amountMinor' => -12345,
        'currency' => 'EUR',
        'settledAmountMinor' => -12345,
        'settledCurrency' => 'EUR',
        'fxRateUsed' => null,
        'counterpartyName' => 'PayPal Europe',
        'counterpartyIban' => null,
        'counterpartyNormalized' => 'paypal europe',
        'normalizationVersion' => 1,
        'description' => 'top-up',
        'categoryId' => null,
        'sourceFormat' => 'asn-csv',
        'importRunId' => 1,
        'sourceRowIndex' => 0,
        'sourceRef' => 'REF-1',
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

it('retypes ASN expense to transfer_out when counterparty_iban matches a seeded PayPal alias', function (): void {
    $world = classifyAliasSeedUser('alias-paypal-out');

    $tx = classifyAliasTx([
        'accountId' => $world['bank']->id,
        'counterpartyIban' => 'LU89751000135104200E',
        'amountMinor' => -12345,
        'settledAmountMinor' => -12345,
    ]);

    /** @var ClassifyTransactionType $stage */
    $stage = app(ClassifyTransactionType::class);
    $result = $stage->run($tx, $world['user']);

    expect($result->type)->toBe('transfer_out');
});

it('retypes ASN income to transfer_in when counterparty_iban matches a seeded ICS alias and amount is positive', function (): void {
    $world = classifyAliasSeedUser('alias-ics-in');

    $tx = classifyAliasTx([
        'accountId' => $world['bank']->id,
        'counterpartyIban' => 'NL08ABNA0526650664',
        'amountMinor' => 9999,
        'settledAmountMinor' => 9999,
        'counterpartyName' => 'ICS refund',
        'counterpartyNormalized' => 'ics refund',
        'type' => 'income',
    ]);

    /** @var ClassifyTransactionType $stage */
    $stage = app(ClassifyTransactionType::class);
    $result = $stage->run($tx, $world['user']);

    expect($result->type)->toBe('transfer_in');
});

it('returns unchanged when alias exists but user has no account of the target kind', function (): void {
    $user = User::query()->create([
        'username' => 'alias-no-target-account',
        'password' => 'fixture-password',
        'period_start_day' => 1,
    ]);
    $bank = Account::create([
        'user_id' => $user->id,
        'name' => 'Only ASN',
        'slug' => 'alias-no-target-account-asn',
        'kind' => 'bank',
        'iban' => 'NL21ASNB0000009999',
        'default_currency' => 'EUR',
    ]);

    KnownCounterpartyIban::query()->create([
        'user_id' => $user->id,
        'real_iban' => 'LU89751000135104200E',
        'target_account_kind' => 'paypal',
        'notes' => 'PayPal SARL et Cie SCA, Luxembourg.',
    ]);

    $tx = classifyAliasTx([
        'accountId' => $bank->id,
        'counterpartyIban' => 'LU89751000135104200E',
        'amountMinor' => -2500,
        'settledAmountMinor' => -2500,
    ]);

    /** @var ClassifyTransactionType $stage */
    $stage = app(ClassifyTransactionType::class);
    $result = $stage->run($tx, $user);

    expect($result->type)->toBe('expense');
});

it('alias arm takes precedence over the literal-equality fallback', function (): void {
    // Both arms can succeed here: bankA's IBAN is the counterparty AND is
    // aliased to the user's paypal account. Removing bankA later is what
    // isolates the alias arm.
    $user = User::query()->create([
        'username' => 'alias-precedence',
        'password' => 'fixture-password',
        'period_start_day' => 1,
    ]);
    $bankA = Account::create([
        'user_id' => $user->id,
        'name' => 'BANKA',
        'slug' => 'alias-precedence-banka',
        'kind' => 'bank',
        'iban' => 'NL00BANKA',
        'default_currency' => 'EUR',
    ]);
    $bankB = Account::create([
        'user_id' => $user->id,
        'name' => 'BANKB',
        'slug' => 'alias-precedence-bankb',
        'kind' => 'bank',
        'iban' => 'NL00BANKB',
        'default_currency' => 'EUR',
    ]);
    $paypal = Account::create([
        'user_id' => $user->id,
        'name' => 'PayPal',
        'slug' => 'alias-precedence-paypal',
        'kind' => 'paypal',
        'iban' => 'PAYPAL',
        'default_currency' => 'EUR',
    ]);
    KnownCounterpartyIban::query()->create([
        'user_id' => $user->id,
        'real_iban' => 'NL00BANKA',
        'target_account_kind' => 'paypal',
        'notes' => 'Hypothetical alias mapping BANKA IBAN to the user paypal account.',
    ]);

    $tx = classifyAliasTx([
        'accountId' => $bankB->id,
        'counterpartyIban' => 'NL00BANKA',
        'amountMinor' => -7777,
        'settledAmountMinor' => -7777,
    ]);

    /** @var ClassifyTransactionType $stage */
    $stage = app(ClassifyTransactionType::class);

    // Either arm could have produced this one.
    $result = $stage->run($tx, $user);
    expect($result->type)->toBe('transfer_out');

    // With BANKA gone the literal arm cannot fire, and the alias arm still
    // resolves NL00BANKA -> paypal -> the user's paypal account.
    $bankA->delete();

    $resultAfter = $stage->run($tx, $user);
    expect($resultAfter->type)->toBe('transfer_out');
    expect($paypal->fresh())->not->toBeNull();
});

it('alias misses are no-ops — non-seeded IBANs fall through to the literal-equality check and then the amount-sign default', function (): void {
    $world = classifyAliasSeedUser('alias-miss-noop');

    $tx = classifyAliasTx([
        'accountId' => $world['bank']->id,
        'counterpartyIban' => 'LU00BOGUS00000000000',
        'amountMinor' => -50,
        'settledAmountMinor' => -50,
    ]);

    /** @var ClassifyTransactionType $stage */
    $stage = app(ClassifyTransactionType::class);
    $result = $stage->run($tx, $world['user']);

    expect($result->type)->toBe('expense');
});

it('alias scoping is per-user — user As alias does not retype user Bs transaction', function (): void {
    classifyAliasSeedUser('alias-scope-a', runAliasSeeder: true);
    $worldB = classifyAliasSeedUser('alias-scope-b', runAliasSeeder: false);

    $tx = classifyAliasTx([
        'accountId' => $worldB['bank']->id,
        'counterpartyIban' => 'LU89751000135104200E',
        'amountMinor' => -5000,
        'settledAmountMinor' => -5000,
    ]);

    /** @var ClassifyTransactionType $stage */
    $stage = app(ClassifyTransactionType::class);
    $result = $stage->run($tx, $worldB['user']);

    expect($result->type)->not->toBe('transfer_out');
    expect($result->type)->toBe('expense');
});
