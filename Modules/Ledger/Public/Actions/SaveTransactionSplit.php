<?php

declare(strict_types=1);

namespace Modules\Ledger\Public\Actions;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use Modules\Core\Models\User;
use Modules\Ledger\Public\Contracts\SavesTransactionSplit;
use Modules\Ledger\Public\Exceptions\SplitSumMismatchException;
use Modules\Ledger\Public\ValueObjects\Money;
use Modules\Sync\Public\Events\TransactionMutated;
use Modules\Sync\Public\Events\TransactionSplitMutated;
use stdClass;

/**
 * The sole mutator of `transaction_splits` (Req 1, 5, 6, 7, 8, 10 / D-04,
 * D-07, D-08, D-09, D-12).
 *
 * `save()`: creates or edits a split. The sum-to-parent Money-VO invariant is
 * re-checked INSIDE the DB write transaction against a freshly re-read
 * parent `settled_amount_minor` (TOCTOU-safe, mirrors
 * `Modules\Pots\Public\Services\PotWriter::fund()`). The existing/incoming
 * leg sets are reconciled with a targeted, PK-PRESERVING UPDATE/DELETE/INSERT
 * diff — never delete-all+reinsert — so surviving legs keep their primary
 * keys and the per-(table, pk, field) LWW sync merge never diverges (D-09,
 * D-12, Req 10, Req 4).
 *
 * `unsplit()`: deletes every leg row and restores a single `category_id` on
 * the parent (Req 8).
 *
 * Every leg's `category_id` is visibility-guarded
 * (`whereNull('user_id')->orWhere('user_id', $user->id)` — copied verbatim
 * from `UpdateTransactionCategory`) before persist (T-13.1-02).
 */
