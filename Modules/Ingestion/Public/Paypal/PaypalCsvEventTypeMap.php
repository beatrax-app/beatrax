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
 * The NL entries cover the observed expense / child-event types AND
 * the three funding-leg parent event types (`Bankstorting`,
 * `General Withdrawal`, `Transfer to bank`) that share their vocabulary
 * with `PaypalFundingResolver::FUNDING_EVENT_TYPES`. The funding-leg
 * trio classifies as `parent` and resolves to `transfer_in`, so an
 * ASN→PayPal top-up surfaces as the PayPal-side `transfer_in` leg that
 * `PairTransferCandidates` matches against the ASN-side
 * `transfer_out`. Four EN forward-compatible skips for Hold /
 * Authorization / Reserve / Reversal stay un-localised — PayPal
 * frequently ships those strings in English regardless of locale.
 *
 * The standalone `Bankstorting` parent and the localised `Bankstorting
 * naar PP-rekening` child are two distinct PayPal event-type literals:
 * the parent is a direct top-up canonical row, the child is a funding-
 * source enrichment that rides under a purchase parent in the rollup.
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

            // Funding-leg parents — standalone top-up ASN→PayPal events.
            // Distinct from the `Bankstorting naar PP-rekening` child-fee
            // entry above; PayPal ships these three strings un-localised
            // and they share their vocabulary with
            // `PaypalFundingResolver::FUNDING_EVENT_TYPES`.
            'Bankstorting' => 'parent',
            'General Withdrawal' => 'parent',
            'Transfer to bank' => 'parent',

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
            // Both observed purchase-parent event types map to 'expense'.
            // Refund NL strings will be added when their corresponding
            // parent event types are observed in a real export.
            'Vooraf goedgekeurde betaling – rekening betaald door gebruiker' => 'expense',
            'Express Checkout-betaling' => 'expense',

            // Funding-leg parents map to 'transfer_in' so the PayPal
            // side of an ASN→PayPal top-up surfaces as the transfer leg
            // `PairTransferCandidates` matches against the ASN-side
            // `transfer_out`.
            'Bankstorting' => 'transfer_in',
            'General Withdrawal' => 'transfer_in',
            'Transfer to bank' => 'transfer_in',
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
        // Two distinct miss conditions, two distinct exception types.
        //
        //  - Event type not present in MAP at all (or unknown
        //    language): a user-data condition — PayPal has shipped an
        //    event-type string we have not catalogued yet. Raise the
        //    broader UnknownPaypalEventTypeException so callers that
        //    want to fall through to the amount-sign default can
        //    catch it.
        //
        //  - Event type present in MAP (as `parent`) but missing
        //    from TRANSACTION_TYPE: a code-internal inconsistency —
        //    the two tables must stay in lock-step. Raise the
        //    narrower MissingPaypalTransactionTypeMapException so
        //    the catch-site surfaces the developer bug at parse
        //    time rather than silently falling through.
        if (! isset(self::MAP[$language][$eventType])) {
            throw new UnknownPaypalEventTypeException(
                "PayPal CSV parent event type '{$eventType}' for language '{$language}' is not catalogued in PaypalCsvEventTypeMap::MAP. PayPal may have shipped a new event-type string — file an issue with the redacted CSV."
            );
        }

        if (! isset(self::TRANSACTION_TYPE[$language][$eventType])) {
            throw new MissingPaypalTransactionTypeMapException(
                "PayPal CSV parent event type '{$eventType}' for language '{$language}' has no Transaction::TYPES mapping. This is a code-internal inconsistency: every event type classified as 'parent' in MAP must have a corresponding TRANSACTION_TYPE entry."
            );
        }

        return self::TRANSACTION_TYPE[$language][$eventType];
    }
}
