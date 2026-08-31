<?php

declare(strict_types=1);

use App\Models\User;
use Carbon\CarbonImmutable;
use Modules\Import\Internal\Pipeline\Stages\PaymentTypeClassifierStage;
use Modules\Import\Public\Enums\PaymentType;
use Modules\Ledger\Public\Dto\CanonicalTransaction;

beforeEach(function (): void {
    /** @var PaymentTypeClassifierStage $stage */
    $stage = $this->app->make(PaymentTypeClassifierStage::class);
    $this->stage = $stage;

    $this->user = User::query()->create([
        'username' => 'ptype-classifier-feature',
        'password' => 'fixture-password',
        'period_start_day' => 1,
    ]);
});

/**
 * @param  array<int|string, mixed>|null  $rawPayload
 */
function ptypeFeatureRow(string $description, string $sourceFormat, ?array $rawPayload = null): CanonicalTransaction
{
    return new CanonicalTransaction(
        userId: 1,
        accountId: 1,
        type: 'expense',
        postedAt: CarbonImmutable::parse('2026-05-15'),
        bookedAt: CarbonImmutable::parse('2026-05-15 00:00:00'),
        valueDate: CarbonImmutable::parse('2026-05-15'),
        amountMinor: -1234,
        currency: 'EUR',
        settledAmountMinor: -1234,
        settledCurrency: 'EUR',
        counterpartyName: 'Some Counterparty',
        counterpartyIban: null,
        counterpartyNormalized: 'some counterparty',
        normalizationVersion: 3,
        description: $description,
        categoryId: null,
        sourceFormat: $sourceFormat,
        importRunId: 0,
        sourceRowIndex: 0,
        sourceRef: null,
        rawPayload: $rawPayload,
    );
}

it('resolves the ASN CSV Betaalautomaat row to PaymentType::Pin', function (): void {
    $tx = ptypeFeatureRow('Betaalautomaat Albert Heijn 1245', 'asn-csv');

    $resolved = $this->stage->run($tx, $this->user, 'asn-csv');

    expect($resolved->paymentType)->toBe(PaymentType::Pin);
});

it('resolves the ASN CSV iDEAL row to PaymentType::Online', function (): void {
    $tx = ptypeFeatureRow('iDEAL Bestelling Bol.com', 'asn-csv');

    $resolved = $this->stage->run($tx, $this->user, 'asn-csv');

    expect($resolved->paymentType)->toBe(PaymentType::Online);
});

it('resolves the ASN CSV Incasso row to PaymentType::DirectDebit', function (): void {
    $tx = ptypeFeatureRow('SEPA Incasso Vattenfall', 'asn-csv');

    $resolved = $this->stage->run($tx, $this->user, 'asn-csv');

    expect($resolved->paymentType)->toBe(PaymentType::DirectDebit);
});

it('resolves the ASN CSV Overboeking row to PaymentType::Transfer', function (): void {
    $tx = ptypeFeatureRow('Overboeking aan partner', 'asn-csv');

    $resolved = $this->stage->run($tx, $this->user, 'asn-csv');

    expect($resolved->paymentType)->toBe(PaymentType::Transfer);
});

it('resolves a PayPal CSV Refund event-type row to PaymentType::Refund', function (): void {
    $tx = ptypeFeatureRow(
        'Refund from merchant',
        'paypal-csv',
        rawPayload: ['format' => 'paypal-csv', 'events' => [['type' => 'Refund Sent']]],
    );

    $resolved = $this->stage->run($tx, $this->user, 'paypal-csv');

    expect($resolved->paymentType)->toBe(PaymentType::Refund);
});

it('resolves a PayPal CSV Service Fee event-type row to PaymentType::Fee', function (): void {
    $tx = ptypeFeatureRow(
        'Service Fee',
        'paypal-csv',
        rawPayload: ['format' => 'paypal-csv', 'events' => [['type' => 'Service Fee']]],
    );

    $resolved = $this->stage->run($tx, $this->user, 'paypal-csv');

    expect($resolved->paymentType)->toBe(PaymentType::Fee);
});

it('resolves the CAMT.053 row authoritatively from BkTxCd PMNT-CCRD-POSD to PaymentType::Pin', function (): void {
    $tx = ptypeFeatureRow(
        'Generic description',
        'camt053',
        rawPayload: ['sepa' => ['btc' => ['domain' => 'PMNT', 'family' => 'CCRD', 'subFamily' => 'POSD']]],
    );

    $resolved = $this->stage->run($tx, $this->user, 'camt053');

    expect($resolved->paymentType)->toBe(PaymentType::Pin);
});

it('falls back to PaymentType::Unknown when no hinter recognises the row', function (): void {
    $tx = ptypeFeatureRow('Pizzeria Roma — eten besteld', 'asn-csv');

    $resolved = $this->stage->run($tx, $this->user, 'asn-csv');

    expect($resolved->paymentType)->toBe(PaymentType::Unknown);
});

it('prefers the source-specific hinter over the description-keyword fallback when both fire', function (): void {
    // "iDEAL" fires PositionalCsvPaymentTypeHinter at confidence 80 and
    // DescriptionKeywordFallbackHinter at 40.
    $tx = ptypeFeatureRow('iDEAL Bestelling', 'asn-csv');

    $resolved = $this->stage->run($tx, $this->user, 'asn-csv');

    expect($resolved->paymentType)->toBe(PaymentType::Online);
});

it('returns a new CanonicalTransaction instance via the immutable withPaymentType wither', function (): void {
    $tx = ptypeFeatureRow('Pizzeria Roma', 'asn-csv');

    $resolved = $this->stage->run($tx, $this->user, 'asn-csv');

    expect($resolved)->not->toBe($tx);
    expect($tx->paymentType)->toBeNull();
    expect($resolved->paymentType)->toBe(PaymentType::Unknown);
});
