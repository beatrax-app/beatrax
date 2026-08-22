<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Merge;

use Illuminate\Database\DatabaseManager;
use Modules\Ledger\Public\Enums\TransactionType;
use Modules\Sync\Internal\OpLog\OpLogEntry;
use Modules\Sync\Internal\OpLog\OpType;

// What happens to the surviving half of a transfer when the other half is
// deleted. A transfer only means anything as a pair, so the partner stops
// being a transfer and becomes plain income or expense — and that has to be
// recorded as a real op, or a rebuild would quietly revert it.
final readonly class TransferPairCascade
{
    public function __construct(
        private DatabaseManager $db,
    ) {}

    // Captured BEFORE the delete, because ON DELETE SET NULL on
    // pair_transaction_id leaves the partner with a null link and nothing left
    // to identify it by. Non-transfer or unpaired rows collect nothing.
    /**
     * @param  list<array{partnerId: int, deletedType: string, tombHlcL: int, tombHlcC: int}>  $pairCascades
     */
    public function collect(string $table, int|string $pk, OpLogEntry $tomb, int $userId, array &$pairCascades): void
    {
        if ($table !== 'transactions') {
            return;
        }

        $txRow = $this->db->connection()
            ->table('transactions')
            ->where('id', $pk)
            ->where('user_id', $userId)
            ->first();

        if ($txRow === null) {
            return;
        }

        $txType = is_string($txRow->type ?? null) ? $txRow->type : null;
        $pairId = is_numeric($txRow->pair_transaction_id ?? null)
            ? (int) $txRow->pair_transaction_id
            : null;

        if ($pairId !== null && in_array($txType, TransactionType::transferValues(), true)) {
            $pairCascades[] = [
                'partnerId' => $pairId,
                'deletedType' => $txType,
                'tombHlcL' => $tomb->hlcL,
                'tombHlcC' => $tomb->hlcC,
            ];
        }
    }

    // Runs after the merge transaction commits, so the pair link is already
    // null on the rows that lost their partner.
    /**
     * @param  list<array{partnerId: int, deletedType: string, tombHlcL: int, tombHlcC: int}>  $pairCascades
     * @param  list<int>  $touchedTransactionIds
     */
    public function apply(array $pairCascades, int $userId, string $now, array &$touchedTransactionIds): void
    {
        foreach ($pairCascades as $cascade) {
            // A leg whose partner is gone is no longer money moving between
            // two of your own accounts — it is money that arrived or left.
            $newType = match ($cascade['deletedType']) {
                TransactionType::TransferOut->value => TransactionType::Income->value,
                TransactionType::TransferIn->value => TransactionType::Expense->value,
                default => null,
            };

            if ($newType === null || ! $this->reclassify($cascade['partnerId'], $newType, $userId)) {
                continue;
            }

            $this->persistCascadeOp($cascade['partnerId'], $newType, $cascade, $userId, $now);
            $touchedTransactionIds[] = $cascade['partnerId'];
        }
    }

    // False when the partner is gone too, or when it still has a pair link —
    // something re-paired it, and reclassifying would then be wrong.
    private function reclassify(int $partnerId, string $newType, int $userId): bool
    {
        $partnerRow = $this->db->connection()
            ->table('transactions')
            ->where('id', $partnerId)
            ->where('user_id', $userId)
            ->first();

        if ($partnerRow === null || $partnerRow->pair_transaction_id !== null) {
            return false;
        }

        $this->db->connection()
            ->table('transactions')
            ->where('id', $partnerId)
            ->where('user_id', $userId)
            ->update(['type' => $newType]);

        return true;
    }

    // Stored with a REAL monotonic HLC (tombstone HLC, counter + 1) so it
    // sorts deterministically AFTER the tombstone — an HLC of [0,0] would sort
    // FIRST and a rebuild would revert the reclassification.
    /**
     * @param  array{partnerId: int, deletedType: string, tombHlcL: int, tombHlcC: int}  $cascade
     */
    private function persistCascadeOp(int $partnerId, string $newType, array $cascade, int $userId, string $now): void
    {
        $this->db->connection()->table('op_log_entries')->updateOrInsert(
            [
                'user_id' => $userId,
                'device_id' => OpLogReplayer::SYSTEM_CASCADE_DEVICE_ID,
                'table_name' => 'transactions',
                'pk' => (string) $partnerId,
                'field' => 'type',
                'hlc_l' => $cascade['tombHlcL'],
                'hlc_c' => $cascade['tombHlcC'] + 1,
            ],
            [
                'op_type' => OpType::Set->value,
                'value' => json_encode($newType, JSON_THROW_ON_ERROR),
                'signature' => '',
                'recorded_at' => $now,
            ],
        );
    }
}
