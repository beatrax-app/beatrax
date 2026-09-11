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
    public const string TABLE = 'transaction_splits';

    public const string AMOUNT = 'settled_amount_minor';

    public function __construct(private DatabaseManager $db) {}

    // Null admits the leg. The row's own id is excluded from what is already
    // there, so replaying a leg that is present is the idempotent re-apply it
    // has always been.
    /**
     * @param  array<string, mixed>  $payload
     * @param  array<int|string, int>  $arriving
     */
    public function reasonToRefuse(string $table, int|string $pk, array $payload, array $arriving = []): ?QuarantineReason
    {
        $transactionId = $table === self::TABLE ? self::asInt($payload['transaction_id'] ?? null) : null;
        $incoming = self::asInt($payload[self::AMOUNT] ?? null);

        if ($transactionId === null || $incoming === null) {
            return null;
        }

        $currency = is_string($payload['settled_currency'] ?? null) ? $payload['settled_currency'] : '';

        try {
            return $this->verdict($transactionId, $pk, $incoming, $currency, $arriving);
        } catch (SplitSumUnreadableException) {
            return QuarantineReason::SplitSumUnreadable;
        }
    }

    // A Set carries one column and no row, so the transaction and the currency
    // the sum is taken in come off the stored leg rather than the op. An op for
    // a leg that is not here yet is nobody's overfill: the update it precedes
    // matches no row either.
    /**
     * @param  array<int|string, int>  $arriving
     */
    public function reasonToRefuseSet(string $table, string $field, int|string $pk, mixed $value, array $arriving = []): ?QuarantineReason
    {
        if ($table !== self::TABLE || $field !== self::AMOUNT || self::asInt($value) === null) {
            return null;
        }

        try {
            $leg = $this->storedLeg($pk);
        } catch (SplitSumUnreadableException) {
            return QuarantineReason::SplitSumUnreadable;
        }

        return $leg === null
            ? null
            : $this->reasonToRefuse($table, $pk, [...$leg, self::AMOUNT => $value], $arriving);
    }

    // Separated from reasonToRefuse() so the one catch above covers both reads
    // and neither can be answered from a value it did not produce.
    /**
     * @param  array<int|string, int>  $arriving
     */
    private function verdict(int $transactionId, int|string $pk, int $incoming, string $currency, array $arriving): ?QuarantineReason
    {
        $parent = $this->parentAmount($transactionId, $currency);

        if ($parent === null) {
            return null;
        }

        return abs($this->legsAlreadyThere($transactionId, $pk, $currency, $arriving) + $incoming) > abs($parent)
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
                ->first([self::AMOUNT, 'settled_currency']);
        } catch (Throwable $e) {
            throw SplitSumUnreadableException::reading('transactions', $e);
        }

        if (! is_object($row) || ($row->settled_currency ?? null) !== $currency) {
            return null;
        }

        return self::asInt($row->settled_amount_minor ?? null);
    }

    // What the other legs hold once the batch lands: the amount an op in the
    // same batch is about to write where it names the leg, the stored one
    // otherwise. A rebalance announces its WHOLE set, so counting only what is
    // stored refuses the order in which a raised leg happens to arrive first.
    /**
     * @param  array<int|string, int>  $arriving
     *
     * @throws SplitSumUnreadableException when the legs already stored cannot be summed
     */
    private function legsAlreadyThere(int $transactionId, int|string $pk, string $currency, array $arriving): int
    {
        try {
            $legs = $this->db->connection()->table(self::TABLE)
                ->where('transaction_id', $transactionId)
                ->where('settled_currency', $currency)
                ->where('id', '!=', $pk)
                ->get(['id', self::AMOUNT]);
        } catch (Throwable $e) {
            throw SplitSumUnreadableException::reading(self::TABLE, $e);
        }

        $total = 0;

        foreach ($legs as $leg) {
            $id = $leg->id ?? null;
            $stored = self::asInt($leg->settled_amount_minor ?? null) ?? 0;
            $total += is_numeric($id) ? ($arriving[(int) $id] ?? $stored) : $stored;
        }

        return $total;
    }

    /**
     * @return array{transaction_id: mixed, settled_currency: mixed}|null
     *
     * @throws SplitSumUnreadableException when the leg cannot be read
     */
    private function storedLeg(int|string $pk): ?array
    {
        try {
            $row = $this->db->connection()->table(self::TABLE)->where('id', $pk)
                ->first(['transaction_id', 'settled_currency']);
        } catch (Throwable $e) {
            throw SplitSumUnreadableException::reading(self::TABLE, $e);
        }

        return is_object($row)
            ? ['transaction_id' => $row->transaction_id ?? null, 'settled_currency' => $row->settled_currency ?? null]
            : null;
    }

    private static function asInt(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }
}
