<?php

declare(strict_types=1);

use App\Models\User;
use Carbon\CarbonImmutable;
use Modules\Import\Internal\Pipeline\Stages\ClassifyTransactionType;
use Modules\Ingestion\Public\Exceptions\OrphanedPaypalChildRowException;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Public\Dto\CanonicalTransaction;

/** @link ../../../../.docs/architecture/ingestion-pipeline.md#4-transaction-type-classification-classifytransactiontype */
beforeEach(function (): void {
    /** @var ClassifyTransactionType $stage */
    $stage = $this->app->make(ClassifyTransactionType::class);
    $this->stage = $stage;

    $this->primaryUser = User::query()->create([
        'username' => 'classify-primary',
        'password' => 'fixture-password',
        'period_start_day' => 1,
    ]);

    $this->asnAccount = Account::create([
        'user_id' => $this->primaryUser->id,
        'name' => 'ASN',
        'slug' => 'classify-asn',
        'kind' => 'bank',
        'iban' => 'NL57ASNB0123456789',
        'default_currency' => 'EUR',
    ]);

    $this->icsAccount = Account::create([
        'user_id' => $this->primaryUser->id,
        'name' => 'ICS card',
        'slug' => 'classify-ics-card',
        'kind' => 'ics_card',
        'iban' => 'ICS-CARD',
        'default_currency' => 'EUR',
    ]);
});

/**
 * @param  array<string, mixed>  $overrides
 */
