<?php

declare(strict_types=1);

namespace Modules\Ingestion\Public\Paypal;

use Modules\Ingestion\Public\Exceptions\MissingPaypalTransactionTypeMapException;
use Modules\Ingestion\Public\Exceptions\OrphanedPaypalChildRowException;
use Modules\Ingestion\Public\Exceptions\UnknownPaypalEventTypeException;
use Modules\Ledger\Public\Enums\TransactionType;

/**
 * @link ../../../../.docs/features/import/paypal-funding-legs.md
 */
final class PaypalCsvEventTypeMap
{
    /**
     * @var array<string, array<string, PaypalEventAction>>
     */
    private const array MAP = [
        'nl' => [
            'Vooraf goedgekeurde betaling – rekening betaald door gebruiker' => PaypalEventAction::Parent,
            'Express Checkout-betaling' => PaypalEventAction::Parent,
            'Algemene valutaomrekening' => PaypalEventAction::ChildFx,

            // Per-purchase funding legs: the reader's own money entering PayPal
            // to settle the parent row. Folding them into the parent as
            // children discarded them, and the bank-side debit then stood
            // alone — the same euros counted twice.
            'Bankstorting naar PP-rekening' => PaypalEventAction::Parent,
            'Algemene kaartstorting' => PaypalEventAction::Parent,

            // Standalone ASN->PayPal top-up parents. PayPal ships these three
            // un-localised, and they must stay in step with
            // PaypalFundingResolver::FUNDING_EVENT_TYPES.
            'Bankstorting' => PaypalEventAction::Parent,
            'General Withdrawal' => PaypalEventAction::Parent,
            'Transfer to bank' => PaypalEventAction::Parent,

            // EN forms PayPal often leaves un-localised in NL exports; only
            // registered under 'nl', so an English-only export would raise
            // UnknownPaypalEventTypeException for these same strings.
            'Hold' => PaypalEventAction::Skip,
            'Authorization' => PaypalEventAction::Skip,
            'Reserve' => PaypalEventAction::Skip,
            'Reversal of General Account Hold' => PaypalEventAction::Skip,
        ],
    ];

    /**
     * @var array<string, array<string, TransactionType>>
     */
    private const array TRANSACTION_TYPE = [
        'nl' => [
            // Only Parent event types appear here; a child enriches its parent
            // rather than owning a canonical type of its own.
            'Vooraf goedgekeurde betaling – rekening betaald door gebruiker' => TransactionType::Expense,
            'Express Checkout-betaling' => TransactionType::Expense,

            // TransferIn so PairTransferCandidates can match the PayPal side of
            // a funding leg or top-up against the bank-side transfer_out.
            'Bankstorting naar PP-rekening' => TransactionType::TransferIn,
            'Algemene kaartstorting' => TransactionType::TransferIn,
            'Bankstorting' => TransactionType::TransferIn,
            'General Withdrawal' => TransactionType::TransferIn,
            'Transfer to bank' => TransactionType::TransferIn,
        ],
    ];

    public function classify(string $eventType, string $language): PaypalEventAction
    {
        if (! isset(self::MAP[$language][$eventType])) {
            throw new UnknownPaypalEventTypeException(
                "PayPal CSV uses an unrecognised event type '{$eventType}' for language '{$language}'. This event type isn't yet mapped — file an issue with the redacted CSV."
            );
        }

        return self::MAP[$language][$eventType];
    }

    public function transactionType(string $eventType, string $language): TransactionType
    {
        // Absent from MAP is a user-data condition (broader exception);
        // present in MAP but missing from TRANSACTION_TYPE is a
        // code-internal inconsistency (narrower exception below).
        if (! isset(self::MAP[$language][$eventType])) {
            throw new UnknownPaypalEventTypeException(
                "PayPal CSV parent event type '{$eventType}' for language '{$language}' is not catalogued in PaypalCsvEventTypeMap::MAP. PayPal may have shipped a new event-type string — file an issue with the redacted CSV."
            );
        }

        // A child only reaches here after the walker promoted it for want of
        // the parent its Reference Txn ID names. That is the reader's file
        // being split, not our table being wrong, and it says so.
        if (self::MAP[$language][$eventType]->isChild()) {
            throw new OrphanedPaypalChildRowException($eventType);
        }

        if (! isset(self::TRANSACTION_TYPE[$language][$eventType])) {
            throw new MissingPaypalTransactionTypeMapException(
                "PayPal CSV parent event type '{$eventType}' for language '{$language}' has no TransactionType mapping. This is a code-internal inconsistency: every event type classified as Parent in MAP must have a corresponding TRANSACTION_TYPE entry."
            );
        }

        return self::TRANSACTION_TYPE[$language][$eventType];
    }
}
