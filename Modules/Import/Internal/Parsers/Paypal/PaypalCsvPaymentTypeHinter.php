<?php

declare(strict_types=1);

namespace Modules\Import\Internal\Parsers\Paypal;

use Modules\Import\Public\Contracts\PaymentTypeHinter;
use Modules\Import\Public\Dto\PaymentTypeHint;
use Modules\Import\Public\Enums\PaymentType;
use Modules\Ledger\Public\Dto\CanonicalTransaction;

final class PaypalCsvPaymentTypeHinter implements PaymentTypeHinter
{
    private const SOURCE_FORMAT = 'paypal-csv';

    /**
     * @var array<string, array{type: PaymentType, confidence: int}>
     */
    private const EVENT_TYPES = [
        'vooraf goedgekeurde betaling – rekening betaald door gebruiker' => ['type' => PaymentType::Online, 'confidence' => 95],
        'express checkout-betaling' => ['type' => PaymentType::Online, 'confidence' => 95],
        'algemene valutaomrekening' => ['type' => PaymentType::Fee, 'confidence' => 95],

        // EN event types PayPal often leaves un-localised. Without them an
        // export that switches to English regresses every row to the
        // description-keyword fallback.
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
        $key = $eventType !== null ? mb_strtolower($eventType) : null;
        if ($key === null || ! isset(self::EVENT_TYPES[$key])) {
            return null;
        }

        $entry = self::EVENT_TYPES[$key];

        return new PaymentTypeHint(
            type: $entry['type'],
            confidence: $entry['confidence'],
            sourceHint: 'event_type:'.$key,
        );
    }

    private function extractFirstEventType(CanonicalTransaction $tx): ?string
    {
        $rawPayload = $tx->rawPayload;
        $events = is_array($rawPayload) ? ($rawPayload['events'] ?? null) : null;
        if (! is_array($events) || $events === []) {
            return null;
        }

        $first = $events[array_key_first($events)];
        if (! is_array($first)) {
            return null;
        }

        $type = $first['type'] ?? null;

        return is_string($type) && $type !== '' ? $type : null;
    }
}