function classifyCanonical(array $overrides = []): CanonicalTransaction
{
    /** @var array<string, mixed> $defaults */
    $defaults = [
        'userId' => null,
        'accountId' => 1,
        'type' => 'expense',
        'postedAt' => CarbonImmutable::parse('2026-05-15'),
        'bookedAt' => CarbonImmutable::parse('2026-05-15 12:00:00'),
        'valueDate' => CarbonImmutable::parse('2026-05-15'),
        'amountMinor' => -1299,
        'currency' => 'EUR',
        'settledAmountMinor' => -1299,
        'settledCurrency' => 'EUR',
        'counterpartyName' => 'AH Amsterdam',
        'counterpartyIban' => null,
        'counterpartyNormalized' => 'ah amsterdam',
        'normalizationVersion' => 1,
        'description' => 'groceries',
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

it('flips a positive non-transfer row to income (subtractive income rule)', function (): void {
    $tx = classifyCanonical([
        'accountId' => $this->asnAccount->id,
        'type' => 'income',  // NormalizeStage's positive-amount default
        'amountMinor' => 250000,
        'settledAmountMinor' => 250000,
        'counterpartyIban' => 'NL69FOREIGN0000000', // not own account
        'counterpartyName' => 'Acme Salary',
    ]);

    $result = $this->stage->run($tx, $this->primaryUser);

    expect($result->type)->toBe('income');
});

it('keeps a positive transfer_in row as transfer_in (income rule does not flip transfers)', function (): void {
    $tx = classifyCanonical([
        'accountId' => $this->asnAccount->id,
        'type' => 'income', // NormalizeStage default for the positive sign
        'amountMinor' => 12345,
        'settledAmountMinor' => 12345,
        'counterpartyIban' => 'ICS-CARD', // matches own ICS account
    ]);

    $result = $this->stage->run($tx, $this->primaryUser);

    expect($result->type)->toBe('transfer_in');
});

it('flips a negative own-account row to transfer_out', function (): void {
    $tx = classifyCanonical([
        'accountId' => $this->asnAccount->id,
        'type' => 'expense',
        'amountMinor' => -12345,
        'settledAmountMinor' => -12345,
        'counterpartyIban' => 'ICS-CARD',
    ]);

    $result = $this->stage->run($tx, $this->primaryUser);

    expect($result->type)->toBe('transfer_out');
});

it('preserves a refund row regardless of amount sign or counterparty', function (): void {
    $tx = classifyCanonical([
        'accountId' => $this->asnAccount->id,
        'type' => 'refund',
        'amountMinor' => 4500,
        'settledAmountMinor' => 4500,
        'counterpartyIban' => 'ICS-CARD', // would normally flip; refund wins
    ]);

    $result = $this->stage->run($tx, $this->primaryUser);

    expect($result->type)->toBe('refund');
});

it('preserves a fee row unchanged', function (): void {
    $tx = classifyCanonical([
        'accountId' => $this->asnAccount->id,
        'type' => 'fee',
        'amountMinor' => -300,
        'settledAmountMinor' => -300,
    ]);

    $result = $this->stage->run($tx, $this->primaryUser);

    expect($result->type)->toBe('fee');
});

it('flips a paypal-csv row whose counterparty matches an own ASN IBAN to transfer_out (cross-account step wins over event-type map)', function (): void {
    /** @var Account $paypalAccount */
    $paypalAccount = Account::create([
        'user_id' => $this->primaryUser->id,
        'name' => 'PayPal',
        'slug' => 'classify-paypal',
        'kind' => 'paypal',
        'iban' => 'PAYPAL',
        'default_currency' => 'EUR',
    ]);

    $tx = classifyCanonical([
        'accountId' => $paypalAccount->id,
        'type' => 'expense',
        'amountMinor' => -2500,
        'settledAmountMinor' => -2500,
        'counterpartyIban' => 'NL57ASNB0123456789', // own ASN account
        'sourceFormat' => 'paypal-csv',
        'rawPayload' => [
            'format' => 'paypal-csv',
            'language' => 'nl',
            'events' => [
                ['type' => 'Vooraf goedgekeurde betaling – rekening betaald door gebruiker'],
            ],
        ],
    ]);

    $result = $this->stage->run($tx, $this->primaryUser);

    expect($result->type)->toBe('transfer_out');
});

it('preserves an already-classified refund on a paypal-csv row', function (): void {
    /** @var Account $paypalAccount */
    $paypalAccount = Account::create([
        'user_id' => $this->primaryUser->id,
        'name' => 'PayPal',
        'slug' => 'classify-paypal-refund',
        'kind' => 'paypal',
        'iban' => 'PAYPAL',
        'default_currency' => 'EUR',
    ]);

    $tx = classifyCanonical([
        'accountId' => $paypalAccount->id,
        'type' => 'refund',
        'amountMinor' => 5000,
        'settledAmountMinor' => 5000,
        'counterpartyIban' => null,
        'sourceFormat' => 'paypal-csv',
        'rawPayload' => [
            'format' => 'paypal-csv',
            'language' => 'nl',
            'events' => [['type' => 'Vooraf goedgekeurde betaling – rekening betaald door gebruiker']],
        ],
    ]);

    $result = $this->stage->run($tx, $this->primaryUser);

    expect($result->type)->toBe('refund');
});

it('does not flip across users — counterparty matches a different user\'s account', function (): void {
    $otherUser = User::query()->create([
        'username' => 'classify-other',
        'password' => 'fixture-password',
        'period_start_day' => 1,
    ]);
    Account::create([
        'user_id' => $otherUser->id,
        'name' => 'Other ICS',
        'slug' => 'classify-other-ics',
        'kind' => 'ics_card',
        'iban' => 'NL69FOREIGN0000000',
        'default_currency' => 'EUR',
    ]);

    $tx = classifyCanonical([
        'accountId' => $this->asnAccount->id,
        'type' => 'expense',
        'amountMinor' => -1000,
        'settledAmountMinor' => -1000,
        'counterpartyIban' => 'NL69FOREIGN0000000',
    ]);

    $result = $this->stage->run($tx, $this->primaryUser);

    expect($result->type)->toBe('expense');
});

it('never queries the transactions table from within ClassifyTransactionType (listener-purity grep gate)', function (): void {
    $stagePath = realpath(__DIR__.'/../../Internal/Pipeline/Stages/ClassifyTransactionType.php');
    expect($stagePath)->toBeString();
    /** @var string $stagePath */
    $contents = file_get_contents($stagePath);
    expect($contents)->toBeString();
    /** @var string $contents */

    // Comments are stripped first so a documentary `Transaction::` in PHPDoc
    // does not read as a query.
    $stripped = preg_replace('!/\*.*?\*/!s', '', $contents);
    expect($stripped)->toBeString();
    /** @var string $stripped */
    $stripped = preg_replace('!//.*$!m', '', $stripped);
    expect($stripped)->toBeString();
    /** @var string $stripped */
    expect($stripped)->not->toContain('Transaction::');
});

it('keeps a negative non-transfer row as expense (NormalizeStage default unchanged)', function (): void {
    $tx = classifyCanonical([
        'accountId' => $this->asnAccount->id,
        'type' => 'expense',
        'amountMinor' => -1599,
        'settledAmountMinor' => -1599,
        'counterpartyIban' => 'NL69FOREIGN0000000', // not own
    ]);

    $result = $this->stage->run($tx, $this->primaryUser);

    expect($result->type)->toBe('expense');
});

it('re-throws OrphanedPaypalChildRowException when a promoted child row reaches transactionType()', function (): void {
    // An FX leg is in MAP as a child and deliberately has no TRANSACTION_TYPE
    // row, so it reaches the orphan exception. The catch ordering must not let
    // the supertype catch swallow it into the amount-sign default — a row the
    // reader has to act on cannot land as a silent expense.
    $tx = classifyCanonical([
        'accountId' => $this->asnAccount->id,
        'type' => 'expense',
        'amountMinor' => -1234,
        'settledAmountMinor' => -1234,
        'counterpartyIban' => null,
        'sourceFormat' => 'paypal-csv',
        'rawPayload' => [
            'format' => 'paypal-csv',
            'language' => 'nl',
            'events' => [['type' => 'Algemene valutaomrekening']],
        ],
    ]);

    expect(fn () => $this->stage->run($tx, $this->primaryUser))
        ->toThrow(OrphanedPaypalChildRowException::class);
});

it('types a per-purchase funding leg as transfer_in rather than folding it away', function (): void {
    $tx = classifyCanonical([
        'accountId' => $this->asnAccount->id,
        'type' => 'income',
        'amountMinor' => 1234,
        'settledAmountMinor' => 1234,
        'counterpartyIban' => null,
        'sourceFormat' => 'paypal-csv',
        'rawPayload' => [
            'format' => 'paypal-csv',
            'language' => 'nl',
            'events' => [['type' => 'Bankstorting naar PP-rekening']],
        ],
    ]);

    expect($this->stage->run($tx, $this->primaryUser)->type)->toBe('transfer_in');
});

it('still swallows UnknownPaypalEventTypeException and falls through to the subtractive default for an unmapped parent event type', function (): void {
    // An event type absent from MAP is a user-data condition, not a code bug,
    // so the import must survive it rather than abort.
    $tx = classifyCanonical([
        'accountId' => $this->asnAccount->id,
        'type' => 'expense',
        'amountMinor' => -789,
        'settledAmountMinor' => -789,
        'counterpartyIban' => null,
        'sourceFormat' => 'paypal-csv',
        'rawPayload' => [
            'format' => 'paypal-csv',
            'language' => 'nl',
            'events' => [['type' => 'Some Event Type PayPal Has Not Shipped Yet']],
        ],
    ]);

    $result = $this->stage->run($tx, $this->primaryUser);

    expect($result->type)->toBe('expense');
});
