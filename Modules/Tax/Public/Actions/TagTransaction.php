<?php

declare(strict_types=1);

namespace Modules\Tax\Public\Actions;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Session\Session;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\UniqueConstraintViolationException;
use Modules\Core\Public\Contracts\Clock;
use Modules\Ledger\Public\Services\FieldProvenanceWriter;
use Modules\Search\Public\Contracts\SearchIndexWriterContract;
use Modules\Sync\Public\Services\SensitiveColumnCodec;
use Modules\Tax\Public\Events\TransactionTagged;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * @link ../../../../.docs/features/tax/architecture.md
 */
final class TagTransaction
{
    public function __construct(
        private readonly DatabaseManager $db,
        private readonly Dispatcher $events,
        private readonly Clock $clock,
        private readonly FieldProvenanceWriter $provenance,
        private readonly SensitiveColumnCodec $codec,
        private readonly Session $session,
        private readonly ?SearchIndexWriterContract $searchIndex = null,
    ) {}

    /**
     * @param  string  $provenanceSource  field_provenance source stamped onto
     *                                    `tax_tag` after a successful write. Defaults to
     *                                    `'manual'` (every existing caller); the rule
     *                                    engine passes `'rule'` so a rule-applied tax
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
        // 404-not-403 to avoid existence leakage across users; ownership
        // is checked before any write below.
        $txExists = $this->db->connection()
            ->table('transactions')
            ->where('id', $transactionId)
            ->where('user_id', $userId)
            ->exists();

        if (! $txExists) {
            throw new NotFoundHttpException('Transaction not found.');
        }

        // Leg-ownership guard: a forged transactionSplitId could otherwise
        // target another user's leg or a leg on a different transaction.
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

        // Cross-user category is a 404, not a silent fallback to
        // uncategorised.
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

        // Time arrives via the injected Clock so the +/-10-year window is
        // testable with the standard clock fake.
        if ($taxYearOverride !== null) {
            $currentYear = $this->clock->now()->year;
            if ($taxYearOverride < $currentYear - 10 || $taxYearOverride > $currentYear + 10) {
                throw new \InvalidArgumentException(
                    "tax_year_override {$taxYearOverride} is outside the allowed range (current year ±10).",
                );
            }
        }

        // Idempotent upsert keyed on (user_id, transaction_id,
        // transaction_split_id). A bare equality-to-null where() clause
        // does not reliably compile to IS NULL, so whereNull()/
        // whereNotNull() are used explicitly below.
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
                        'note' => $this->encryptNote($note, $userId),
                        'tax_year_override' => $taxYearOverride,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
            } catch (UniqueConstraintViolationException) {
                // Lost the select-then-insert race against a concurrent
                // request — the row exists now; retry as the guarded
                // update instead of surfacing a 500.
                $this->updateExisting($userId, $transactionId, $deductionCategoryId, $note, $taxYearOverride, $now, $transactionSplitId);
            }
        }

        // field_provenance lives only on transactions, so leg-scoped tags
        // stamp the same whole-transaction key as whole-transaction tags.
        $this->provenance->stamp($userId, $transactionId, ['tax_tag' => $provenanceSource]);

        $this->events->dispatch(new TransactionTagged(
            userId: $userId,
            transactionId: $transactionId,
            deductionCategoryId: $deductionCategoryId,
        ));

        // Optional nullable injection — a no-op when the Search module is
        // absent. The writer re-verifies ownership from the passed actor
        // id, so a forged transaction id can never reach another user's
        // index doc.
        $this->searchIndex?->upsertForTransaction($transactionId, $userId);
    }

    // See the full-payload upsert contract at the class @link: a bare
    // re-tag (all-null payload) never wipes an existing tag, but any
    // non-null field rewrites all three payload columns together.
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
            $values['note'] = $this->encryptNote($note, $userId);
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

    private function encryptNote(?string $note, int $userId): ?string
    {
        return is_string($note)
            ? $this->codec->encryptValue('tax_transaction_tags', 'note', $note, $userId, $this->session)
            : $note;
    }
}
