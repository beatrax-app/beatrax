<?php

declare(strict_types=1);

namespace Modules\Tax\Public\Actions;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Carbon;
use Modules\Tax\Public\Events\TransactionTagged;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Tags a transaction with a deduction category.
 *
 * Ownership is checked before every write (T-07-01, T-07-02).
 * An idempotent updateOrInsert ensures re-tagging is safe.
 * Dispatches TransactionTagged after every successful write.
 *
 * Security guarantees:
 *   - Transaction must belong to $userId → 404 on miss (T-07-01)
 *   - Category (if non-null) must belong to $userId → 404 on miss (T-07-02)
 *   - tax_year_override must be within now±10 years → InvalidArgumentException (T-07-03)
 *   - Raw DatabaseManager only — no Eloquent statics (PHPStan level 10)
 */
final class TagTransaction
{
    public function __construct(
        private readonly DatabaseManager $db,
        private readonly Dispatcher $events,
    ) {}

    public function execute(
        int $userId,
        int $transactionId,
        ?int $deductionCategoryId,
        ?string $note,
        ?int $taxYearOverride,
    ): void {
        // (1) Verify transaction ownership — 404-not-403 to avoid existence leakage.
        $txExists = $this->db->connection()
            ->table('transactions')
            ->where('id', $transactionId)
            ->where('user_id', $userId)
            ->exists();

        if (! $txExists) {
            throw new NotFoundHttpException('Transaction not found.');
        }

        // (2) Verify category ownership — cross-user category is a 404 (T-07-02).
        if ($deductionCategoryId !== null) {
            $catExists = $this->db->connection()
                ->table('tax_deduction_categories')
                ->where('id', $deductionCategoryId)
                ->where('user_id', $userId)
                ->exists();

            if (! $catExists) {
                throw new NotFoundHttpException('Deduction category not found.');
            }
        }

        // (3) Range-check tax_year_override (T-07-03).
        if ($taxYearOverride !== null) {
            $currentYear = Carbon::now()->year;
            if ($taxYearOverride < $currentYear - 10 || $taxYearOverride > $currentYear + 10) {
                throw new \InvalidArgumentException(
                    "tax_year_override {$taxYearOverride} is outside the allowed range (current year ±10).",
                );
            }
        }

        // (4) Idempotent upsert — unique(user_id, transaction_id).
        $now = Carbon::now()->toDateTimeString();
        $this->db->connection()
            ->table('tax_transaction_tags')
            ->updateOrInsert(
                ['user_id' => $userId, 'transaction_id' => $transactionId],
                [
                    'deduction_category_id' => $deductionCategoryId,
                    'note'                  => $note,
                    'tax_year_override'     => $taxYearOverride,
                    'updated_at'            => $now,
                    'created_at'            => $now,
                ],
            );

        // (5) Notify listeners.
        $this->events->dispatch(new TransactionTagged(
            userId: $userId,
            transactionId: $transactionId,
            deductionCategoryId: $deductionCategoryId,
        ));
    }
}
