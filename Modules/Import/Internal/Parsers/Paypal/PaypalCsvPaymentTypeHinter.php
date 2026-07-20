<?php

declare(strict_types=1);

namespace Modules\Import\Internal\Parsers\Paypal;

use Modules\Import\Public\Contracts\PaymentTypeHinter;
use Modules\Import\Public\Dto\PaymentTypeHint;
use Modules\Import\Public\Enums\PaymentType;
use Modules\Ledger\Public\Dto\CanonicalTransaction;

/**
 * @link ../../../../../.docs/features/import/architecture.md#payment-type-hinters
 */
final class PaypalCsvPaymentTypeHinter implements PaymentTypeHinter
{
    private const SOURCE_FORMAT = 'paypal-csv';

    // Matched case-insensitively on the lower-cased event-type literal
    // so casing variants between NL/EN exports resolve identically.
    /**
     * @var array<string, array{type: PaymentType, confidence: int}>
     */
    private const EVENT_TYPES = [
        'vooraf goedgekeurde betaling – rekening betaald door gebruiker' => ['type' => PaymentType::Online, 'confidence' => 95],
        'express checkout-betaling' => ['type' => PaymentType::Online, 'confidence' => 95],
        'algemene valutaomrekening' => ['type' => PaymentType::Fee, 'confidence' => 95],

        // Forward-compatible EN event types PayPal often leaves
        // un-localised — added so a future NL → EN export switch
        // does not regress every row to the description-keyword
        // fallback.
        'payment sent' => ['type' => PaymentType::Online, 'confidence' => 95],
        'subscription payment' => ['type' => PaymentType::Online, 'confidence' => 95],
        'recurring payment sent' => ['type' => PaymentType::Online, 'confidence' => 95],
        'pre-approved payment' => ['type' => PaymentType::Online, 'confidence' => 95],
        'express checkout payment' => ['type' => PaymentType::Online, 'confidence' => 95],

        'refund' => ['type' => PaymentType::Refund, 'confidence' => 95],
        'refund sent' => ['type' => PaymentType::Refund, 'confidence' => 95],
        'cancelled payment' => ['type' => PaymentType::Refund, 'confidence' => 95],
        'canceled payment' => ['type' => PaymentType::Refund, 'confidence' => 95],

        'fee' => ['type' => PaymentType::Fee, 'confidence' => 95],
        'service fee' => ['type' => PaymentType::Fee, 'confidence' => 95],
        'paypal fee' => ['type' => PaymentType::Fee, 'confidence' => 95],

        'user initiated withdrawal' => ['type' => PaymentType::Transfer, 'confidence' => 85],
        'general withdrawal' => ['type' => PaymentType::Transfer, 'confidence' => 85],
        'bank deposit to pp account' => ['type' => PaymentType::Transfer, 'confidence' => 85],
    ];

    public function hint(CanonicalTransaction $tx, string $sourceFormat): ?PaymentTypeHint
    {
        if ($sourceFormat !== self::SOURCE_FORMAT) {
            return null;
        }

        $eventType = $this->extractFirstEventType($tx);
        if ($eventType === null) {
            return null;
        }

        $key = mb_strtolower($eventType);
        if (! isset(self::EVENT_TYPES[$key])) {
            return null;
        }

        $entry = self::EVENT_TYPES[$key];

        return new PaymentTypeHint(
            type: $entry['type'],
            confidence: $entry['confidence'],
            sourceHint: 'event_type:'.$key,
        );
    }

    // Null when the manifest structure is missing/malformed — the
    // classifier then falls through to the description-keyword fallback.
    private function extractFirstEventType(CanonicalTransaction $tx): ?string
    {
        $rawPayload = $tx->rawPayload;
        if (! is_array($rawPayload)) {
            return null;
        }

        $events = $rawPayload['events'] ?? null;
        if (! is_array($events) || $events === []) {
            return null;
        }

        // array_key_first is non-null because $events is non-empty
        // (the guard above returned null when $events === []).
        $firstKey = array_key_first($events);
        $first = $events[$firstKey];
        if (! is_array($first)) {
            return null;
        }

        $type = $first['type'] ?? null;

        return is_string($type) && $type !== '' ? $type : null;
    }
}
