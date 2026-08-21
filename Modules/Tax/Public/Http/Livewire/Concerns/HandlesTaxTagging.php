<?php

declare(strict_types=1);

namespace Modules\Tax\Public\Http\Livewire\Concerns;

use Livewire\Attributes\On;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Core\Public\Http\Livewire\Concerns\DispatchesToast;
use Modules\Core\Public\Support\Lang;
use Modules\Ledger\Public\Services\TransactionStatusQuery;
use Modules\Tax\Public\Actions\TagTransaction;
use Modules\Tax\Public\Actions\UntagTransaction;
use Modules\Tax\Public\Services\TaxCategoryWriter;
use Modules\Tax\Public\Services\TaxTagQuery;

/**
 * @link ../../../../../../.docs/features/tax/batch-tag-suggestion.md
 */
trait HandlesTaxTagging
{
    use DispatchesToast;

    public ?int $taxPickerTxId = null;

    public string $pickerNote = '';

    public ?int $pickerCategoryId = null;

    // null derives the tag's year from the transaction's booked_at.
    public ?int $pickerYearOverride = null;

    public ?int $pickerBookedYear = null;

    public ?int $pickerTaxYear = null;

    public string $pickerInlineNewName = '';

    public bool $pickerIsNewCatOpen = false;

    // A plain string-keyed array, because Livewire cannot dehydrate the DTO.
    /**
     * @var array{counterpartyId: int, counterpartyName: string, untaggedCount: int, taxYear?: int, categoryId?: int|null, note?: string|null}|null
     */
    public ?array $batchSuggestion = null;

    public bool $batchSuggestionDismissed = false;

    /**
     * @var list<\stdClass>
     */
    public array $pickerCategories = [];

    // Dispatched by x-tax::tax-badge's ghost "Tag" button.
    #[On('tax-tag')]
    public function tagTransaction(
        int $id,
        TagTransaction $tag,
        CurrentUser $u,
        TaxTagQuery $q,
        Clock $c,
        TaxCategoryWriter $writer,
        TransactionStatusQuery $status,
    ): void {
        $user = $u->user();

        // Ahead of both the write and the picker, so a locked row stays untouched.
        if ($status->isReconciled($user->id, $id)) {
            $this->toast(Lang::get('tax::messages.reconciled_lock'));

            return;
        }

        $tag->execute($user->id, $id, null, null, null);

        $this->openPickerFor($id, $c, $writer, $u, $q);

        if (! $this->batchSuggestionDismissed) {
            // Keyed to the trigger row's booked year, not the seasonal current
            // one: the banner must count and apply against the same year.
            $taxYear = $this->pickerBookedYear ?? $this->resolveCurrentTaxYear($c);
            $suggestion = $q->untaggedCountForCounterparty($user->id, $id, $taxYear);

            if ($suggestion->untaggedCount >= 2) {
                $this->batchSuggestion = [
                    'counterpartyId' => $suggestion->counterpartyId,
                    'counterpartyName' => $suggestion->counterpartyName,
                    'untaggedCount' => $suggestion->untaggedCount,
                    'taxYear' => $taxYear,
                ];
            } else {
                $this->batchSuggestion = null;
            }
        }
    }

    // Dispatched by x-tax::tax-badge's emerald pill.
    #[On('tax-edit-tag')]
    public function editTaxTag(
        int $id,
        TaxTagQuery $q,
        CurrentUser $u,
        Clock $c,
        TaxCategoryWriter $writer,
    ): void {
        $user = $u->user();

        // Open (which resets the row fields) before pre-filling, so a tag-lookup
        // miss leaves clean fields rather than another row's stale values.
        $this->openPickerFor($id, $c, $writer, $u, $q);

        $tags = $q->forTransactionIds($user->id, [$id]);
        if (isset($tags[$id])) {
            $existing = $tags[$id];
            $this->pickerCategoryId = $existing->deductionCategoryId;
            $this->pickerNote = $existing->note ?? '';
            $this->pickerYearOverride = $existing->taxYearOverride;
        }
    }

