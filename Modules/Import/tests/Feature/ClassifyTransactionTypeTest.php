<?php

declare(strict_types=1);

use App\Models\User;
use Carbon\CarbonImmutable;
use Modules\Import\Internal\Pipeline\Stages\ClassifyTransactionType;
use Modules\Ingestion\Public\Exceptions\MissingPaypalTransactionTypeMapException;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Public\Dto\CanonicalTransaction;

/*
 * Coverage for the ClassifyTransactionType pipeline stage.
 *
 * The stage decouples typing from pairing:
 *
 *   1. Refund / fee / adjustment rows preserve their pre-classified type
 *   2. counterparty_iban matching one of the user's OWN Account.iban rows
 *      flips the type to transfer_out (negative) or transfer_in (positive)
 *   3. PayPal-specific source-format event-type map: parent event type
 *      resolves via PaypalCsvEventTypeMap::transactionType()
 *   4. Subtractive income rule: positive amount NOT classified as
 *      transfer/refund/fee becomes income
 *   5. Default: NormalizeStage's amount-sign default survives unchanged
 *
 * Listener-purity invariant: the stage NEVER queries the transactions
 * table — the grep gate in this file asserts zero `Transaction::`
 * occurrences in the stage source (comment-stripped).
 */

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
 * Build a CanonicalTransaction with sane defaults; tests override only
 * the keys they exercise.
 *
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
        'fxRateUsed' => null,
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
    // Other user owns an account whose IBAN our primary user's tx references
    // as counterparty. The stage MUST NOT flip the primary user's row.
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

    // Strip single-line `//` and block `/** ... */` comments before
    // greping so the rule of "no Transaction:: in code" tolerates
    // documentary references in PHPDoc.
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

it('re-throws MissingPaypalTransactionTypeMapException when transactionType() reports a code-internal inconsistency', function (): void {
    // `'Bankstorting naar PP-rekening'` is mapped as `child-fee` in
    // MAP — it exists in MAP but deliberately has no TRANSACTION_TYPE
    // entry. Calling transactionType() against it triggers the
    // narrower MissingPaypalTransactionTypeMapException, which
    // signals a code-internal inconsistency (in practice: a future
    // `parent` MAP entry whose TRANSACTION_TYPE row was forgotten).
    // The catch ordering in ClassifyTransactionType MUST surface
    // this as a hard failure rather than swallowing it via the
    // supertype catch and falling through to the amount-sign
    // default. The runtime can drive the child-fee event type
    // here because rawPayload.events[0].type is whatever string the
    // adapter put there — a regression in event-rollup logic could
    // surface child-fee rows here, and the listener MUST scream.
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
            'events' => [['type' => 'Bankstorting naar PP-rekening']],
        ],
    ]);

    expect(fn () => $this->stage->run($tx, $this->primaryUser))
        ->toThrow(MissingPaypalTransactionTypeMapException::class);
});

it('still swallows UnknownPaypalEventTypeException and falls through to the subtractive default for an unmapped parent event type', function (): void {
    // An event type that does not exist in MAP at all raises the
    // broader UnknownPaypalEventTypeException (user-data condition).
    // The stage MUST catch it and let the amount-sign default
    // classify the row rather than aborting the import.
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

    // Subtractive default keeps the row at `expense` (negative
    // amount, not transfer / refund / fee, not income).
    expect($result->type)->toBe('expense');
});
