<?php

declare(strict_types=1);

namespace Modules\Ledger\Internal\Http\Livewire\Concerns;

use Illuminate\Contracts\Session\Session;
use Illuminate\Database\DatabaseManager;
use InvalidArgumentException;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Core\Public\Http\Livewire\Concerns\DispatchesToast;
use Modules\Core\Public\Support\Lang;
use Modules\Ledger\Models\Transaction;
use Modules\Ledger\Public\Contracts\SavesTransactionSplit;
use Modules\Ledger\Public\Enums\ClearedStatus;
use Modules\Ledger\Public\Exceptions\SplitSumMismatchException;
use Modules\Ledger\Public\ValueObjects\Money;
use Modules\Sync\Public\Services\SensitiveColumnCodec;
use Modules\Tax\Public\Actions\TagTransaction;
use Modules\Tax\Public\Actions\UntagTransaction;
use Modules\Tax\Public\Services\TaxTagQuery;

// The inline split editor: its own session state, its own confirm flows,
// and the two mutator calls that make any of it durable. It sits beside
// TransactionDetail rather than inside it because it was fifteen of that
// component's twenty-five methods and answers a question of its own.
/**
 * @link ../../../../../../.docs/features/ledger/architecture.md
 */
trait ManagesSplitEditor
{
    use DispatchesToast;

    // True either because the transaction already carries persisted
    // legs, or because the user just clicked "Split into categories".
    public bool $editingSplit = false;

    // Distinct from $editingSplit: a freshly-opened, never-saved split
    // editor has editingSplit=true but hasPersistedSplit=false —
    // nothing persists until "Save split". Gates the whole-transaction
    // tax section suppression.
    public bool $hasPersistedSplit = false;

    // Session-local until saveSplit() persists them. categoryId is
    // int|string|null because Livewire's <select> wire:model always
    // arrives as a string; every read normalises via legCategoryId().
    /** @var list<array{id: ?int, categoryId: int|string|null, amount: string, note: string, tax: bool}> */
    public array $legs = [];

    // Computed via the Money value object, never client math. Positive
    // = still to assign, negative = over-allocated, zero = exact.
    public int $remainingMinor = 0;

    // Set when the server rejects a save (SplitSumMismatchException,
    // invalid leg, etc).
    public ?string $splitError = null;

    public bool $confirmUnsplit = false;

    public ?int $unsplitSurvivorIndex = null;

    public bool $confirmRemoveToOne = false;

    public ?int $pendingRemoveIndex = null;

    // Seeds exactly 2 in-memory legs for a not-yet-split transaction —
    // leg 0 = current category + full parent amount, leg 1 = blank —
    // and persists nothing. No-op if already split (legs are already
    // loaded by loadSplitState() at mount time).
    public function openSplitEditor(CurrentUser $currentUser, DatabaseManager $db): void
    {
        if ($this->hasPersistedSplit) {
            return;
        }

        $userId = $currentUser->user()->id;
        $parent = $db->connection()
            ->table('transactions')
            ->where('id', $this->transactionId)
            ->where('user_id', $userId)
            ->first(['category_id', 'settled_amount_minor']);

        if ($parent === null) {
            return;
        }

        $categoryId = is_numeric($parent->category_id) ? (int) $parent->category_id : null;
        $absMinor = abs(is_numeric($parent->settled_amount_minor) ? (int) $parent->settled_amount_minor : 0);

        $this->legs = [
            ['id' => null, 'categoryId' => $categoryId, 'amount' => self::formatAbsAmount($absMinor), 'note' => '', 'tax' => false],
            ['id' => null, 'categoryId' => null, 'amount' => self::formatAbsAmount(0), 'note' => '', 'tax' => false],
        ];
        $this->editingSplit = true;
        $this->splitError = null;

        $this->recomputeRemaining($currentUser, $db);
    }

    public function addLeg(): void
    {
        $this->legs[] = ['id' => null, 'categoryId' => null, 'amount' => self::formatAbsAmount(0), 'note' => '', 'tax' => false];
    }

