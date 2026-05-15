<?php

declare(strict_types=1);

namespace Modules\Ingestion\Public\Paypal;

use Modules\Ingestion\Public\Exceptions\MissingPaypalTransactionTypeMapException;
use Modules\Ingestion\Public\Exceptions\UnknownPaypalEventTypeException;

/**
 * Event-type → canonical-action map for PayPal Activity Download CSV
 * rows.
 *
 * Action vocabulary:
 *
 *   - 'skip'      — row is dropped at the adapter boundary
 *                   (Hold / Authorization / Reserve / Reversal of
 *                   General Account Hold). Counted via
 *                   `import_runs.extras.skippedHoldCount`.
 *   - 'parent'    — row creates a canonical Transaction. The
 *                   `transactionType()` method then resolves to the
 *                   `Transaction::TYPES` enum value.
 *   - 'child-fee' — row rides under its parent's rawPayload manifest
 *                   as a funding-source / fee enrichment, never
 *                   becomes its own canonical row.
 *   - 'child-fx'  — row rides under its parent's rawPayload manifest
 *                   and contributes the EUR-leg or non-EUR-leg of
 *                   the dual-amount pair.
 *
 * Map entries are language-keyed; the adapter looks up via the
 * detected language code from `PaypalCsvLanguageProfile::detected()`.
 *
 * The NL entries cover the five observed event types plus four EN
 * forward-compatible skips for Hold / Authorization / Reserve /
 * Reversal — PayPal frequently ships those strings un-localised
 * (`Reference Txn ID` is similarly un-localised in NL exports).
 *
 * Any event type encountered that is NOT in the map raises
 * `UnknownPaypalEventTypeException` — silent mis-classification is
 * impossible.
 */
final class PaypalCsvEventTypeMap
{
    /**
     * Event-type → canonical action map.
     *
     * @var array<string, array<string, string>>
     */
    private const MAP = [
        'nl' => [
            // Observed event types in the NL Activity Download.
            'Vooraf goedgekeurde betaling – rekening betaald door gebruiker' => 'parent',
            'Express Checkout-betaling' => 'parent',
            'Bankstorting naar PP-rekening' => 'child-fee',
            'Algemene kaartstorting' => 'child-fee',
            'Algemene valutaomrekening' => 'child-fx',

            // Forward-compatibility — EN forms that PayPal often
            // leaves un-localised. Filtered at the adapter boundary.
            'Hold' => 'skip',
            'Authorization' => 'skip',
            'Reserve' => 'skip',
            'Reversal of General Account Hold' => 'skip',
        ],
    ];

    /**
     * Parent-event-type → `Transaction::TYPES` enum value.
     *
     * Only `parent` event types appear here. Children never own a
     * canonical type — they enrich their parent.
     *
     * @var array<string, array<string, string>>
     */
    private const TRANSACTION_TYPE = [
        'nl' => [
            // Both observed parent event types map to 'expense'.
            // Refund / withdrawal / transfer NL strings will be added
            // here when their corresponding parent event types are
            // observed in a real export.
            'Vooraf goedgekeurde betaling – rekening betaald door gebruiker' => 'expense',
            'Express Checkout-betaling' => 'expense',
        ],
    ];

    public function classify(string $eventType, string $language): string
    {
        if (! isset(self::MAP[$language][$eventType])) {
            throw new UnknownPaypalEventTypeException(
                "PayPal CSV uses an unrecognised event type '{$eventType}' for language '{$language}'. This event type isn't yet mapped — file an issue with the redacted CSV."
            );
        }

        return self::MAP[$language][$eventType];
    }

    public function transactionType(string $eventType, string $language): string
    {
        if (! isset(self::TRANSACTION_TYPE[$language][$eventType])) {
            throw new MissingPaypalTransactionTypeMapException(
                "PayPal CSV parent event type '{$eventType}' for language '{$language}' has no Transaction::TYPES mapping. This is a code-internal inconsistency: every event type classified as 'parent' in MAP must have a corresponding TRANSACTION_TYPE entry."
            );
        }

        return self::TRANSACTION_TYPE[$language][$eventType];
    }
}
