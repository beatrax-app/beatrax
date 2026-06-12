<?php

declare(strict_types=1);

namespace Modules\Tax\Public\Http\Livewire\Concerns;

use Livewire\Attributes\On;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Tax\Internal\Actions\TaxCategoryWriter;
use Modules\Tax\Public\Actions\TagTransaction;
use Modules\Tax\Public\Actions\UntagTransaction;
use Modules\Tax\Public\Services\TaxTagQuery;

/**
 * Reusable Livewire trait for the four transaction-row surfaces.
 *
 * Provides tag / untag / category / batch actions plus the taxTagStateFor()
 * batch-load helper. All collaborators arrive as method parameters (no
 * constructor DI — Livewire strict-rules prohibition).
 *
 * Event wiring uses $dispatch/#[On] (Open Question 1 resolution) so the
 * badge works from deeply-nested row partials without direct method calls.
 *
 * Security:
 *  - tag/untag/applyBatchTag all delegate to user-scoped actions (T-07-18)
 *  - taxTagStateFor uses TaxTagQuery which scopes to user_id (T-07-19/20)
 *
 * Pitfall prevention:
 *  - Pitfall 1: taxTagStateFor issues ONE query for the full batch via
 *    TaxTagQuery::forTransactionIds — never per-row.
 *  - Pitfall 7: applyBatchTag sets batchSuggestionDismissed=true after
 *    applying; dismissBatch does the same. tagTransaction only surfaces
 *    a suggestion when batchSuggestionDismissed is already false.
 */
trait HandlesTaxTagging
{
    // ── Picker state ────────────────────────────────────────────────────────

    /** Transaction id currently open in the picker, or null (closed). */
    public ?int $taxPickerTxId = null;

    /** Note textarea binding. */
    public string $pickerNote = '';

    /** Selected deduction category id (null = no category). */
    public ?int $pickerCategoryId = null;

    /** Manual year override for this tag (null = derive from booked_at). */
    public ?int $pickerYearOverride = null;

    /** Booked year of the transaction in the picker (for the year-assignment row). */
    public ?int $pickerBookedYear = null;

    /** Current seasonal tax year (for the year-assignment row). */
    public ?int $pickerTaxYear = null;

    /** Inline new-category name input. */
    public string $pickerInlineNewName = '';

    /** Whether the inline new-category row is expanded. */
    public bool $pickerIsNewCatOpen = false;

    // ── Batch-tag suggestion state ──────────────────────────────────────────

    /**
     * Batch-tag suggestion data. null = no pending suggestion.
     * Stored as a plain string-keyed array so Livewire can dehydrate it.
     *
     * 'categoryId' and 'note' are snapshotted from the picker when the
     * trigger tag is saved — saveTaxCategory() resets the picker state via
     * closePicker(), so applyBatchTag() can no longer read the live picker
     * properties by the time the banner is clicked (D-03: one confirmation
     * applies the SAME category as the trigger tag).
     *
     * Keys: 'counterpartyId' (int), 'counterpartyName' (string), 'untaggedCount' (int),
     * 'categoryId' (int|null, optional), 'note' (string|null, optional).
     *
     * @var array{counterpartyId: int, counterpartyName: string, untaggedCount: int, categoryId?: int|null, note?: string|null}|null
     */
    public ?array $batchSuggestion = null;

    /** True after applyBatchTag or dismissBatch — suppresses re-surfacing (Pitfall 7). */
    public bool $batchSuggestionDismissed = false;

    // ── Picker categories list (loaded fresh on open) ────────────────────────

    /**
     * Active categories for the picker list.
     *
     * @var list<\stdClass>
     */
    public array $pickerCategories = [];

    // ── Listeners ────────────────────────────────────────────────────────────