    // At >=3 legs this removes instantly. At exactly 2, removing would
    // drop the count to 1 — instead of silently collapsing, this routes
    // to the same two-step confirm as "Unsplit transaction", scoped to
    // the leg that would become the sole survivor.
    public function removeLeg(int $index, CurrentUser $currentUser, DatabaseManager $db): void
    {
        if (! array_key_exists($index, $this->legs)) {
            return;
        }

        if (count($this->legs) <= 2) {
            $this->pendingRemoveIndex = $index;
            $this->confirmRemoveToOne = true;

            return;
        }

        $legs = $this->legs;
        array_splice($legs, $index, 1);
        $this->legs = $legs;
        $this->recomputeRemaining($currentUser, $db);
    }

    public function cancelRemoveToOne(): void
    {
        $this->confirmRemoveToOne = false;
        $this->pendingRemoveIndex = null;
    }

    // The other leg (not the one the user clicked remove on) becomes the
    // surviving category. A never-persisted editor collapses in memory;
    // persistUnsplit() owns that distinction and the mutator call.
    public function confirmRemoveToOneAction(SavesTransactionSplit $splitter, CurrentUser $currentUser): void
    {
        if ($this->pendingRemoveIndex === null || count($this->legs) !== 2) {
            return;
        }

        $survivorIndex = $this->pendingRemoveIndex === 0 ? 1 : 0;
        $error = $this->persistUnsplit($splitter, $currentUser, $survivorIndex, Lang::get('ledger::detail.errors.choose_before_removing'));

        if ($error !== null) {
            $this->splitError = $error;

            return;
        }

        $this->resetSplitEditor();
        $this->confirmRemoveToOne = false;
        $this->pendingRemoveIndex = null;
        $this->toast(Lang::get('ledger::detail.toast.removed_one_remains'));
    }

    // Defaults the survivor radio to the larger-magnitude leg — a
    // pre-selected (not locked) radio choice.
    public function unsplit(): void
    {
        if ($this->legs === []) {
            return;
        }

        $this->unsplitSurvivorIndex = $this->largestMagnitudeLegIndex();
        $this->confirmUnsplit = true;
    }

    public function selectUnsplitSurvivor(int $index): void
    {
        if (array_key_exists($index, $this->legs)) {
            $this->unsplitSurvivorIndex = $index;
        }
    }

    public function cancelUnsplit(): void
    {
        $this->confirmUnsplit = false;
        $this->unsplitSurvivorIndex = null;
    }

    public function confirmUnsplitAction(SavesTransactionSplit $splitter, CurrentUser $currentUser): void
    {
        if ($this->unsplitSurvivorIndex === null || ! array_key_exists($this->unsplitSurvivorIndex, $this->legs)) {
            return;
        }

        $error = $this->persistUnsplit($splitter, $currentUser, $this->unsplitSurvivorIndex, Lang::get('ledger::detail.errors.choose_before_unsplitting'));

        if ($error !== null) {
            $this->splitError = $error;

            return;
        }

        $this->resetSplitEditor();
        $this->confirmUnsplit = false;
        $this->unsplitSurvivorIndex = null;
        $this->toast(Lang::get('ledger::detail.toast.unsplit_restored'));
    }

    // Drives the chosen survivor category through the unsplit mutator when
    // a split is actually persisted; a never-saved editor has nothing to
    // reverse, so this reports success and lets the caller collapse it in
    // memory. Returns an error string to surface, or null to finish.
    private function persistUnsplit(
        SavesTransactionSplit $splitter,
        CurrentUser $currentUser,
        int $survivorIndex,
        string $missingCategoryMessage,
    ): ?string {
        if ($this->hasPersistedSplit) {
            $survivorCategoryId = self::legCategoryId($this->legs[$survivorIndex]);

            if ($survivorCategoryId === null) {
                return $missingCategoryMessage;
            }

            try {
                $splitter->unsplit($currentUser->user(), $this->transactionId, $survivorCategoryId);
            } catch (InvalidArgumentException $e) {
                return $e->getMessage();
            }
        }

        return null;
    }

