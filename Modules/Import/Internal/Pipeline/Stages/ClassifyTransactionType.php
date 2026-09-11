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
use Modules\Ledger\Public\Enums\AccountKind;
use Modules\Ledger\Public\Enums\TransactionType;

/**
 * @link ../../../../../.docs/architecture/ingestion-pipeline.md#4-transaction-type-classification-classifytransactiontype
 */
final readonly class ClassifyTransactionType
{
    private const string PAYPAL_FORMAT = 'paypal-csv';

    // Types NormalizeStage already settled, plus the transfer legs that are
    // excluded from the amount-sign income default.
    private const array TERMINAL_TYPES = [TransactionType::Refund, TransactionType::Fee, TransactionType::Adjustment];

    private const array NON_INCOME_TYPES = [
        TransactionType::TransferIn,
        TransactionType::TransferOut,
        TransactionType::Refund,
        TransactionType::Fee,
    ];

    public function __construct(
        private PaypalCsvEventTypeMap $eventTypes,
        private DatabaseManager $db,
        private ResolvesKnownCounterpartyIban $aliasResolver,
    ) {}

    public function run(CanonicalTransaction $tx, User $user): CanonicalTransaction
    {
        if (in_array(TransactionType::tryFrom($tx->type), self::TERMINAL_TYPES, true)) {
            return $tx;
        }

        $resolved = $this->transferType($tx, $user)
            ?? $this->paypalType($tx)
            ?? $this->incomeType($tx, $user);

        return $resolved === null ? $tx : $tx->withType($resolved);
    }

    // Two arms, both scoped by $user->id: the alias bridge maps a real
    // institution IBAN onto the user's synthetic-IBAN account, and the
    // literal match catches transfers between two of the user's accounts.
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

        return $this->mapPaypalEvent($parentEventType, $language)?->value;
    }

    // An unmapped event type is user data, not a bug — the adapter already
    // raised at parse time for the genuinely unmappable — so it falls
    // through to null. A missing map entry is ours, and re-throws.
    private function mapPaypalEvent(string $parentEventType, string $language): ?TransactionType
    {
        try {
            return $this->eventTypes->transactionType($parentEventType, $language);
        } catch (MissingPaypalTransactionTypeMapException $missing) {
            throw $missing;
        } catch (UnknownPaypalEventTypeException) {
            return null;
        }
    }

    private function incomeType(CanonicalTransaction $tx, User $user): ?string
    {
        if ($tx->amountMinor <= 0 || in_array(TransactionType::tryFrom($tx->type), self::NON_INCOME_TYPES, true)) {
            return null;
        }

        // A card balance is what is OWED, so money arriving on one is the
        // reader paying it down — the other half of a transfer their bank
        // already booked — and never earnings. Read as income, one card
        // statement's settlement was a second salary every month.
        return $this->liabilityAccount($tx->accountId, $user)
            ? TransactionType::TransferIn->value
            : TransactionType::Income->value;
    }

    private function liabilityAccount(int $accountId, User $user): bool
    {
        $kind = $this->db->connection()
            ->table('accounts')
            ->where('user_id', $user->id)
            ->where('id', $accountId)
            ->value('kind');

        return is_string($kind) && AccountKind::tryFrom($kind)?->isLiability() === true;
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

        $firstEvent = array_first($events) ?? null;
        if (! is_array($firstEvent) || ! isset($firstEvent['type']) || ! is_string($firstEvent['type']) || $firstEvent['type'] === '') {
            return null;
        }

        return $firstEvent['type'];
    }
}
