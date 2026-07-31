<?php

declare(strict_types=1);

namespace Modules\Ingestion\Public\Paypal;

use Modules\Ingestion\Public\Exceptions\MissingPaypalTransactionTypeMapException;
use Modules\Ingestion\Public\Exceptions\UnknownPaypalEventTypeException;

/**
 * @link ../../../../.docs/features/ingestion/architecture.md
 */
final class PaypalCsvEventTypeMap
{
    /**
     * @var array<string, array<string, string>>
     */
    private const MAP = [
        'nl' => [
            'Vooraf goedgekeurde betaling – rekening betaald door gebruiker' => 'parent',
            'Express Checkout-betaling' => 'parent',
            'Bankstorting naar PP-rekening' => 'child-fee',
            'Algemene kaartstorting' => 'child-fee',
            'Algemene valutaomrekening' => 'child-fx',

            // Funding-leg parents: standalone top-up ASN->PayPal events,
            // distinct from the child-fee entry above; PayPal ships these
            // three strings un-localised, sharing vocabulary with
            // PaypalFundingResolver::FUNDING_EVENT_TYPES.
            'Bankstorting' => 'parent',
            'General Withdrawal' => 'parent',
            'Transfer to bank' => 'parent',

            // EN forms PayPal often leaves un-localised in NL exports; only
            // registered under 'nl', so an English-only export would raise
            // UnknownPaypalEventTypeException for these same strings.
            'Hold' => 'skip',
            'Authorization' => 'skip',
            'Reserve' => 'skip',
            'Reversal of General Account Hold' => 'skip',
        ],
    ];

    /**
     * @var array<string, array<string, string>>
     */
    private const TRANSACTION_TYPE = [
        'nl' => [
            // Only 'parent' event types appear here; children enrich their
            // parent rather than owning a canonical type of their own.
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
        // Absent from MAP is a user-data condition (broader exception);
        // present in MAP but missing from TRANSACTION_TYPE is a
        // code-internal inconsistency (narrower exception below).
        if (! isset(self::MAP[$language][$eventType])) {
            throw new UnknownPaypalEventTypeException(
                "PayPal CSV parent event type '{$eventType}' for language '{$language}' is not catalogued in PaypalCsvEventTypeMap::MAP. PayPal may have shipped a new event-type string — file an issue with the redacted CSV."
            );
        }

        if (! isset(self::TRANSACTION_TYPE[$language][$eventType])) {
            throw new MissingPaypalTransactionTypeMapException(
                "PayPal CSV parent event type '{$eventType}' for language '{$language}' has no TransactionType mapping. This is a code-internal inconsistency: every event type classified as 'parent' in MAP must have a corresponding TRANSACTION_TYPE entry."
            );
        }

        return self::TRANSACTION_TYPE[$language][$eventType];
    }
}
