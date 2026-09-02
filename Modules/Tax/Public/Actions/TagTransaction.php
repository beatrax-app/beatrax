<?php

declare(strict_types=1);

namespace Modules\Tax\Public\Actions;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\UniqueConstraintViolationException;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Services\SessionFactory;
use Modules\Ledger\Public\Services\FieldProvenanceWriter;
use Modules\Ledger\Public\Services\TransactionStatusQuery;
use Modules\Search\Public\Contracts\SearchIndexWriterContract;
use Modules\Sync\Public\Events\EntityMutated;
use Modules\Sync\Public\Services\SensitiveColumnCodec;
use Modules\Tax\Internal\Support\TaxYearBounds;
use Modules\Tax\Public\Events\TransactionTagged;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * @link ../../../../.docs/features/tax/tag-write-contract.md
 */
final readonly class TagTransaction
{
    public function __construct(
        private DatabaseManager $db,
        private Dispatcher $events,
        private Clock $clock,
        private FieldProvenanceWriter $provenance,
        private SensitiveColumnCodec $codec,
        // A factory, not the session: resolving a session builds the encrypter,
        // and Artisan constructs this class merely to list a console command.
        private SessionFactory $session,
        private ?SearchIndexWriterContract $searchIndex = null,
    ) {}

    /**
     * @param  string  $provenanceSource  `field_provenance` source stamped onto
     *                                    `tax_tag`: `'manual'`, or `'rule'` from the
     *                                    rule engine.
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
        $txRow = $this->db->connection()
            ->table('transactions')
            ->where('id', $transactionId)
            ->where('user_id', $userId)
            ->first(['status']);

        if ($txRow === null) {
            throw new NotFoundHttpException('Transaction not found.');
        }

        // The rule engine, a bulk tag and a replay all reach this action
        // without passing the page's own lock, and a tag is exactly the
        // classification a reconcile froze.
        if (TransactionStatusQuery::locksEdits($txRow->status)) {
            return;
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

        if ($taxYearOverride !== null && ! TaxYearBounds::contains($taxYearOverride, $this->clock->now()->year)) {
            throw new \InvalidArgumentException(
                "tax_year_override {$taxYearOverride} is outside the allowed range (current year ±".TaxYearBounds::SPAN_YEARS.').',
            );
        }

        // A bare where('transaction_split_id', null) does not reliably compile
        // to IS NULL, so the whole-transaction branch uses whereNull().
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

        $wasUpdate = $exists;

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
                // Lost the select-then-insert race; the row exists now, so
                // retry as the guarded update rather than surfacing a 500.
                $this->updateExisting($userId, $transactionId, $deductionCategoryId, $note, $taxYearOverride, $now, $transactionSplitId);
                $wasUpdate = true;
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

        $this->captureTag($userId, $transactionId, $transactionSplitId, $deductionCategoryId, $note, $taxYearOverride, $now, $wasUpdate);

        $this->searchIndex?->upsertForTransaction($transactionId, $userId);
    }

    private function captureTag(
        int $userId,
        int $transactionId,
        ?int $transactionSplitId,
        ?int $deductionCategoryId,
        ?string $note,
        ?int $taxYearOverride,
        string $now,
        bool $wasUpdate,
    ): void {
        $id = $this->db->connection()
            ->table('tax_transaction_tags')
            ->where('user_id', $userId)
            ->where('transaction_id', $transactionId)
            ->when(
                $transactionSplitId === null,
                static fn (QueryBuilder $q) => $q->whereNull('transaction_split_id'),
                static fn (QueryBuilder $q) => $q->where('transaction_split_id', $transactionSplitId),
            )
            ->value('id');

        if (! is_numeric($id)) {
            return;
        }

        $this->events->dispatch(new EntityMutated(
            table: 'tax_transaction_tags',
            pk: (int) $id,
            userId: $userId,
            // A create_row naming a row the peer already holds is ignored, so
            // announcing every re-tag as a create left the two devices
            // disagreeing for good — no quarantine, no error, nothing to see.
            mutationType: $wasUpdate ? 'edit' : 'create',
            dirtyFields: $wasUpdate
                ? self::editedColumns($deductionCategoryId, $note, $taxYearOverride, $now)
                : [
                    'user_id' => $userId,
                    'transaction_id' => $transactionId,
                    'transaction_split_id' => $transactionSplitId,
                    'deduction_category_id' => $deductionCategoryId,
                    'note' => $note,
                    'tax_year_override' => $taxYearOverride,
                ],
        ));
    }

    // Exactly what updateExisting() wrote, and nothing more: it leaves the three
    // payload columns alone when all of them are null, so announcing them anyway
    // would send three null sets and wipe the values the peer still holds.
    /** @return array<string, mixed> */
    private static function editedColumns(?int $deductionCategoryId, ?string $note, ?int $taxYearOverride, string $now): array
    {
        if ($deductionCategoryId === null && $note === null && $taxYearOverride === null) {
            return ['updated_at' => $now];
        }

        return [
            'deduction_category_id' => $deductionCategoryId,
            'note' => $note,
            'tax_year_override' => $taxYearOverride,
            'updated_at' => $now,
        ];
    }

    // Whole-payload upsert: a bare all-null re-tag leaves the row alone, but
    // any non-null field rewrites all three payload columns together.
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
            ? $this->codec->encryptValue('tax_transaction_tags', 'note', $note, $userId, ($this->session)())
            : $note;
    }
}
