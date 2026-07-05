<?php

declare(strict_types=1);

namespace Modules\Tax\Public\Actions;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\UniqueConstraintViolationException;
use Modules\Core\Public\Contracts\Clock;
use Modules\Ledger\Public\Services\FieldProvenanceWriter;
use Modules\Search\Public\Contracts\SearchIndexWriterContract;
use Modules\Tax\Public\Events\TransactionTagged;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Tags a transaction with a deduction category.
 *
 * Ownership is checked before every write (T-07-01, T-07-02).
 * Re-tagging is idempotent AND non-destructive: an all-null payload on an
 * already-tagged row (the one-tap path) leaves the existing category, note,
 * and year override intact; created_at is never rewritten on update.
 * Dispatches TransactionTagged after every successful write.
 *
 * Leg-aware (Phase 13.1 D-06a): an optional trailing `$transactionSplitId`
 * scopes the tag to one leg of a split transaction instead of the whole
 * transaction. Defaults to null (whole-transaction tagging), so every
 * existing unsplit caller is unaffected. When non-null, the leg is verified
 * to belong to the same `$transactionId` AND `$userId` (T-13.1-09 — a
 * forged leg id targeting another user's leg or a leg on another transaction
 * must never be accepted).
 *
 * Security guarantees:
 *   - Transaction must belong to $userId → 404 on miss (T-07-01)
 *   - Category (if non-null) must belong to $userId → 404 on miss (T-07-02)
 *   - tax_year_override must be within now±10 years → InvalidArgumentException (T-07-03)
 *   - transactionSplitId (if non-null) must belong to $transactionId AND $userId → 404 on miss (T-13.1-09)
 *   - Raw DatabaseManager only — no Eloquent statics (PHPStan level 10)
 */
final class TagTransaction
{
    public function __construct(
        private readonly DatabaseManager $db,
        private readonly Dispatcher $events,
        private readonly Clock $clock,
        private readonly FieldProvenanceWriter $provenance,
        private readonly ?SearchIndexWriterContract $searchIndex = null,
    ) {}