    // Re-validates server-side (the disabled Save button is a UX gate
    // only, never authoritative): recomputes remainingMinor fresh,
    // rejects any leg that fails to parse or carries no category, then
    // delegates to the sole mutator.
    public function saveSplit(SavesTransactionSplit $splitter, CurrentUser $currentUser, DatabaseManager $db, TaxTagQuery $taxTagQuery, SensitiveColumnCodec $codec, Session $session): void
    {
        $this->splitError = null;
        $userId = $currentUser->user()->id;

        $this->recomputeRemaining($currentUser, $db);

        if ($this->remainingMinor !== 0) {
            $this->splitError = Lang::get('ledger::detail.errors.totals_must_match');

            return;
        }

        $parent = $db->connection()
            ->table('transactions')
            ->where('id', $this->transactionId)
            ->where('user_id', $userId)
            ->first(['settled_amount_minor']);

        if ($parent === null) {
            $this->splitError = Lang::get('ledger::detail.errors.not_found');

            return;
        }

        $parentMinor = is_numeric($parent->settled_amount_minor) ? (int) $parent->settled_amount_minor : 0;

        $legsPayload = $this->buildLegsPayload($parentMinor);

        if ($legsPayload === null) {
            return;
        }

        try {
            $splitter->save($currentUser->user(), $this->transactionId, $legsPayload);

            // Reload persisted legs (real DB ids) + tax state so subsequent
            // edits/removals correctly diff against the now-saved rows.
            $this->loadSplitState($currentUser, $db, $taxTagQuery, $codec, $session);
            $this->toast(Lang::get('ledger::detail.toast.split_saved'));
        } catch (SplitSumMismatchException) {
            $this->splitError = Lang::get('ledger::detail.errors.totals_must_match');
        } catch (InvalidArgumentException $e) {
            $this->splitError = $e->getMessage();
        }
    }

    // Parses and validates every leg into the mutator's payload shape,
    // signing each settled amount to match the parent. Returns null and
    // sets splitError on the first leg that fails to parse or carries no
    // category, so the caller aborts without saving anything.
    /**
     * @return list<array{id: ?int, category_id: int, settled_amount_minor: int, note: ?string}>|null
     */
    private function buildLegsPayload(int $parentMinor): ?array
    {
        $legsPayload = [];

        foreach ($this->legs as $leg) {
            $abs = self::parseAmount($leg['amount']);
            if ($abs === null) {
                $this->splitError = Lang::get('ledger::detail.errors.amount_zero');

                return null;
            }

            $categoryId = self::legCategoryId($leg);
            if ($categoryId === null) {
                $this->splitError = Lang::get('ledger::detail.errors.choose_category');

                return null;
            }

            $trimmedNote = trim($leg['note']);

            $legsPayload[] = [
                'id' => $leg['id'],
                'category_id' => $categoryId,
                'settled_amount_minor' => $parentMinor < 0 ? -$abs : $abs,
                'note' => $trimmedNote !== '' ? $trimmedNote : null,
            ];
        }

        return $legsPayload;
    }

    // A no-op for a leg that has not yet been persisted (no id) — leg-
    // scoped tax tagging requires an existing transaction_splits row.
    public function toggleLegTax(int $index, TagTransaction $tag, UntagTransaction $untag, CurrentUser $currentUser, DatabaseManager $db): void
    {
        if (! array_key_exists($index, $this->legs)) {
            return;
        }

        $legId = $this->legs[$index]['id'];
        if ($legId === null) {
            return;
        }

        $userId = $currentUser->user()->id;

        // Reconciled lock: warn-first, no write. Tax tags on a
        // reconciled transaction's legs are exactly the classification
        // a reconcile is meant to freeze.
        $status = $db->connection()
            ->table('transactions')
            ->where('id', $this->transactionId)
            ->where('user_id', $userId)
            ->value('status');

        if ($status === ClearedStatus::Reconciled->value) {
            $this->toast(Lang::get(self::RECONCILED_NOTICE_KEY));

            return;
        }

        $newState = ! $this->legs[$index]['tax'];

        if ($newState) {
            $tag->execute($userId, $this->transactionId, null, null, null, $legId);
        } else {
            $untag->execute($userId, $this->transactionId, $legId);
        }

        $this->legs[$index]['tax'] = $newState;
    }

