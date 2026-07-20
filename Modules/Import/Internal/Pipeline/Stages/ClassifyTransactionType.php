<?php

declare(strict_types=1);

namespace Modules\Import\Internal\Pipeline\Stages;

use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\Import\Public\Contracts\ResolvesKnownCounterpartyIban;
use Modules\Ingestion\Public\Exceptions\MissingPaypalTransactionTypeMapException;
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
 *      `$tx->counterpartyIban` is non-null the stage consults the alias
 *      bridge first (Arm A — `ResolvesKnownCounterpartyIban` maps real
 *      institution IBANs such as PayPal SARL Luxembourg's
 *      `LU89751000135104200E` or ICS at ABN AMRO's
 *      `NL08ABNA0526650664` to the user's own synthetic-IBAN account of
 *      the matching kind). If the alias arm resolves an account that
 *      differs from the row's own account, the row is a transfer leg.
 *      Falling back to Arm B — literal own-account-IBAN equality —
 *      catches bank-to-bank transfers between two of the user's
 *      Account.iban rows that share the same literal IBAN convention
 *      (e.g. two ASN accounts). Sign-of-amount picks the direction:
 *      negative → transfer_out, positive → transfer_in. Both arms
 *      filter by `$user->id` so a counterparty IBAN that happens to
 *      belong to a different user's account never triggers a flip.
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
        private readonly ResolvesKnownCounterpartyIban $aliasResolver,
    ) {}

    public function run(CanonicalTransaction $tx, User $user): CanonicalTransaction
    {
        if (in_array($tx->type, ['refund', 'fee', 'adjustment'], true)) {
            return $tx;
        }

        // Two-arm cross-account-IBAN check, both scoped by $user->id:
        // the alias bridge (Arm A) maps real institution IBANs to the
        // user's synthetic-IBAN account; the literal own-IBAN match
        // (Arm B) catches transfers between two of the user's accounts.
        if ($tx->counterpartyIban !== null && $tx->counterpartyIban !== '') {
            // Arm A — alias bridge: real institution IBAN -> user's
            // own synthetic-IBAN account whose kind matches the alias.
            $aliasAccount = $this->aliasResolver->resolveAccount($tx->counterpartyIban, $user->id);
            if ($aliasAccount !== null && $aliasAccount->id !== $tx->accountId) {
                return $tx->withType($tx->amountMinor < 0 ? 'transfer_out' : 'transfer_in');
            }

            // Arm B — literal own-IBAN match: catches bank-to-bank
            // transfers between two of the user's own accounts.
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
                } catch (MissingPaypalTransactionTypeMapException $missing) {
                    // Code-internal inconsistency (MAP entry with no
                    // TRANSACTION_TYPE) — re-throw so it fails loudly
                    // at parse time. Extends UnknownPaypalEventTypeException,
                    // so this narrower catch MUST come before that one.
                    throw $missing;
                } catch (UnknownPaypalEventTypeException) {
                    // Unmapped event type is a user-data condition, not
                    // a bug (the adapter already raised at parse time
                    // for genuinely-unmappable events) — fall through
                    // to the amount-sign default rather than aborting.
                }
            }
        }

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
