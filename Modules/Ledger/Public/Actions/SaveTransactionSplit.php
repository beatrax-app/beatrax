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
use Modules\Core\Public\Services\SessionFactory;
use Modules\Ledger\Public\Contracts\SavesTransactionSplit;
use Modules\Ledger\Public\Exceptions\SplitSumMismatchException;
use Modules\Ledger\Public\ValueObjects\Money;
use Modules\Ledger\Public\ValueObjects\SplittableTypes;
use Modules\Sync\Public\Events\TransactionMutated;
use Modules\Sync\Public\Events\TransactionSplitMutated;
use Modules\Sync\Public\Services\SensitiveColumnCodec;
use stdClass;

/**
 * @link ../../../../.docs/features/ledger/architecture.md
 */
final class SaveTransactionSplit implements SavesTransactionSplit
{
    public function __construct(
        private readonly DatabaseManager $db,
        private readonly Dispatcher $events,
        private readonly SensitiveColumnCodec $codec,
        private readonly SessionFactory $session,
    ) {}

    /**
     * @param  list<array{id: ?int, category_id: int, settled_amount_minor: int, note: ?string}>  $legs
     *
     * @throws SplitSumMismatchException
     * @throws InvalidArgumentException
     */
    public function save(User $user, int $transactionId, array $legs): void
    {
        $parent = $this->db->connection()
            ->table('transactions')
            ->where('id', $transactionId)
            ->where('user_id', $user->id)
            ->first(['id', 'type', 'status', 'settled_amount_minor', 'settled_currency']);

        if ($parent === null) {
            throw new InvalidArgumentException('Transaction not found or not owned by user.');
        }

        // Reconciled lock: reuses the already user-scoped parent load
        // above — no extra query. TransactionDetail's catch blocks
        // convert this into a warn toast, staying warn-first end-to-end.
        if (self::toStr($parent->status) === 'reconciled') {
            throw new InvalidArgumentException('This transaction is reconciled. Un-reconcile it to change its split.');
        }

        $parentType = self::toStr($parent->type);
        if (! SplittableTypes::contains($parentType)) {
            throw new InvalidArgumentException("Transaction type '{$parentType}' is not splittable.");
        }

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
            // Re-read the parent's settled amount inside the transaction
            // — TOCTOU-safe, mirrors PotWriter::fund().
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

            // PK-preserving UPDATE/DELETE/INSERT diff (never delete-all +
            // reinsert), full rows re-read so the edit branch below can
            // compute a genuine per-field dirty diff — see the linked
            // architecture page for why the LWW sync merge requires this.
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
                    // Matched by id -> UPDATE in place, preserving the PK.
                    // Canonicalise empty note to NULL on write so '' and
                    // null are never distinct stored values.
                    $normalizedNote = self::normalizeNote($leg['note']);
                    $fields = [
                        'category_id' => $leg['category_id'],
                        'settled_amount_minor' => $leg['settled_amount_minor'],
                        'note' => $normalizedNote,
                        'sort_order' => $index,
                    ];

                    // The DB row stores ciphertext; $fields (plaintext)
                    // stays the dirty-diff and dispatched-event source of
                    // truth so the op-log's own encrypt-on-write never
                    // double-encrypts.
                    $dbFields = $fields;
                    $dbFields['note'] = $this->encryptNote($normalizedNote, $user->id);

                    $this->db->connection()
                        ->table('transaction_splits')
                        ->where('id', $legId)
                        ->where('user_id', $user->id)
                        ->update($dbFields);

                    // Only fields that actually changed are captured. The
                    // stored note is ciphertext when encrypted — decrypt
                    // before diffing so the comparison runs on plaintext,
                    // never a fresh random-nonce ciphertext.
                    $old = $existingRows->get($legId);
                    $oldFields = $old !== null ? [
                        'category_id' => $old->category_id ?? null,
                        'settled_amount_minor' => $old->settled_amount_minor ?? null,
                        'note' => $this->decryptNote($old->note ?? null, $user->id),
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
                    $fields = [
                        'user_id' => $user->id,
                        'transaction_id' => $transactionId,
                        'category_id' => $leg['category_id'],
                        'settled_amount_minor' => $leg['settled_amount_minor'],
                        'settled_currency' => $currency,
                        'note' => self::normalizeNote($leg['note']),
                        'sort_order' => $index,
                        // Pre-formatted datetime strings into the op-log
                        // dirtyFields rather than CarbonImmutable objects —
                        // decouples the capture payload from Carbon and
                        // the op-log serialiser's implicit coercion.
                        'created_at' => $now->toDateTimeString(),
                        'updated_at' => $now->toDateTimeString(),
                    ];

                    // $fields (plaintext) stays the dispatched-event source
                    // of truth; only the DB row gets the ciphertext note.
                    $dbFields = $fields;
                    $dbFields['note'] = $this->encryptNote($fields['note'], $user->id);

                    $newId = self::toInt($this->db->connection()->table('transaction_splits')->insertGetId($dbFields));

                    $dispatchAfterCommit[] = new TransactionSplitMutated(
                        splitId: $newId,
                        transactionId: $transactionId,
                        userId: $user->id,
                        mutationType: 'create',
                        dirtyFields: $fields,
                    );
                }
            }

            // Leg present in the existing set but absent from the
            // incoming set -> DELETE (tombstone).
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

        // Dispatch after the transaction commits — never from inside
        // an open DB::transaction() closure.
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
            $parent = $this->db->connection()
                ->table('transactions')
                ->where('id', $transactionId)
                ->where('user_id', $user->id)
                ->first(['id', 'status']);

            if ($parent === null) {
                throw new InvalidArgumentException('Transaction not found or not owned by user.');
            }

            // Reconciled lock: reuses the already user-scoped parent
            // load above — no extra query.
            if (self::toStr($parent->status) === 'reconciled') {
                throw new InvalidArgumentException('This transaction is reconciled. Un-reconcile it to change its split.');
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

    // $newValue's PHP type determines which normalization applies to
    // the raw DB value before comparing. string|null fields (note) are
    // canonicalised so '' and null compare equal, or leg notes would
    // ping-pong between '' and null across devices under LWW.
    private static function legFieldChanged(mixed $oldValue, mixed $newValue): bool
    {
        if (is_int($newValue)) {
            return self::toInt($oldValue) !== $newValue;
        }

        $old = $oldValue === null ? null : self::toStr($oldValue);
        $new = $newValue === null ? null : self::toStr($newValue);

        return self::normalizeNote($old) !== self::normalizeNote($new);
    }

    private static function normalizeNote(?string $note): ?string
    {
        return ($note === null || $note === '') ? null : $note;
    }

    private function encryptNote(?string $note, int $userId): ?string
    {
        return is_string($note)
            ? $this->codec->encryptValue('transaction_splits', 'note', $note, $userId, ($this->session)())
            : $note;
    }

    private function decryptNote(mixed $stored, int $userId): ?string
    {
        return is_string($stored)
            ? $this->codec->decryptValue('transaction_splits', 'note', $stored, $userId, ($this->session)())['value']
            : null;
    }
}