final class SaveTransactionSplit implements SavesTransactionSplit
{
    /** @var list<string> */
    private const SPLITTABLE_TYPES = ['expense', 'income', 'fee', 'refund', 'adjustment'];

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly Dispatcher $events,
    ) {}

    /**
     * @param  list<array{id: ?int, category_id: int, settled_amount_minor: int, note: ?string}>  $legs
     *
     * @throws SplitSumMismatchException
     * @throws InvalidArgumentException
     */
    public function save(User $user, int $transactionId, array $legs): void
    {
        // 1. Ownership + type gate (pre-check, fail fast, no DB write).
        $parent = $this->db->connection()
            ->table('transactions')
            ->where('id', $transactionId)
            ->where('user_id', $user->id)
            ->first(['id', 'type', 'settled_amount_minor', 'settled_currency']);

        if ($parent === null) {
            throw new InvalidArgumentException('Transaction not found or not owned by user.');
        }

        $parentType = self::toStr($parent->type);
        if (! in_array($parentType, self::SPLITTABLE_TYPES, true)) {
            throw new InvalidArgumentException("Transaction type '{$parentType}' is not splittable.");
        }

        // 2. Per-leg pre-validation (fail fast, no DB round trip needed for
        // these checks — cheapest before any write).
        if (count($legs) < 2) {
            throw new InvalidArgumentException('A split requires at least 2 legs.');
        }

        $parentMinorForSignCheck = self::toInt($parent->settled_amount_minor);
        foreach ($legs as $leg) {
            if ($leg['settled_amount_minor'] === 0) {
                throw new InvalidArgumentException('Leg amounts must be non-zero.');
            }
            if (($leg['settled_amount_minor'] < 0) !== ($parentMinorForSignCheck < 0)) {
                throw new InvalidArgumentException('Leg amounts must share the parent\'s sign.');
            }

            $categoryVisible = $this->db->connection()
                ->table('categories')
                ->where('id', $leg['category_id'])
                ->where(static function (QueryBuilder $q) use ($user): void {
                    $q->whereNull('user_id')->orWhere('user_id', $user->id);
                })
                ->exists();

            if (! $categoryVisible) {
                throw new InvalidArgumentException('Leg category not found or not accessible by user.');
            }
        }

        /** @var list<TransactionSplitMutated> $dispatchAfterCommit */
        $dispatchAfterCommit = [];

        $this->db->connection()->transaction(function () use ($user, $transactionId, $legs, &$dispatchAfterCommit): void {
            // 3. Re-read the parent's settled amount INSIDE the transaction
            // (TOCTOU-safe, mirrors PotWriter::fund()).
            $freshParent = $this->db->connection()
                ->table('transactions')
                ->where('id', $transactionId)
                ->where('user_id', $user->id)
                ->first(['settled_amount_minor', 'settled_currency']);

            if ($freshParent === null) {
                throw new InvalidArgumentException('Transaction not found or not owned by user.');
            }

            $parentMinor = self::toInt($freshParent->settled_amount_minor);
            $currency = self::toStr($freshParent->settled_currency);

            $sum = Money::ofMinor(0, $currency);
            foreach ($legs as $leg) {
                $sum = $sum->plus(Money::ofMinor($leg['settled_amount_minor'], $currency));
            }

            if ($sum->toMinor() !== $parentMinor) {
                throw new SplitSumMismatchException(
                    "Leg totals ({$sum->toMinor()}) must match parent ({$parentMinor}) exactly.",
                );
            }

            // Reconcile the leg set with a targeted, PK-PRESERVING diff —
            // NOT delete-all+reinsert (D-09, D-12, Req 10, Req 4).
            //
            // Full rows (not just ids) are re-read here so the edit branch
            // below can compute a genuine per-field dirty diff (T-13.1-09):
            // per-(table,pk,field) LWW sync merge only converges correctly
            // under two independent offline edits of the SAME leg if each
            // device's op-log SET carries ONLY the fields it actually
            // changed. Re-dispatching every field unconditionally would let
            // one device's unchanged echo of a field (e.g. an amount it
            // never touched) silently clobber the other device's real edit
            // to that same field once HLC-ordered — a whole-row-wins bug
            // masquerading as field-level LWW.
            /** @var Collection<int, stdClass> $existingRows */
            $existingRows = $this->db->connection()
                ->table('transaction_splits')
                ->where('transaction_id', $transactionId)
                ->where('user_id', $user->id)
                ->get(['id', 'category_id', 'settled_amount_minor', 'note', 'sort_order'])
                ->keyBy(static fn (object $row): int => self::toInt($row->id));

            /** @var list<int> $existingIds */
            $existingIds = $existingRows->keys()->all();

            /** @var list<int> $incomingIds */
            $incomingIds = [];
            foreach ($legs as $leg) {
                $legId = $leg['id'] ?? null;
                if ($legId !== null && in_array($legId, $existingIds, true)) {
                    $incomingIds[] = $legId;
                }
            }

            $now = CarbonImmutable::now();

            foreach ($legs as $index => $leg) {
                $legId = $leg['id'] ?? null;

                if ($legId !== null && in_array($legId, $existingIds, true)) {
                    // Matched by id → UPDATE in place, preserving the PK.
                    $fields = [
                        'category_id' => $leg['category_id'],
                        'settled_amount_minor' => $leg['settled_amount_minor'],
                        'note' => $leg['note'],
                        'sort_order' => $index,
                    ];

                    $this->db->connection()
                        ->table('transaction_splits')
                        ->where('id', $legId)
                        ->where('user_id', $user->id)
                        ->update($fields);

                    // Dirty-field diff (T-13.1-09 / D-12): only fields whose
                    // value actually changed relative to the pre-write row
                    // are captured. See the comment above existingRows.
                    $old = $existingRows->get($legId);
                    $oldFields = $old !== null ? [
                        'category_id' => $old->category_id ?? null,
                        'settled_amount_minor' => $old->settled_amount_minor ?? null,
                        'note' => $old->note ?? null,
                        'sort_order' => $old->sort_order ?? null,
                    ] : [];

                    $dirty = [];
                    foreach ($fields as $field => $value) {
                        if (self::legFieldChanged($oldFields[$field] ?? null, $value)) {
                            $dirty[$field] = $value;
                        }
                    }

                    if ($dirty !== []) {
                        $dispatchAfterCommit[] = new TransactionSplitMutated(
                            splitId: $legId,
                            transactionId: $transactionId,
                            userId: $user->id,
                            mutationType: 'edit',
                            dirtyFields: $dirty,
                        );
                    }
                } else {
                    // No matching incoming id → INSERT a new row.
                    $fields = [
                        'user_id' => $user->id,
                        'transaction_id' => $transactionId,
                        'category_id' => $leg['category_id'],
                        'settled_amount_minor' => $leg['settled_amount_minor'],
                        'settled_currency' => $currency,
                        'note' => $leg['note'],
                        'sort_order' => $index,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];

                    $newId = self::toInt($this->db->connection()->table('transaction_splits')->insertGetId($fields));

                    $dispatchAfterCommit[] = new TransactionSplitMutated(
                        splitId: $newId,
                        transactionId: $transactionId,
                        userId: $user->id,
                        mutationType: 'create',
                        dirtyFields: $fields,
                    );
                }
            }

            // Leg present in the existing set but absent from the incoming
            // set → DELETE (tombstone).
            $removedIds = array_diff($existingIds, $incomingIds);
            foreach ($removedIds as $removedId) {
                $this->db->connection()
                    ->table('transaction_splits')
                    ->where('id', $removedId)
                    ->where('user_id', $user->id)
                    ->delete();

                $dispatchAfterCommit[] = new TransactionSplitMutated(
                    splitId: $removedId,
                    transactionId: $transactionId,
                    userId: $user->id,
                    mutationType: 'delete',
                );
            }
        });

        // 4. Dispatch AFTER the transaction commits (WR-06 contract — never
        // from inside an open DB::transaction() closure).
        foreach ($dispatchAfterCommit as $event) {
            $this->events->dispatch($event);
        }
    }

    public function unsplit(User $user, int $transactionId, int $survivingCategoryId): void
    {
        $categoryVisible = $this->db->connection()
            ->table('categories')
            ->where('id', $survivingCategoryId)
            ->where(static function (QueryBuilder $q) use ($user): void {
                $q->whereNull('user_id')->orWhere('user_id', $user->id);
            })
            ->exists();

        if (! $categoryVisible) {
            throw new InvalidArgumentException('Surviving category not found or not accessible by user.');
        }

        /** @var list<int> $removedIds */
        $removedIds = [];

        $this->db->connection()->transaction(function () use ($user, $transactionId, $survivingCategoryId, &$removedIds): void {
            $parentExists = $this->db->connection()
                ->table('transactions')
                ->where('id', $transactionId)
                ->where('user_id', $user->id)
                ->exists();

            if (! $parentExists) {
                throw new InvalidArgumentException('Transaction not found or not owned by user.');
            }

            $legRows = $this->db->connection()
                ->table('transaction_splits')
                ->where('transaction_id', $transactionId)
                ->where('user_id', $user->id)
                ->get(['id', 'category_id']);

            $survivorIsCurrentLeg = $legRows->contains(
                static fn (object $row): bool => self::toInt($row->category_id) === $survivingCategoryId,
            );

            if ($legRows->isNotEmpty() && ! $survivorIsCurrentLeg) {
                throw new InvalidArgumentException('Surviving category must be one of the split\'s current leg categories.');
            }

            $removedIds = $legRows->map(static fn (object $row): int => self::toInt($row->id))->all();

            $this->db->connection()
                ->table('transaction_splits')
                ->where('transaction_id', $transactionId)
                ->where('user_id', $user->id)
                ->delete();

            $this->db->connection()
                ->table('transactions')
                ->where('id', $transactionId)
                ->where('user_id', $user->id)
                ->update(['category_id' => $survivingCategoryId]);
        });

        foreach ($removedIds as $removedId) {
            $this->events->dispatch(new TransactionSplitMutated(
                splitId: $removedId,
                transactionId: $transactionId,
                userId: $user->id,
                mutationType: 'delete',
            ));
        }

        $this->events->dispatch(new TransactionMutated(
            transactionId: $transactionId,
            userId: $user->id,
            mutationType: 'edit',
            dirtyFields: ['category_id' => $survivingCategoryId],
        ));
    }

    private static function toInt(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    private static function toStr(mixed $value): string
    {
        return is_string($value) ? $value : (is_scalar($value) ? (string) $value : '');
    }

    /**
     * Whether a leg field's incoming value differs from its pre-write DB
     * value (T-13.1-09). $newValue's PHP type (int for category_id /
     * settled_amount_minor / sort_order, string|null for note) determines
     * which normalization is applied to the raw DB value before comparing.
     */
    private static function legFieldChanged(mixed $oldValue, mixed $newValue): bool
    {
        if ($newValue === null) {
            return $oldValue !== null;
        }

        if (is_int($newValue)) {
            return self::toInt($oldValue) !== $newValue;
        }

        return self::toStr($oldValue) !== $newValue;
    }
}