    /**
     * @param  string  $provenanceSource  D-04 field_provenance source stamped onto
     *                                    `tax_tag` after a successful write. Defaults to
     *                                    `'manual'` (every existing caller); the Plan 05
     *                                    rule engine passes `'rule'` so a rule-applied tax
     *                                    tag is distinguishable from a user-set one.
     */
    public function execute(
        int $userId,
        int $transactionId,
        ?int $deductionCategoryId,
        ?string $note,
        ?int $taxYearOverride,
        ?int $transactionSplitId = null,
        string $provenanceSource = 'manual',
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

        // (1b) Leg-ownership guard (T-13.1-09): a forged transactionSplitId
        // could otherwise target another user's leg or a leg belonging to a
        // different transaction. Reject before any write.
        if ($transactionSplitId !== null) {
            $legExists = $this->db->connection()
                ->table('transaction_splits')
                ->where('id', $transactionSplitId)
                ->where('transaction_id', $transactionId)
                ->where(static function (QueryBuilder $q) use ($userId): void {
                    $q->whereNull('user_id')->orWhere('user_id', $userId);
                })
                ->exists();

            if (! $legExists) {
                throw new NotFoundHttpException('Transaction split not found.');
            }
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

        // (3) Range-check tax_year_override (T-07-03). Time arrives via the
        // injected Clock so the ±10-year window is testable with the
        // standard clock fake (IN-06).
        if ($taxYearOverride !== null) {
            $currentYear = $this->clock->now()->year;
            if ($taxYearOverride < $currentYear - 10 || $taxYearOverride > $currentYear + 10) {
                throw new \InvalidArgumentException(
                    "tax_year_override {$taxYearOverride} is outside the allowed range (current year ±10).",
                );
            }
        }

        // (4) Idempotent upsert — unique(user_id, transaction_id, transaction_split_id).
        // A bare equality-to-null where() clause does not reliably compile to
        // IS NULL, so whereNull()/whereNotNull() are used explicitly below.
        $now = $this->clock->now()->toDateTimeString();
        $connection = $this->db->connection();

        $exists = $connection
            ->table('tax_transaction_tags')
            ->where('user_id', $userId)
            ->where('transaction_id', $transactionId)
            ->when(
                $transactionSplitId === null,
                static fn (QueryBuilder $q) => $q->whereNull('transaction_split_id'),
                static fn (QueryBuilder $q) => $q->where('transaction_split_id', $transactionSplitId),
            )
            ->exists();

        if ($exists) {
            $this->updateExisting($userId, $transactionId, $deductionCategoryId, $note, $taxYearOverride, $now, $transactionSplitId);
        } else {
            try {
                $connection
                    ->table('tax_transaction_tags')
                    ->insert([
                        'user_id' => $userId,
                        'transaction_id' => $transactionId,
                        'transaction_split_id' => $transactionSplitId,
                        'deduction_category_id' => $deductionCategoryId,
                        'note' => $note,
                        'tax_year_override' => $taxYearOverride,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
            } catch (UniqueConstraintViolationException) {
                // Lost the select-then-insert race against a concurrent
                // request — the row exists now; retry as the guarded
                // update instead of surfacing a 500 (IN-06).
                $this->updateExisting($userId, $transactionId, $deductionCategoryId, $note, $taxYearOverride, $now, $transactionSplitId);
            }
        }

        // (5) D-04 (Req 4 — manual-preservation): stamp field_provenance.tax_tag
        // on the PARENT transaction — field_provenance lives only on
        // `transactions`, so leg-scoped tags stamp the same whole-transaction
        // key as whole-transaction tags. Defaults to 'manual' for every
        // existing caller; the Plan 05 rule engine passes 'rule'.
        $this->provenance->stamp($userId, $transactionId, ['tax_tag' => $provenanceSource]);

        // (6) Notify listeners.
        $this->events->dispatch(new TransactionTagged(
            userId: $userId,
            transactionId: $transactionId,
            deductionCategoryId: $deductionCategoryId,
        ));

        // (7) Re-index the transaction so the tax note is searchable.
        // Optional nullable injection — no-op when Search module is absent (RESEARCH A4).
        // Pass the authenticated actor (CR-02): the writer verifies ownership
        // so a forged transaction id can never reach another user's index doc.
        $this->searchIndex?->upsertForTransaction($transactionId, $userId);
    }

    /**
     * Guarded update for an existing tag row.
     *
     * Non-destructive one-tap path (CR-03): a bare re-tag (all-null payload,
     * e.g. the ghost "Tag" button on an already-tagged row) must never wipe
     * an existing tag's category/note/override — only a save carrying at
     * least one value rewrites the payload columns. created_at is never
     * touched on update — it is the "first tagged" audit signal (WR-02).
     */
    private function updateExisting(
        int $userId,
        int $transactionId,
        ?int $deductionCategoryId,
        ?string $note,
        ?int $taxYearOverride,
        string $now,
        ?int $transactionSplitId = null,
    ): void {
        $values = ['updated_at' => $now];
        if ($deductionCategoryId !== null || $note !== null || $taxYearOverride !== null) {
            $values['deduction_category_id'] = $deductionCategoryId;
            $values['note'] = $note;
            $values['tax_year_override'] = $taxYearOverride;
        }

        $this->db->connection()
            ->table('tax_transaction_tags')
            ->where('user_id', $userId)
            ->where('transaction_id', $transactionId)
            ->when(
                $transactionSplitId === null,
                static fn (QueryBuilder $q) => $q->whereNull('transaction_split_id'),
                static fn (QueryBuilder $q) => $q->where('transaction_split_id', $transactionSplitId),
            )
            ->update($values);
    }
}
