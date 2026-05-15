<?php

declare(strict_types=1);

namespace Modules\Import\Internal\Pipeline\Stages;

use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\Ingestion\Public\Exceptions\UnknownPaypalEventTypeException;
use Modules\Ingestion\Public\Paypal\PaypalCsvEventTypeMap;
use Modules\Ledger\Public\Dto\CanonicalTransaction;

/**
 * Pipeline stage that resolves a canonical row's `type` from the
 * combination of source-adapter pre-classification, the user's account
 * graph, and the PayPal event-type map. Sits between NormalizeStage and
 * FingerprintStage; runs once per canonical row before the row is
 * fingerprinted or persisted.
 *
 * The stage is a pure pre-load transformer: it never queries the
 * `transactions` table, never mutates `pair_transaction_id`, and never
 * re-types rows the adapter already classified as `refund` / `fee` /
 * `adjustment`.
 *
 * Algorithm:
 *
 *   1. Preserve already-classified rows. `refund` / `fee` / `adjustment`
 *      come back unchanged — they are pre-classified by the adapter
 *      (PayPal Refund event type, ASN refund mapper) and downstream
 *      pair-detection has no opinion on them.
 *
 *   2. Cross-account-IBAN check (universal across source formats). When
 *      `$tx->counterpartyIban` is non-null AND matches one of the user's
 *      OWN Account.iban rows (excluding the row's own account), the row
 *      is a transfer leg. Sign-of-amount picks the direction: negative →
 *      transfer_out, positive → transfer_in. The Account query filters
 *      by `$user->id` so a counterparty IBAN that happens to belong to a
 *      different user's account never triggers a flip.
 *
 *   3. PayPal source-format event-type map. Fires when
 *      `rawPayload.format === 'paypal-csv'`. Reads the FIRST event's
 *      `type` from `rawPayload.events`, looks up via
 *      `PaypalCsvEventTypeMap::transactionType()`, and applies the
 *      resulting `Transaction::TYPES` value if it doesn't collide with a
 *      transfer (step 2 takes precedence — a PayPal General Withdrawal
 *      whose counterparty IBAN matches a known own ASN account becomes
 *      `transfer_out` regardless). An unmapped parent event type catches
 *      the typed exception and falls through to step 4.
 *
 *   4. Subtractive income rule. Positive amount AND type not in
 *      {transfer_in, transfer_out, refund, fee} → income. Cheap and
 *      deterministic; counterparty-heuristic salary detection is a
 *      future concern.
 *
 *   5. Default. Return the row unchanged so NormalizeStage's amount-sign
 *      default (negative → expense) survives.
 *
 * Cross-user safety: every Account lookup filters on `$user->id`. The
 * stage is a single read against `accounts`; it does NOT query
 * `transactions` — listener-side concerns stay listener-side.
 */
final class ClassifyTransactionType
{
    public function __construct(
        private readonly PaypalCsvEventTypeMap $eventTypes,
        private readonly DatabaseManager $db,
    ) {}

    public function run(CanonicalTransaction $tx, User $user): CanonicalTransaction
    {
        // Step 1 — preserve pre-classified rows.
        if (in_array($tx->type, ['refund', 'fee', 'adjustment'], true)) {
            return $tx;
        }

        // Step 2 — cross-account-IBAN check (every source format).
        //
        // Raw Query Builder count() (rather than Eloquent's static
        // exists()) keeps Larastan strict happy with the same
        // staticMethod.dynamicCall posture PreviewWizard::needsIcsAccountName
        // uses for the same predicate shape.
        if ($tx->counterpartyIban !== null && $tx->counterpartyIban !== '') {
            $isOwnAccount = $this->db->connection()
                ->table('accounts')
                ->where('user_id', $user->id)
                ->where('iban', $tx->counterpartyIban)
                ->where('id', '!=', $tx->accountId)
                ->count() > 0;
            if ($isOwnAccount) {
                return $tx->withType($tx->amountMinor < 0 ? 'transfer_out' : 'transfer_in');
            }
        }

        // Step 3 — PayPal source-format event-type map.
        $rawPayload = $tx->rawPayload;
        if (is_array($rawPayload) && ($rawPayload['format'] ?? null) === 'paypal-csv') {
            $events = $rawPayload['events'] ?? null;
            $parentEventType = null;
            if (is_array($events) && $events !== []) {
                $firstEvent = $events[array_key_first($events)] ?? null;
                if (is_array($firstEvent) && isset($firstEvent['type']) && is_string($firstEvent['type'])) {
                    $parentEventType = $firstEvent['type'];
                }
            }
            $language = $rawPayload['language'] ?? null;
            if (
                is_string($parentEventType)
                && $parentEventType !== ''
                && is_string($language)
                && $language !== ''
            ) {
                try {
                    $mappedType = $this->eventTypes->transactionType($parentEventType, $language);

                    return $tx->withType($mappedType);
                } catch (UnknownPaypalEventTypeException) {
                    // Unknown parent event type OR missing TRANSACTION_TYPE
                    // mapping — fall through to the subtractive default.
                    // The adapter would have raised a typed exception at
                    // parse time when the event was genuinely unmappable;
                    // here we treat unmapped parent types as "use the
                    // amount-sign default" rather than hard-aborting the
                    // import.
                }
            }
        }

        // Step 4 — subtractive income rule.
        if (
            $tx->amountMinor > 0
            && ! in_array($tx->type, ['transfer_in', 'transfer_out', 'refund', 'fee'], true)
        ) {
            return $tx->withType('income');
        }

        // Step 5 — default: keep NormalizeStage's amount-sign-derived
        // default (negative → expense, zero → adjustment).
        return $tx;
    }
}
