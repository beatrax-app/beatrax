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
use Modules\Core\Public\Concerns\CoercesScalars;
use Modules\Core\Public\Services\SessionFactory;
use Modules\Core\Public\Support\Lang;
use Modules\Ledger\Public\Contracts\SavesTransactionSplit;
use Modules\Ledger\Public\Enums\ClearedStatus;
use Modules\Ledger\Public\Enums\TransactionType;
use Modules\Ledger\Public\Exceptions\SplitSumMismatchException;
use Modules\Ledger\Public\ValueObjects\Money;
use Modules\Sync\Public\Events\TransactionMutated;
use Modules\Sync\Public\Events\TransactionSplitMutated;
use Modules\Sync\Public\Services\SensitiveColumnCodec;
use stdClass;

final class SaveTransactionSplit implements SavesTransactionSplit
{
    // A missing row and a cross-user row are deliberately the same
    // message: telling them apart would confirm the row exists.
    private const NOT_FOUND_KEY = 'ledger::detail.errors.not_found_or_unowned';

    use CoercesScalars;

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
        $parent = $this->loadParent($user, $transactionId, ['id', 'type', 'status', 'settled_amount_minor', 'settled_currency']);
        $this->assertSplittable($user, $parent, $legs);

        /** @var list<TransactionSplitMutated> $dispatchAfterCommit */
        $dispatchAfterCommit = [];

        $this->db->connection()->transaction(function () use ($user, $transactionId, $legs, &$dispatchAfterCommit): void {
            // Re-read the parent's settled amount inside the transaction
            // — TOCTOU-safe, mirrors PotWriter::fund().
            $freshParent = $this->loadParent($user, $transactionId, ['settled_amount_minor', 'settled_currency']);
            $currency = self::toString($freshParent->settled_currency);

            $this->assertLegsSumToParent($legs, $currency, self::toInt($freshParent->settled_amount_minor));

            $dispatchAfterCommit = $this->applyLegDiff($user, $transactionId, $legs, $currency);
        });