    /**
     * One-tap tag (optimistic badge flip) + open picker + batch suggestion.
     * Dispatched by x-tax::tax-badge when the ghost "Tag" button is clicked.
     */
    #[On('tax-tag')]
    public function tagTransaction(
        int $id,
        TagTransaction $tag,
        CurrentUser $u,
        TaxTagQuery $q,
        Clock $c,
        TaxCategoryWriter $writer,
    ): void {
        $user = $u->user();

        // (1) Immediate tag with no category — one tap is enough.
        $tag->execute($user->id, $id, null, null, null);

        // (2) Open the picker for the optional category/note/year selection.
        $this->openPickerFor($id, $c, $writer, $u, $q);

        // (3) Compute batch suggestion (D-03) — only when not already dismissed.
        if (! $this->batchSuggestionDismissed) {
            $taxYear = $this->resolveCurrentTaxYear($c);
            $suggestion = $q->untaggedCountForCounterparty($user->id, $id, $taxYear);

            if ($suggestion->untaggedCount >= 2) {
                $this->batchSuggestion = [
                    'counterpartyId' => $suggestion->counterpartyId,
                    'counterpartyName' => $suggestion->counterpartyName,
                    'untaggedCount' => $suggestion->untaggedCount,
                ];
            } else {
                $this->batchSuggestion = null;
            }
        }
    }

    /**
     * Open the picker for an already-tagged row (to edit category/note/year).
     * Dispatched by x-tax::tax-badge when the emerald pill is clicked.
     */
    #[On('tax-edit-tag')]
    public function editTaxTag(
        int $id,
        TaxTagQuery $q,
        CurrentUser $u,
        Clock $c,
        TaxCategoryWriter $writer,
    ): void {
        $user = $u->user();

        // Pre-fill picker from the existing tag.
        $tags = $q->forTransactionIds($user->id, [$id]);
        if (isset($tags[$id])) {
            $existing = $tags[$id];
            $this->pickerCategoryId = $existing->deductionCategoryId;
            $this->pickerNote = $existing->note ?? '';
            $this->pickerYearOverride = $existing->taxYearOverride;
        }

        $this->openPickerFor($id, $c, $writer, $u, $q);
    }

    // ── Actions ──────────────────────────────────────────────────────────────

    /**
     * Save the category / note / year override for the open picker transaction.
     */
    public function saveTaxCategory(
        TagTransaction $tag,
        CurrentUser $u,
    ): void {
        if ($this->taxPickerTxId === null) {
            return;
        }

        $tag->execute(
            $u->user()->id,
            $this->taxPickerTxId,
            $this->pickerCategoryId,
            $this->pickerNote !== '' ? $this->pickerNote : null,
            $this->pickerYearOverride,
        );

        // Snapshot the saved category/note onto the pending batch suggestion:
        // closePicker() wipes the picker state, but a later applyBatchTag()
        // must apply the SAME category + note as this trigger tag (D-03).
        if ($this->batchSuggestion !== null) {
            $this->batchSuggestion['categoryId'] = $this->pickerCategoryId;
            $this->batchSuggestion['note'] = $this->pickerNote !== '' ? $this->pickerNote : null;
        }

        $this->closePicker();
        $this->dispatch('toast', message: 'Tagged as tax-deductible.');
    }

    /**
     * Create a new category inline and immediately select it in the picker.
     */
    public function addInlineCategory(
        TaxCategoryWriter $writer,
        CurrentUser $u,
    ): void {
        $name = trim($this->pickerInlineNewName);
        if ($name === '') {
            return;
        }

        try {
            $newId = $writer->add($u->user()->id, $name);
            // Refresh the categories list.
            $this->pickerCategories = $writer->listForUser($u->user()->id);
            // Auto-select the new category.
            $this->pickerCategoryId = $newId;
            $this->pickerInlineNewName = '';
            $this->pickerIsNewCatOpen = false;
        } catch (\RuntimeException) {
            // Name conflict — ignore; the DB unique constraint already prevents doubles.
        }
    }

    /**
     * Untag the currently-open transaction.
     */
    public function untag(
        UntagTransaction $untag,
        CurrentUser $u,
    ): void {
        if ($this->taxPickerTxId === null) {
            return;
        }

        $untag->execute($u->user()->id, $this->taxPickerTxId);
        $this->closePicker();
        $this->dispatch('toast', message: 'Tax tag removed.');
    }

