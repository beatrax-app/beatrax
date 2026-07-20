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
 * @link ../../../../../.docs/architecture/ingestion-pipeline.md#4-transaction-type-classification-classifytransactiontype
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
