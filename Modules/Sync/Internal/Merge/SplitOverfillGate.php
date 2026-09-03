<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Merge;

use Illuminate\Database\DatabaseManager;
use Throwable;

// A transaction's split legs must add up to it exactly — SaveTransactionSplit
// refuses anything else and the applier had no such rule. Once each leg carried
// an identity of its own, a peer's whole second set no longer collided with
// this device's: both landed, and one charge showed twice its own money.
/**
 * @link ../../../../.docs/features/sync/architecture.md
 */
final readonly class SplitOverfillGate
{
    private const string TABLE = 'transaction_splits';

    public function __construct(private DatabaseManager $db) {}

    // True when writing this leg would carry the legs past the transaction.
    // The row's own id is excluded from what is already there, so replaying a
    // leg that is present is the idempotent re-apply it has always been.
    /**
     * @param  array<string, mixed>  $payload
     */
    public function refuses(string $table, int|string $pk, array $payload): bool
    {
        $transactionId = $table === self::TABLE ? self::asInt($payload['transaction_id'] ?? null) : null;
        $incoming = self::asInt($payload['settled_amount_minor'] ?? null);

        if ($transactionId === null || $incoming === null) {
            return false;
        }

        $currency = is_string($payload['settled_currency'] ?? null) ? $payload['settled_currency'] : '';
        $parent = $this->parentAmount($transactionId, $currency);

        if ($parent === null) {
            return false;
        }

        return abs($this->legsAlreadyThere($transactionId, $pk, $currency) + $incoming) > abs($parent);
    }

    // Only when the leg is denominated in the transaction's own currency. A leg
    // in another one is not this gate's question, and adding the two together
    // would be minor units of two currencies under one sign.
    private function parentAmount(int $transactionId, string $currency): ?int
    {
        try {
            $row = $this->db->connection()->table('transactions')->where('id', $transactionId)
                ->first(['settled_amount_minor', 'settled_currency']);
        } catch (Throwable) {
            return null;
        }

        if (! is_object($row) || ($row->settled_currency ?? null) !== $currency) {
            return null;
        }

        return self::asInt($row->settled_amount_minor ?? null);
    }

    private function legsAlreadyThere(int $transactionId, int|string $pk, string $currency): int
    {
        try {
            $sum = $this->db->connection()->table(self::TABLE)
                ->where('transaction_id', $transactionId)
                ->where('settled_currency', $currency)
                ->where('id', '!=', $pk)
                ->sum('settled_amount_minor');
        } catch (Throwable) {
            return 0;
        }

        return (int) $sum;
    }

    private static function asInt(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }
}
