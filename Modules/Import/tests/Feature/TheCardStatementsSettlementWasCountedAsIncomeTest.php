<?php

declare(strict_types=1);

use App\Models\User;
use Carbon\CarbonImmutable;
use Modules\Import\Internal\Pipeline\Stages\ClassifyTransactionType;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Public\Dto\CanonicalTransaction;

// The figures are the committed ICS fixture's own: ics-sample-1.txt books
// "23 jan. 23 jan. IDEAL BETALING, DANK U 606,96 Bij", and the adapter's
// snapshot yields it as +60696 with counterpartyIban null.
/** @link ../../../../.docs/architecture/ingestion-pipeline.md#4-transaction-type-classification-classifytransactiontype */
beforeEach(function (): void {
    $this->stage = $this->app->make(ClassifyTransactionType::class);

    $this->owner = User::query()->create([
        'username' => 'card-settlement',
        'password' => 'fixture-password',
        'period_start_day' => 1,
    ]);

    $this->card = Account::create([
        'user_id' => $this->owner->id,
        'name' => 'ICS card',
        'slug' => 'card-settlement-ics',
        'kind' => 'ics_card',
        'iban' => 'ICS-CARD',
        'default_currency' => 'EUR',
    ]);

    $this->bank = Account::create([
        'user_id' => $this->owner->id,
        'name' => 'ASN',
        'slug' => 'card-settlement-asn',
        'kind' => 'bank',
        'iban' => 'NL57ASNB0123456789',
        'default_currency' => 'EUR',
    ]);
});

function cardSettlementRow(int $accountId, int $amountMinor, string $type): CanonicalTransaction
{
    return new CanonicalTransaction(
        userId: null,
        accountId: $accountId,
        type: $type,
        postedAt: CarbonImmutable::parse('2026-01-23'),
        bookedAt: CarbonImmutable::parse('2026-01-23 00:00:00'),
        valueDate: CarbonImmutable::parse('2026-01-23'),
        amountMinor: $amountMinor,
        currency: 'EUR',
        settledAmountMinor: $amountMinor,
        settledCurrency: 'EUR',
        counterpartyName: 'IDEAL BETALING, DANK U',
        counterpartyIban: null,
        counterpartyNormalized: 'ideal betaling dank u',
        normalizationVersion: 4,
        description: 'IDEAL BETALING, DANK U',
        categoryId: null,
        sourceFormat: 'ics-pdf',
        importRunId: 1,
        sourceRowIndex: 0,
        sourceRef: null,
        rawPayload: null,
    );
}

it('does not count money arriving on a card as income', function (): void {
    $result = $this->stage->run(cardSettlementRow($this->card->id, 60696, 'income'), $this->owner);

    expect($result->type)->toBe('transfer_in');
});

it('still counts money arriving on a bank account as income', function (): void {
    $result = $this->stage->run(cardSettlementRow($this->bank->id, 60696, 'income'), $this->owner);

    expect($result->type)->toBe('income');
});

it('leaves a card charge as the expense it is', function (): void {
    $result = $this->stage->run(cardSettlementRow($this->card->id, -6000, 'expense'), $this->owner);

    expect($result->type)->toBe('expense');
});