    /**
     * Apply the pending batch suggestion: tag all matching untagged rows
     * with the same category + note as the picker. Dismisses the banner
     * to prevent Pitfall-7 re-appearance.
     */
    public function applyBatchTag(
        TagTransaction $tag,
        CurrentUser $u,
        TaxTagQuery $q,
        Clock $c,
    ): void {
        if ($this->batchSuggestion === null) {
            return;
        }

        $user = $u->user();
        $cpId = $this->batchSuggestion['counterpartyId'];
        $taxYear = $this->resolveCurrentTaxYear($c);

        // Fetch the untagged transaction ids for this counterparty/year.
        $ids = $q->untaggedIdsForCounterparty($user->id, $cpId, $taxYear);

        // Prefer the snapshot taken at trigger-tag save time; fall back to the
        // live picker state ONLY when no snapshot was taken (picker-still-open
        // case). array_key_exists — not ?? — because a snapshotted null means
        // "the trigger tag was saved with NO category/note" and must not fall
        // through to picker state from an unrelated row (WR-03 / D-03).
        $categoryId = array_key_exists('categoryId', $this->batchSuggestion)
            ? $this->batchSuggestion['categoryId']
            : $this->pickerCategoryId;
        $note = array_key_exists('note', $this->batchSuggestion)
            ? $this->batchSuggestion['note']
            : ($this->pickerNote !== '' ? $this->pickerNote : null);

        foreach ($ids as $txId) {
            $tag->execute(
                $user->id,
                $txId,
                $categoryId,
                $note,
                null,
            );
        }

        $count = count($ids);
        $this->batchSuggestion = null;
        $this->batchSuggestionDismissed = true; // Pitfall-7 guard.
        $this->dispatch('toast', message: "Tagged {$count} more transactions.");
    }

    /** Dismiss the batch-tag banner without tagging. */
    public function dismissBatch(): void
    {
        $this->batchSuggestion = null;
        $this->batchSuggestionDismissed = true; // Pitfall-7 guard.
    }

    /** Close the picker without saving (Escape key or click-outside). */
    public function closePicker(): void
    {
        $this->taxPickerTxId = null;
        $this->pickerNote = '';
        $this->pickerCategoryId = null;
        $this->pickerYearOverride = null;
        $this->pickerBookedYear = null;
        $this->pickerInlineNewName = '';
        $this->pickerIsNewCatOpen = false;
    }

    // ── Batch-load helper (Pitfall 1) ────────────────────────────────────────

    /**
     * Batch-load tax tag state for an array of transaction ids.
     *
     * Issues ONE query via TaxTagQuery::forTransactionIds (no N+1).
     * Returns an array<int, array{taxTagged: bool, taxCategoryShortName: ?string}>
     * keyed by transaction id, suitable for merging into row arrays.
     *
     * @param  array<int>  $transactionIds
     * @return array<int, array{taxTagged: bool, taxCategoryShortName: ?string}>
     */
    public function taxTagStateFor(
        array $transactionIds,
        TaxTagQuery $q,
        CurrentUser $u,
    ): array {
        if ($transactionIds === []) {
            return [];
        }

        $tags = $q->forTransactionIds($u->user()->id, $transactionIds);

        $result = [];
        foreach ($transactionIds as $id) {
            $result[$id] = [
                'taxTagged' => isset($tags[$id]),
                'taxCategoryShortName' => $tags[$id]->deductionCategoryShortName ?? null,
            ];
        }

        return $result;
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    private function openPickerFor(
        int $id,
        Clock $c,
        TaxCategoryWriter $writer,
        CurrentUser $u,
        TaxTagQuery $q,
    ): void {
        $this->taxPickerTxId = $id;
        $this->pickerIsNewCatOpen = false;
        $this->pickerInlineNewName = '';

        // Load categories fresh.
        $this->pickerCategories = $writer->listForUser($u->user()->id);

        // Resolve booked year + current tax year for the D-10 year-assignment
        // row (rendered when they differ — CR-02).
        $this->pickerTaxYear = $this->resolveCurrentTaxYear($c);
        $this->pickerBookedYear = $q->bookedYearFor($u->user()->id, $id);
    }

    private function resolveCurrentTaxYear(Clock $c): int
    {
        $now = $c->now();

        return $now->month <= 4 ? $now->year - 1 : $now->year;
    }
}
