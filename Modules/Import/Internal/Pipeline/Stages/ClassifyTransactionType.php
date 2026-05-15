<?php

declare(strict_types=1);

namespace Modules\Import\Internal\Pipeline\Stages;

use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\Ingestion\Public\Paypal\PaypalCsvEventTypeMap;
use Modules\Ledger\Public\Dto\CanonicalTransaction;
use Throwable;

/**
 * Decouples typing from pairing per D-76 / Pitfall 3.
 *
 * Sits between NormalizeStage and FingerprintStage in the pipeline; runs
 * once per canonical row before the row is fingerprinted, classified, or
 * persisted. The stage is a pure pre-load transformer: it NEVER queries
 * the `transactions` table, NEVER mutates `pair_transaction_id`, and
 * NEVER re-types rows the adapter already classified as `refund` /
 * `fee` / `adjustment`.
 *
 * Algorithm (verbatim from RESEARCH.md Example 3 / D-76 / D-77):
 *
 *   1. Preserve already-classified rows
 *      ─ `refund` / `fee` / `adjustment` → returned unchanged. These
 *        are pre-classified by the adapter (PayPal Refund event type,
 *        ASN refund mapper) and the pair-detection listener has no
 *        opinion on them.
 *
 *   2. Cross-account-IBAN check (UNIVERSAL — every source format)
 *      ─ When `$tx->counterpartyIban` is non-null AND matches one of
 *        the user's OWN Account.iban rows (excluding the row's own
 *        account), the row is a transfer leg. Sign-of-amount picks the
 *        direction: negative → transfer_out, positive → transfer_in.
 *      ─ The Account query filters by `$user->id` so a counterparty
 *        IBAN that happens to belong to a different user's account
 *        never triggers a flip (T-04-W2-01 mitigation).
 *
 *   3. PayPal source-format event-type map
 *      ─ Only fires when `rawPayload.format === 'paypal-csv'`. Reads
 *        the FIRST event's `type` from `rawPayload.events`, looks up
 *        via `PaypalCsvEventTypeMap::transactionType()`, and applies
 *        the resulting `Transaction::TYPES` value if it doesn't
 *        collide with a transfer (step 2 takes precedence — a PayPal
 *        General Withdrawal whose counterparty IBAN matches a known
 *        own ASN account becomes `transfer_out` regardless).
 *      ─ An unknown parent event type catches the typed exception and
 *        falls through to step 4 — the adapter would already have
 *        raised an UnknownPaypalEventTypeException at parse time when
 *        the event truly cannot be classified.
 *
 *   4. Subtractive income detector (D-77)
 *      ─ Positive amount AND type not in {transfer_in, transfer_out,
 *        refund, fee} → income. The detector is intentionally cheap;
 *        counterparty-heuristic salary detection is a future phase.
 *
 *   5. Default
 *      ─ Return the row unchanged so NormalizeStage's amount-sign
 *        default (negative → expense) survives.
 *
 * Cross-user safety: every Account lookup filters on `$user->id`
 * (T-04-W2-01). The stage is a single read against `accounts`; it does
 * NOT query `transactions` (Pitfall 3 — listener-side concerns stay
 * listener-side).
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
                } catch (Throwable) {
                    // Unknown parent event type — fall through to the
                    // subtractive default. The adapter would have raised
                    // a typed exception at parse time when the event was
                    // genuinely unmappable; here we treat unknown parent
                    // types as "use the amount-sign default" rather than
                    // hard-aborting the import.
                }
            }
        }

        // Step 4 — subtractive income detector (D-77).
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