        // Dispatch after the transaction commits — never from inside
        // an open DB::transaction() closure.
        foreach ($dispatchAfterCommit as $event) {
            $this->events->dispatch($event);
        }
    }

    // Both reads of the parent are user-scoped and both treat an absent row
    // the same way, so they ask the same question here. A missing row and a
    // cross-user row stay indistinguishable on purpose.
    /**
     * @param  list<string>  $columns
     */
    private function loadParent(User $user, int $transactionId, array $columns): stdClass
    {
        $parent = $this->db->connection()
            ->table('transactions')
            ->where('id', $transactionId)
            ->where('user_id', $user->id)
            ->first($columns);

        if ($parent === null) {
            throw new InvalidArgumentException(Lang::get(self::NOT_FOUND_KEY));
        }

        return $parent;
    }

    /**
     * @param  list<array{id: ?int, category_id: int, settled_amount_minor: int, note: ?string}>  $legs
     */
    private function assertSplittable(User $user, stdClass $parent, array $legs): void
    {
        // Reconciled lock: reuses the already user-scoped parent load
        // above — no extra query. TransactionDetail's catch blocks
        // convert this into a warn toast, staying warn-first end-to-end.
        if (self::toString($parent->status) === ClearedStatus::Reconciled->value) {
            throw new InvalidArgumentException(Lang::get('ledger::detail.errors.reconciled_split'));
        }

        $parentType = self::toString($parent->type);
        if (TransactionType::tryFrom($parentType)?->isSplittable() !== true) {
            throw new InvalidArgumentException(Lang::get('ledger::detail.errors.not_splittable', ['type' => $parentType]));
        }

        if (count($legs) < 2) {
            throw new InvalidArgumentException(Lang::get('ledger::detail.errors.min_two_legs'));
        }

        $parentMinorForSignCheck = self::toInt($parent->settled_amount_minor);
        foreach ($legs as $leg) {
            if ($leg['settled_amount_minor'] === 0) {
                throw new InvalidArgumentException(Lang::get('ledger::detail.errors.legs_non_zero'));
            }
            if (($leg['settled_amount_minor'] < 0) !== ($parentMinorForSignCheck < 0)) {
                throw new InvalidArgumentException(Lang::get('ledger::detail.errors.legs_parent_sign'));
            }

            $this->assertCategoryVisible($user, $leg['category_id']);
        }
    }

    // A category is usable if it is global or the user's own. Anything else
    // is reported as absent rather than forbidden, so the check cannot be
    // used to probe another user's category names.
    private function assertCategoryVisible(User $user, int $categoryId): void
    {
        $visible = $this->db->connection()
            ->table('categories')
            ->where('id', $categoryId)
            ->where(static function (QueryBuilder $q) use ($user): void {
                $q->whereNull('user_id')->orWhere('user_id', $user->id);
            })
            ->exists();

        if (! $visible) {
            throw new InvalidArgumentException(Lang::get('ledger::detail.errors.leg_category_not_accessible'));
        }
    }

    /**
     * @param  list<array{id: ?int, category_id: int, settled_amount_minor: int, note: ?string}>  $legs
     */
    private function assertLegsSumToParent(array $legs, string $currency, int $parentMinor): void
    {
        $sum = Money::ofMinor(0, $currency);
        foreach ($legs as $leg) {
            $sum = $sum->plus(Money::ofMinor($leg['settled_amount_minor'], $currency));
        }

        if ($sum->toMinor() !== $parentMinor) {
            throw new SplitSumMismatchException(
                "Leg totals ({$sum->toMinor()}) must match parent ({$parentMinor}) exactly.",
            );
        }
    }

    // PK-preserving UPDATE/DELETE/INSERT diff, never delete-all + reinsert:
    // full rows are re-read so the edit branch can compute a genuine
    // per-field dirty diff, which is what the LWW sync merge requires.
    /**
     * @param  list<array{id: ?int, category_id: int, settled_amount_minor: int, note: ?string}>  $legs
     * @return list<TransactionSplitMutated>
     */
    private function applyLegDiff(User $user, int $transactionId, array $legs, string $currency): array
    {
        /** @var Collection<int, stdClass> $existingRows */
        $existingRows = $this->db->connection()
            ->table('transaction_splits')
            ->where('transaction_id', $transactionId)
            ->where('user_id', $user->id)
            ->get(['id', 'category_id', 'settled_amount_minor', 'note', 'sort_order'])
            ->keyBy(static fn (object $row): int => self::toInt($row->id));

        /** @var list<int> $existingIds */
        $existingIds = $existingRows->keys()->all();

        $now = CarbonImmutable::now();
        $events = [];
        $incomingIds = [];

        foreach ($legs as $index => $leg) {
            $legId = $leg['id'] ?? null;

            if ($legId !== null && in_array($legId, $existingIds, true)) {
                $incomingIds[] = $legId;
                $event = $this->updateLeg($user, $transactionId, $legId, $leg, $index, $existingRows->get($legId));
                if ($event !== null) {
                    $events[] = $event;
                }

                continue;
            }

            $events[] = $this->insertLeg($user, $transactionId, $leg, $index, $currency, $now);
        }

        return [...$events, ...$this->deleteRemovedLegs($user, $transactionId, $existingIds, $incomingIds)];
    }

    // Matched by id -> UPDATE in place, preserving the PK. Returns null when
    // nothing actually changed, so an untouched leg raises no event.
    /**
     * @param  array{id: ?int, category_id: int, settled_amount_minor: int, note: ?string}  $leg
     */
    private function updateLeg(User $user, int $transactionId, int $legId, array $leg, int $index, ?stdClass $old): ?TransactionSplitMutated
    {
        // Canonicalise empty note to NULL on write so '' and null are never
        // distinct stored values.
        $normalizedNote = self::normalizeNote($leg['note']);
        $fields = [
            'category_id' => $leg['category_id'],
            'settled_amount_minor' => $leg['settled_amount_minor'],
            'note' => $normalizedNote,
            'sort_order' => $index,
        ];

        // The DB row stores ciphertext; $fields (plaintext) stays the
        // dirty-diff and dispatched-event source of truth so the op-log's
        // own encrypt-on-write never double-encrypts.
        $dbFields = $fields;
        $dbFields['note'] = $this->encryptNote($normalizedNote, $user->id);

        $this->db->connection()
            ->table('transaction_splits')
            ->where('id', $legId)
            ->where('user_id', $user->id)
            ->update($dbFields);

        // The stored note is ciphertext when encrypted — decrypt before
        // diffing so the comparison runs on plaintext, never a fresh
        // random-nonce ciphertext.
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

        if ($dirty === []) {
            return null;
        }

        return new TransactionSplitMutated(
            splitId: $legId,
            transactionId: $transactionId,
            userId: $user->id,
            mutationType: 'edit',
            dirtyFields: $dirty,
        );
    }

    /**
     * @param  array{id: ?int, category_id: int, settled_amount_minor: int, note: ?string}  $leg
     */
    private function insertLeg(User $user, int $transactionId, array $leg, int $index, string $currency, CarbonImmutable $now): TransactionSplitMutated
    {
        $fields = [
            'user_id' => $user->id,
            'transaction_id' => $transactionId,
            'category_id' => $leg['category_id'],
            'settled_amount_minor' => $leg['settled_amount_minor'],
            'settled_currency' => $currency,
            'note' => self::normalizeNote($leg['note']),
            'sort_order' => $index,
            // Pre-formatted datetime strings into the op-log dirtyFields
            // rather than CarbonImmutable objects — decouples the capture
            // payload from Carbon and the op-log serialiser's coercion.
            'created_at' => $now->toDateTimeString(),
            'updated_at' => $now->toDateTimeString(),
        ];

        // $fields (plaintext) stays the dispatched-event source of truth;
        // only the DB row gets the ciphertext note.
        $dbFields = $fields;
        $dbFields['note'] = $this->encryptNote($fields['note'], $user->id);

        $newId = self::toInt($this->db->connection()->table('transaction_splits')->insertGetId($dbFields));

        return new TransactionSplitMutated(
            splitId: $newId,
            transactionId: $transactionId,
            userId: $user->id,
            mutationType: 'create',
            dirtyFields: $fields,
        );
    }

    // Present in the existing set but absent from the incoming one, which
    // is a delete rather than an edit and tombstones for the sync merge.
    /**
     * @param  list<int>  $existingIds
     * @param  list<int>  $incomingIds
     * @return list<TransactionSplitMutated>
     */
    private function deleteRemovedLegs(User $user, int $transactionId, array $existingIds, array $incomingIds): array
    {
        $events = [];

        foreach (array_diff($existingIds, $incomingIds) as $removedId) {
            $this->db->connection()
                ->table('transaction_splits')
                ->where('id', $removedId)
                ->where('user_id', $user->id)
                ->delete();

            $events[] = new TransactionSplitMutated(
                splitId: $removedId,
                transactionId: $transactionId,
                userId: $user->id,
                mutationType: 'delete',
            );
        }

        return $events;
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
            throw new InvalidArgumentException(Lang::get('ledger::detail.errors.survivor_not_accessible'));
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
                throw new InvalidArgumentException(Lang::get(self::NOT_FOUND_KEY));
            }

            // Reconciled lock: reuses the already user-scoped parent
            // load above — no extra query.
            if (self::toString($parent->status) === ClearedStatus::Reconciled->value) {
                throw new InvalidArgumentException(Lang::get('ledger::detail.errors.reconciled_split'));
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
                throw new InvalidArgumentException(Lang::get('ledger::detail.errors.survivor_must_be_current'));
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

    // $newValue's PHP type determines which normalization applies to
    // the raw DB value before comparing. string|null fields (note) are
    // canonicalised so '' and null compare equal, or leg notes would
    // ping-pong between '' and null across devices under LWW.
    private static function legFieldChanged(mixed $oldValue, mixed $newValue): bool
    {
        if (is_int($newValue)) {
            return self::toInt($oldValue) !== $newValue;
        }

        $old = $oldValue === null ? null : self::toString($oldValue);
        $new = $newValue === null ? null : self::toString($newValue);

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