    // Called at mount and after every successful saveSplit() so leg
    // ids stay in sync with the DB for subsequent edit diffs.
    private function loadSplitState(CurrentUser $currentUser, DatabaseManager $db, TaxTagQuery $taxTagQuery, SensitiveColumnCodec $codec, Session $session): void
    {
        $userId = $currentUser->user()->id;

        $rows = $db->connection()
            ->table('transaction_splits')
            ->where('transaction_id', $this->transactionId)
            ->where('user_id', $userId)
            ->orderBy('sort_order')
            ->get(['id', 'category_id', 'settled_amount_minor', 'note']);

        if ($rows->isEmpty()) {
            $this->hasPersistedSplit = false;
            $this->editingSplit = false;
            $this->legs = [];

            return;
        }

        $taxStates = $taxTagQuery->forTransactionIdsWithLegs($userId, [$this->transactionId]);

        $legs = [];
        foreach ($rows as $row) {
            $legId = is_numeric($row->id) ? (int) $row->id : 0;
            $key = $this->transactionId.':'.$legId;

            // Read-side decrypt — pass-through no-op when encryption
            // is not enabled for this user.
            $legNote = is_string($row->note)
                ? $codec->decryptValue('transaction_splits', 'note', $row->note, $userId, $session)['value']
                : '';

            $legs[] = [
                'id' => $legId,
                'categoryId' => is_numeric($row->category_id) ? (int) $row->category_id : null,
                'amount' => self::formatAbsAmount(is_numeric($row->settled_amount_minor) ? abs((int) $row->settled_amount_minor) : 0),
                'note' => $legNote,
                'tax' => isset($taxStates[$key]),
            ];
        }

        $this->legs = $legs;
        $this->hasPersistedSplit = true;
        $this->editingSplit = true;

        $this->recomputeRemaining($currentUser, $db);
    }

    // Re-reads the parent's settled amount and re-sums every leg's
    // parsed absolute amount, both via the Money value object — never
    // client-supplied math.
    private function recomputeRemaining(CurrentUser $currentUser, DatabaseManager $db): void
    {
        $userId = $currentUser->user()->id;

        $parent = $db->connection()
            ->table('transactions')
            ->where('id', $this->transactionId)
            ->where('user_id', $userId)
            ->first(['settled_amount_minor', 'settled_currency']);

        if ($parent === null) {
            $this->remainingMinor = 0;

            return;
        }

        $parentMinor = is_numeric($parent->settled_amount_minor) ? (int) $parent->settled_amount_minor : 0;
        $currency = is_string($parent->settled_currency) ? $parent->settled_currency : 'EUR';

        $parentAbs = Money::ofMinor(abs($parentMinor), $currency);
        $sumAbs = Money::ofMinor(0, $currency);

        foreach ($this->legs as $leg) {
            $abs = self::parseAmount($leg['amount']) ?? 0;
            $sumAbs = $sumAbs->plus(Money::ofMinor($abs, $currency));
        }

        $this->remainingMinor = $parentAbs->minus($sumAbs)->toMinor();
    }

    private function resetSplitEditor(): void
    {
        $this->editingSplit = false;
        $this->hasPersistedSplit = false;
        $this->legs = [];
        $this->remainingMinor = 0;
        $this->splitError = null;
    }

    private function largestMagnitudeLegIndex(): int
    {
        $bestIndex = 0;
        $bestAbs = -1;

        foreach ($this->legs as $index => $leg) {
            $abs = self::parseAmount($leg['amount']) ?? 0;
            if ($abs > $bestAbs) {
                $bestAbs = $abs;
                $bestIndex = $index;
            }
        }

        return $bestIndex;
    }
}
