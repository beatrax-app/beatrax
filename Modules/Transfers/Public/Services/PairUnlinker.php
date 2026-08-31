<?php

declare(strict_types=1);

namespace Modules\Transfers\Public\Services;

use Illuminate\Database\DatabaseManager;
use Modules\Core\Public\Concerns\CoercesScalars;
use Modules\Ledger\Public\Contracts\UnpairsTransferLegs;
use Modules\Ledger\Public\Enums\TransactionType;

// The retype rule used to live only in the Sync merge replay, so on a
// single-device install the survivor of a deleted transfer kept type
// transfer_out with a null pair and the dashboard went on netting it out.
final readonly class PairUnlinker implements UnpairsTransferLegs
{
    use CoercesScalars;

    public function __construct(
        private DatabaseManager $db,
    ) {}

    public function unpair(int $userId, int $survivorId, TransactionType $deletedType): ?TransactionType
    {
        if (! $deletedType->isTransfer()) {
            return null;
        }

        // whereNull is the guard, not a filter: a survivor something has
        // already re-paired must keep its transfer type.
        $survivor = $this->db->connection()
            ->table('transactions')
            ->where('id', $survivorId)
            ->where('user_id', $userId)
            ->whereNull('pair_transaction_id')
            ->first(['type', 'amount_minor']);

        $newType = $survivor === null
            ? null
            : self::survivorTypeFor($survivor->type ?? null, self::toInt($survivor->amount_minor ?? null));

        if ($newType === null) {
            return null;
        }

        $affected = $this->db->connection()
            ->table('transactions')
            ->where('id', $survivorId)
            ->where('user_id', $userId)
            ->whereNull('pair_transaction_id')
            ->update(['type' => $newType->value]);

        return $affected > 0 ? $newType : null;
    }

    // A leg whose partner is gone is no longer money moving between two of
    // your own accounts — it is money that arrived or left, and only its own
    // amount says which: PaypalCsvEventTypeMap types a withdrawal transfer_in
    // on both sides, so the deleted leg's type does not name the survivor's.
    private static function survivorTypeFor(mixed $survivorType, int $survivorAmountMinor): ?TransactionType
    {
        $currentType = TransactionType::tryFrom(self::toStringOrNull($survivorType) ?? '');
        if ($currentType === null || ! $currentType->isTransfer()) {
            return null;
        }

        return $survivorAmountMinor < 0 ? TransactionType::Expense : TransactionType::Income;
    }
}