    public function saveTaxCategory(
        TagTransaction $tag,
        CurrentUser $u,
        TransactionStatusQuery $status,
    ): void {
        if ($this->taxPickerTxId === null) {
            return;
        }

        if ($status->isReconciled($u->user()->id, $this->taxPickerTxId)) {
            $this->toast(Lang::get('tax::messages.reconciled_lock'));

            return;
        }

        $tag->execute(
            $u->user()->id,
            $this->taxPickerTxId,
            $this->pickerCategoryId,
            $this->pickerNote !== '' ? $this->pickerNote : null,
            $this->pickerYearOverride,
        );

        // Snapshot the saved category and note before closePicker() wipes them.
        if ($this->batchSuggestion !== null) {
            $this->batchSuggestion['categoryId'] = $this->pickerCategoryId;
            $this->batchSuggestion['note'] = $this->pickerNote !== '' ? $this->pickerNote : null;
        }

        $this->closePicker();
        $this->toast(Lang::get('tax::messages.tagged'));
    }

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
            $this->pickerCategories = $writer->listForUser($u->user()->id);
            $this->pickerCategoryId = $newId;
            $this->pickerInlineNewName = '';
            $this->pickerIsNewCatOpen = false;
        } catch (\RuntimeException) {
            // Name conflict — ignore; the DB unique constraint already
            // prevents doubles.
        }
    }

    public function untag(
        UntagTransaction $untag,
        CurrentUser $u,
        TransactionStatusQuery $status,
    ): void {
        if ($this->taxPickerTxId === null) {
            return;
        }

        if ($status->isReconciled($u->user()->id, $this->taxPickerTxId)) {
            $this->toast(Lang::get('tax::messages.reconciled_lock'));

            return;
        }

        $untag->execute($u->user()->id, $this->taxPickerTxId);
        $this->closePicker();
        $this->toast(Lang::get('tax::messages.untagged'));
    }

    public function applyBatchTag(
        TagTransaction $tag,
        CurrentUser $u,
        TaxTagQuery $q,
        Clock $c,
        TransactionStatusQuery $status,
    ): void {
        if ($this->batchSuggestion === null) {
            return;
        }

        $user = $u->user();
        $cpId = $this->batchSuggestion['counterpartyId'];
        // The year the suggestion counted against, so the banner cannot apply to
        // a different one. The seasonal year only covers a pre-snapshot state.
        $taxYear = $this->batchSuggestion['taxYear'] ?? $this->resolveCurrentTaxYear($c);

        $ids = $q->untaggedIdsForCounterparty($user->id, $cpId, $taxYear);

        // One query, before tagging: the banner only writes editable rows and the
        // count below only reports rows actually written.
        $reconciledIds = $status->reconciledIdsAmong($user->id, $ids);
        if ($reconciledIds !== []) {
            $ids = array_values(array_diff($ids, $reconciledIds));
        }

        // array_key_exists, not ??: a snapshotted null means "saved with no
        // category/note", and ?? would fall through to whatever picker state an
        // unrelated row left behind.
        $categoryId = array_key_exists('categoryId', $this->batchSuggestion)
            ? $this->batchSuggestion['categoryId']
            : $this->pickerCategoryId;
        $livePickerNote = $this->pickerNote !== '' ? $this->pickerNote : null;
        $note = array_key_exists('note', $this->batchSuggestion)
            ? $this->batchSuggestion['note']
            : $livePickerNote;

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
        $this->batchSuggestionDismissed = true;

        // Every candidate reconciled: say so, not "Tagged 0 more transactions."
        if ($count === 0) {
            $this->toast(Lang::get('tax::messages.batch_none_reconciled'));

            return;
        }

        $this->toast(Lang::get('tax::messages.batch_tagged', ['count' => $count]));
    }

    public function dismissBatch(): void
    {
        $this->batchSuggestion = null;
        $this->batchSuggestionDismissed = true;
    }

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

    /**
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

        // Re-opening without an intermediate closePicker() would otherwise bleed
        // one row's state into the next. editTaxTag() prefills after this call.
        $this->pickerNote = '';
        $this->pickerCategoryId = null;
        $this->pickerYearOverride = null;

        $this->pickerCategories = $writer->listForUser($u->user()->id);

        // The blade renders the year-assignment row only when these two differ.
        $this->pickerTaxYear = $this->resolveCurrentTaxYear($c);
        $this->pickerBookedYear = $q->bookedYearFor($u->user()->id, $id);
    }

    private function resolveCurrentTaxYear(Clock $c): int
    {
        $now = $c->now();

        return $now->month <= 4 ? $now->year - 1 : $now->year;
    }
}
