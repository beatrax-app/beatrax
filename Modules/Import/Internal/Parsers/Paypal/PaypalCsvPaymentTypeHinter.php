<?php

declare(strict_types=1);

namespace Modules\Import\Internal\Parsers\Paypal;

use Modules\Import\Public\Contracts\PaymentTypeHinter;
use Modules\Import\Public\Dto\PaymentTypeHint;
use Modules\Import\Public\Enums\PaymentType;
use Modules\Ledger\Public\Dto\CanonicalTransaction;

/**
 * Payment-type hinter specialised for PayPal Activity Download CSV
 * exports (`source_format = 'paypal-csv'`).
 *
 * Keys off the canonical row's `rawPayload['events']` manifest the
 * `PaypalCsvAdapter` builds at parse time: the first event's `type`
 * field carries the authoritative PayPal event type (e.g.
 * `Express Checkout-betaling`, `Algemene valutaomrekening`,
 * `Bankstorting naar PP-rekening`). The hinter maps observed event
 * types — and a small forward-compatibility set of English literals
 * PayPal often leaves un-localised — to `PaymentType`.
 *
 * Recognised NL event types (current observed corpus):
 *
 *  - `Vooraf goedgekeurde betaling – rekening betaald door gebruiker` → Online
 *  - `Express Checkout-betaling` → Online
 *  - `Algemene valutaomrekening` → Fee (fx conversion is the only
 *    canonical row PayPal emits for a settled FX leg)
 *
 * Recognised EN forward-compatibility event types:
 *
 *  - `Payment Sent` / `Subscription Payment` / `Recurring Payment Sent`
 *    / `Pre-approved Payment` → Online
 *  - `Refund` / `Refund Sent` / `Cancelled Payment` → Refund
 *  - `Fee` / `Service Fee` / `PayPal Fee` → Fee
 *  - `User Initiated Withdrawal` / `General Withdrawal` /
 *    `Bank Deposit to PP Account` → Transfer
 *
 * Event types outside this allow-list return `null` so the
 * description-keyword fallback can take a shot. The adapter raises a
 * typed exception for genuinely-unmappable events at parse time so an
 * unmapped event here is by design forward-compatible noise rather
 * than a silent classification miss.
 *
 * Returns `null` when the row did not originate from a PayPal CSV
 * import or when no recognised event type appears in the manifest.
 *
 * Pure / stateless / singleton-safe — no constructor dependencies.
 */
final class PaypalCsvPaymentTypeHinter implements PaymentTypeHinter
{
    private const SOURCE_FORMAT = 'paypal-csv';

    /**
     * Event-type string → (`PaymentType`, confidence) mapping. Match
     * is done case-insensitively on the lower-cased event-type
     * literal so casing variants between NL / EN exports resolve
     * identically.
     *
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

    /**
     * Pulls the first event-type string from the PayPal manifest the
     * adapter persists into `rawPayload['events'][0]['type']`. Returns
     * null when the structure is missing or malformed — the classifier
     * then falls through to the description-keyword fallback.
     */
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
