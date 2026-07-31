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
use Modules\Ledger\Public\Enums\TransactionType;

/**
 * @link ../../../../../.docs/architecture/ingestion-pipeline.md#4-transaction-type-classification-classifytransactiontype
 */
final class ClassifyTransactionType
{
    private const PAYPAL_FORMAT = 'paypal-csv';

    // Types NormalizeStage already settled that classification must not
    // override, plus the transfer legs that exclude a row from the
    // amount-sign income default.
    private const TERMINAL_TYPES = [TransactionType::Refund, TransactionType::Fee, TransactionType::Adjustment];

    private const NON_INCOME_TYPES = [
        TransactionType::TransferIn,
        TransactionType::TransferOut,
        TransactionType::Refund,
        TransactionType::Fee,
    ];

    public function __construct(
        private readonly PaypalCsvEventTypeMap $eventTypes,
        private readonly DatabaseManager $db,
        private readonly ResolvesKnownCounterpartyIban $aliasResolver,
    ) {}

    public function run(CanonicalTransaction $tx, User $user): CanonicalTransaction
    {
        if (in_array(TransactionType::tryFrom($tx->type), self::TERMINAL_TYPES, true)) {
            return $tx;
        }

        $resolved = $this->transferType($tx, $user)
            ?? $this->paypalType($tx)
            ?? $this->incomeType($tx);

        return $resolved === null ? $tx : $tx->withType($resolved);
    }

    // Two-arm cross-account-IBAN check, both scoped by $user->id: the
    // alias bridge (Arm A) maps real institution IBANs to the user's
    // synthetic-IBAN account; the literal own-IBAN match (Arm B) catches
    // transfers between two of the user's own accounts.
    private function transferType(CanonicalTransaction $tx, User $user): ?string
    {
        if ($tx->counterpartyIban === null || $tx->counterpartyIban === '') {
            return null;
        }

        $aliasAccount = $this->aliasResolver->resolveAccount($tx->counterpartyIban, $user->id);
        $bridged = $aliasAccount !== null && $aliasAccount->id !== $tx->accountId;

        if (! $bridged && ! $this->matchesOwnAccount($tx, $user)) {
            return null;
        }

        return ($tx->amountMinor < 0 ? TransactionType::TransferOut : TransactionType::TransferIn)->value;
    }

    private function matchesOwnAccount(CanonicalTransaction $tx, User $user): bool
    {
        return $this->db->connection()
            ->table('accounts')
            ->where('user_id', $user->id)
            ->where('iban', $tx->counterpartyIban)
            ->where('id', '!=', $tx->accountId)
            ->count() > 0;
    }

    private function paypalType(CanonicalTransaction $tx): ?string
    {
        $rawPayload = $tx->rawPayload;
        if (! is_array($rawPayload) || ($rawPayload['format'] ?? null) !== self::PAYPAL_FORMAT) {
            return null;
        }

        $parentEventType = self::firstEventType($rawPayload);
        $language = $rawPayload['language'] ?? null;
        if ($parentEventType === null || ! is_string($language) || $language === '') {
            return null;
        }

        return $this->mapPaypalEvent($parentEventType, $language);
    }

    // Unmapped event types are user data, not bugs (the adapter already
    // raised at parse time for genuinely-unmappable events), so they fall
    // through to null. A MissingPaypalTransactionTypeMapException is a
    // code-internal inconsistency and re-throws loudly.
    private function mapPaypalEvent(string $parentEventType, string $language): ?string
    {
        try {
            return $this->eventTypes->transactionType($parentEventType, $language);
        } catch (MissingPaypalTransactionTypeMapException $missing) {
            throw $missing;
        } catch (UnknownPaypalEventTypeException) {
            return null;
        }
    }

    private function incomeType(CanonicalTransaction $tx): ?string
    {
        if ($tx->amountMinor > 0 && ! in_array(TransactionType::tryFrom($tx->type), self::NON_INCOME_TYPES, true)) {
            return TransactionType::Income->value;
        }

        return null;
    }

    /**
     * @param  array<mixed>  $rawPayload
     */
    private static function firstEventType(array $rawPayload): ?string
    {
        $events = $rawPayload['events'] ?? null;
        if (! is_array($events) || $events === []) {
            return null;
        }

        $firstEvent = $events[array_key_first($events)] ?? null;
        if (! is_array($firstEvent) || ! isset($firstEvent['type']) || ! is_string($firstEvent['type']) || $firstEvent['type'] === '') {
            return null;
        }

        return $firstEvent['type'];
    }
}
