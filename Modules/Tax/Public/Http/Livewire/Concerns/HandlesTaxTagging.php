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
 * @link ../../../../../../.docs/features/tax/architecture.md
 */
trait HandlesTaxTagging
{
    use DispatchesToast;

    // Mirrors the Ledger-side guarded mutators (TransactionDetail) so the two
    // cross-module lock surfaces speak with one voice; read through Lang::get
    // (tax::messages.reconciled_lock) so it translates alongside the views.

    public ?int $taxPickerTxId = null;

    public string $pickerNote = '';

    public ?int $pickerCategoryId = null;

    // Manual year override for this tag; null derives from booked_at.
    // pickerBookedYear/pickerTaxYear feed the year-assignment row (rendered
    // only when the two differ).
    public ?int $pickerYearOverride = null;

    public ?int $pickerBookedYear = null;

    public ?int $pickerTaxYear = null;

    public string $pickerInlineNewName = '';

    public bool $pickerIsNewCatOpen = false;

    // Stored as a plain string-keyed array so Livewire can dehydrate it;
    // see the batch-suggestion snapshot contract at the @link above.
    /**
     * @var array{counterpartyId: int, counterpartyName: string, untaggedCount: int, taxYear?: int, categoryId?: int|null, note?: string|null}|null
     */
    public ?array $batchSuggestion = null;

    public bool $batchSuggestionDismissed = false;

    /**
     * @var list<\stdClass>
     */
    public array $pickerCategories = [];

    // Dispatched by x-tax::tax-badge when the ghost "Tag" button is
    // clicked.
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

        // Warn-first, no write — checked before writing or opening the
        // picker so a locked row stays untouched (see the reconciled-lock
        // note at the @link above).
        if ($status->isReconciled($user->id, $id)) {
            $this->toast(Lang::get('tax::messages.reconciled_lock'));

            return;
        }

        $tag->execute($user->id, $id, null, null, null);

        $this->openPickerFor($id, $c, $writer, $u, $q);

        if (! $this->batchSuggestionDismissed) {
            // Keyed to the TRIGGER transaction's booked year (just resolved
            // by openPickerFor), not the seasonal current tax year — see
            // the year-keying note at the @link above.
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

    // Dispatched by x-tax::tax-badge when the emerald pill is
    // clicked.
    #[On('tax-edit-tag')]
    public function editTaxTag(
        int $id,
        TaxTagQuery $q,
        CurrentUser $u,
        Clock $c,
        TaxCategoryWriter $writer,
    ): void {
        $user = $u->user();

        // Open first (resets row-specific picker fields), then pre-fill
        // from the existing tag, so a tag-lookup miss leaves clean fields
        // rather than another row's stale values.
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

        // Snapshot the saved category/note onto the pending batch suggestion
        // before closePicker() wipes the picker state (see @link above).
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
        // Reuse the year snapshotted when the suggestion was computed, so
        // the banner can never apply to a different year than it counted —
        // the seasonal year is only a fallback for a stale snapshot.
        $taxYear = $this->batchSuggestion['taxYear'] ?? $this->resolveCurrentTaxYear($c);

        $ids = $q->untaggedIdsForCounterparty($user->id, $cpId, $taxYear);

        // Filter reconciled ids out in one query before tagging, so the
        // banner only ever tags editable rows and the success count below
        // honestly reflects only the rows actually written.
        $reconciledIds = $status->reconciledIdsAmong($user->id, $ids);
        if ($reconciledIds !== []) {
            $ids = array_values(array_diff($ids, $reconciledIds));
        }

        // Prefer the trigger-tag snapshot; fall back to the live picker
        // state only when no snapshot was taken. array_key_exists (not ??)
        // because a snapshotted null means "saved with no category/note,"
        // which must not fall through to an unrelated row's picker state.
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

        // If every candidate was reconciled, nothing was tagged — say so
        // rather than claiming "Tagged 0 more transactions."
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

        // Reset row-specific fields so state never bleeds between rows when
        // the picker is re-opened without an intermediate closePicker();
        // editTaxTag() prefills these after this call.
        $this->pickerNote = '';
        $this->pickerCategoryId = null;
        $this->pickerYearOverride = null;

        $this->pickerCategories = $writer->listForUser($u->user()->id);

        // Booked year + current tax year feed the year-assignment row,
        // rendered only when the two differ.
        $this->pickerTaxYear = $this->resolveCurrentTaxYear($c);
        $this->pickerBookedYear = $q->bookedYearFor($u->user()->id, $id);
    }

    private function resolveCurrentTaxYear(Clock $c): int
    {
        $now = $c->now();

        return $now->month <= 4 ? $now->year - 1 : $now->year;
    }
}
