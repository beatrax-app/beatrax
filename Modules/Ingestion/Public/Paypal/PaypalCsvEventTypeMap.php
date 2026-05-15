<?php

declare(strict_types=1);

namespace Modules\Ingestion\Public\Paypal;

use Modules\Ingestion\Public\Exceptions\MissingPaypalTransactionTypeMapException;
use Modules\Ingestion\Public\Exceptions\UnknownPaypalEventTypeException;

/**
 * Empirical event-type → canonical-action map for PayPal Activity
 * Download CSV rows.
 *
 * Action vocabulary (D-62):
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
 *                   the dual-amount pair (D-63).
 *
 * Map entries are language-keyed; the adapter looks up via the
 * detected language code from `PaypalCsvLanguageProfile::detected()`.
 *
 * Wave 0 entries (locked from `04-WAVE-0-FINDINGS.md` (b)):
 *
 *   nl → 5 observed event types + 4 EN forward-compatible skips for
 *        Hold / Authorization / Reserve / Reversal. The EN skips
 *        cover the case where PayPal hasn't localised those strings
 *        (empirically common — `Reference Txn ID` is also un-localised
 *        in the user's NL export); when a localised NL form first
 *        appears in a future export, the entry gets a CONTEXT.md
 *        addendum + a fixture extension.
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
            // Observed in the Wave 0 fixture
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
            // Both observed parent forms in the Wave 0 fixture map to
            // 'expense'. Wave 0 surfaced no refund / withdrawal /
            // transfer rows; their NL strings are deferred until
            // empirically observed (see 04-WAVE-0-FINDINGS.md
            // "Deferred / future-event-type-coverage").
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
