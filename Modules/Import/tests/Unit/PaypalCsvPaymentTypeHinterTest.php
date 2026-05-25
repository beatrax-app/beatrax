<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Modules\Import\Internal\Parsers\Paypal\PaypalCsvPaymentTypeHinter;
use Modules\Import\Public\Enums\PaymentType;
use Modules\Ledger\Public\Dto\CanonicalTransaction;

/*
 * Unit coverage for PaypalCsvPaymentTypeHinter. The hinter keys off
 * the first event-type in the PayPal manifest the adapter persists at
 * `rawPayload['events'][0]['type']` — these tests build a minimal
 * rawPayload by hand and assert each branch of the event-type map.
 */

/**
 * Build a CanonicalTransaction whose rawPayload carries the supplied
 * PayPal event-type literal in the first event slot.
 *
 * @param  array<int|string, mixed>|null  $rawPayload
 */
function paypalPtypeRow(?string $eventType, ?array $rawPayload = null, string $sourceFormat = 'paypal-csv'): CanonicalTransaction
{
    if ($rawPayload === null) {
        $rawPayload = $eventType === null
            ? null
            : ['format' => 'paypal-csv', 'events' => [['type' => $eventType]]];
    }

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
        fxRateUsed: null,
        counterpartyName: 'Some Merchant',
        counterpartyIban: null,
        counterpartyNormalized: 'some merchant',
        normalizationVersion: 3,
        description: 'some merchant',
        categoryId: null,
        sourceFormat: $sourceFormat,
        importRunId: 0,
        sourceRowIndex: 0,
        sourceRef: null,
        rawPayload: $rawPayload,
    );
}

it('maps the observed NL Express Checkout-betaling event type to PaymentType::Online', function (): void {
    $hinter = new PaypalCsvPaymentTypeHinter;
    $hint = $hinter->hint(paypalPtypeRow('Express Checkout-betaling'), 'paypal-csv');

    expect($hint)->not->toBeNull();
    expect($hint?->type)->toBe(PaymentType::Online);
    expect($hint?->confidence)->toBe(95);
});

it('maps the observed NL Algemene valutaomrekening event type to PaymentType::Fee', function (): void {
    $hinter = new PaypalCsvPaymentTypeHinter;
    $hint = $hinter->hint(paypalPtypeRow('Algemene valutaomrekening'), 'paypal-csv');

    expect($hint)->not->toBeNull();
    expect($hint?->type)->toBe(PaymentType::Fee);
});

it('maps the forward-compat EN Refund event type to PaymentType::Refund', function (): void {
    $hinter = new PaypalCsvPaymentTypeHinter;
    $hint = $hinter->hint(paypalPtypeRow('Refund Sent'), 'paypal-csv');

    expect($hint)->not->toBeNull();
    expect($hint?->type)->toBe(PaymentType::Refund);
});

it('maps the forward-compat EN Service Fee event type to PaymentType::Fee', function (): void {
    $hinter = new PaypalCsvPaymentTypeHinter;
    $hint = $hinter->hint(paypalPtypeRow('Service Fee'), 'paypal-csv');

    expect($hint)->not->toBeNull();
    expect($hint?->type)->toBe(PaymentType::Fee);
});

it('maps the forward-compat EN User Initiated Withdrawal event type to PaymentType::Transfer', function (): void {
    $hinter = new PaypalCsvPaymentTypeHinter;
    $hint = $hinter->hint(paypalPtypeRow('User Initiated Withdrawal'), 'paypal-csv');

    expect($hint)->not->toBeNull();
    expect($hint?->type)->toBe(PaymentType::Transfer);
});

it('returns null when the source format is not paypal-csv', function (): void {
    $hinter = new PaypalCsvPaymentTypeHinter;
    $hint = $hinter->hint(paypalPtypeRow('Refund Sent', sourceFormat: 'asn-csv'), 'asn-csv');

    expect($hint)->toBeNull();
});

it('returns null when the rawPayload events manifest is empty or missing', function (): void {
    $hinter = new PaypalCsvPaymentTypeHinter;

    // No rawPayload at all.
    expect($hinter->hint(paypalPtypeRow(null), 'paypal-csv'))->toBeNull();
});

it('returns null when the event type is not in the allow-list', function (): void {
    $hinter = new PaypalCsvPaymentTypeHinter;
    $hint = $hinter->hint(paypalPtypeRow('Some Future Unmapped Event'), 'paypal-csv');

    expect($hint)->toBeNull();
});
