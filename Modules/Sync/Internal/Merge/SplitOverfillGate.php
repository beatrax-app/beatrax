<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Merge;

use Illuminate\Database\DatabaseManager;
use Modules\Sync\Internal\Exceptions\SplitSumUnreadableException;
use Modules\Sync\Internal\OpLog\QuarantineReason;
use Throwable;

// A transaction's split legs must add up to it exactly — SaveTransactionSplit
// refuses anything else and the applier had no such rule. Once each leg carried
// an identity of its own, a peer's whole second set no longer collided with
// this device's: both landed, and one charge showed twice its own money.

// The two reads this gate compares used to fold their own failure into a
// number, so a sum that could not be taken read as legs that fit. Refusing on
// a reason the reprojector retries keeps a busy database from admitting the
// money the gate exists to stop.
/**
 * @link ../../../../.docs/features/sync/architecture.md
 */
final readonly class SplitOverfillGate
{
    private const string TABLE = 'transaction_splits';

    public function __construct(private DatabaseManager $db) {}

    // Null admits the leg. The row's own id is excluded from what is already
    // there, so replaying a leg that is present is the idempotent re-apply it
    // has always been.
    /**
     * @param  array<string, mixed>  $payload
     */
    public function reasonToRefuse(string $table, int|string $pk, array $payload): ?QuarantineReason
    {
        $transactionId = $table === self::TABLE ? self::asInt($payload['transaction_id'] ?? null) : null;
        $incoming = self::asInt($payload['settled_amount_minor'] ?? null);

        if ($transactionId === null || $incoming === null) {
            return null;
        }

        $currency = is_string($payload['settled_currency'] ?? null) ? $payload['settled_currency'] : '';

        try {
            return $this->verdict($transactionId, $pk, $incoming, $currency);
        } catch (SplitSumUnreadableException) {
            return QuarantineReason::SplitSumUnreadable;
        }
    }

    // Separated from reasonToRefuse() so the one catch above covers both reads
    // and neither can be answered from a value it did not produce.
    private function verdict(int $transactionId, int|string $pk, int $incoming, string $currency): ?QuarantineReason
    {
        $parent = $this->parentAmount($transactionId, $currency);

        if ($parent === null) {
            return null;
        }

        return abs($this->legsAlreadyThere($transactionId, $pk, $currency) + $incoming) > abs($parent)
            ? QuarantineReason::SplitWouldOverfillTransaction
            : null;
    }

    // Only when the leg is denominated in the transaction's own currency. A leg
    // in another one is not this gate's question, and adding the two together
    // would be minor units of two currencies under one sign.
    /**
     * @throws SplitSumUnreadableException when the transaction cannot be read
     */
    private function parentAmount(int $transactionId, string $currency): ?int
    {
        try {
            $row = $this->db->connection()->table('transactions')->where('id', $transactionId)
                ->first(['settled_amount_minor', 'settled_currency']);
        } catch (Throwable $e) {
            throw SplitSumUnreadableException::reading('transactions', $e);
        }

        if (! is_object($row) || ($row->settled_currency ?? null) !== $currency) {
            return null;
        }

        return self::asInt($row->settled_amount_minor ?? null);
    }

    /**
     * @throws SplitSumUnreadableException when the legs already stored cannot be summed
     */
    private function legsAlreadyThere(int $transactionId, int|string $pk, string $currency): int
    {
        try {
            $sum = $this->db->connection()->table(self::TABLE)
                ->where('transaction_id', $transactionId)
                ->where('settled_currency', $currency)
                ->where('id', '!=', $pk)
                ->sum('settled_amount_minor');
        } catch (Throwable $e) {
            throw SplitSumUnreadableException::reading(self::TABLE, $e);
        }

        return (int) $sum;
    }

    private static function asInt(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }
}
