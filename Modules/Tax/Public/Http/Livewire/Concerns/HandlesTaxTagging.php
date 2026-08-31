<?php

declare(strict_types=1);

namespace Modules\Tax\Public\Http\Livewire\Concerns;

use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Core\Public\Http\Livewire\Concerns\DispatchesToast;
use Modules\Core\Public\Support\Lang;
use Modules\Ledger\Public\Services\TransactionStatusQuery;
use Modules\Tax\Internal\Exceptions\DuplicateTaxCategoryNameException;
use Modules\Tax\Internal\Support\FilingSeason;
use Modules\Tax\Public\Actions\TagTransaction;
use Modules\Tax\Public\Actions\UntagTransaction;
use Modules\Tax\Public\Services\TaxCategoryWriter;
use Modules\Tax\Public\Services\TaxTagQuery;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * @link ../../../../../../.docs/features/tax/batch-tag-suggestion.md
 */
trait HandlesTaxTagging
{
    use DispatchesToast;

    public ?int $taxPickerTxId = null;

    public string $pickerNote = '';

    public ?int $pickerCategoryId = null;

    public ?int $pickerYearOverride = null;

    public ?int $pickerPostedYear = null;

    public ?int $pickerTaxYear = null;

    public string $pickerInlineNewName = '';

    public bool $pickerIsNewCatOpen = false;

    /**
     * @var array{counterpartyId: int, counterpartyName: string, untaggedCount: int, taxYear?: int, categoryId?: int|null, note?: string|null}|null
     */
    public ?array $batchSuggestion = null;

    public bool $batchSuggestionDismissed = false;

    // Locked because the popover reads $cat->id off every row: the picker is
    // written only from TaxCategoryWriter, and a wire payload putting a string
    // in the list made the render itself a 500 rather than a refusal.
    /**
     * @var list<\stdClass>
     */
    #[Locked]
    public array $pickerCategories = [];

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

        if ($status->isReconciled($user->id, $id)) {
            $this->toast(Lang::get('tax::messages.reconciled_lock'));

            return;
        }

        try {
            $tag->execute($user->id, $id, null, null, null);
        } catch (NotFoundHttpException|\InvalidArgumentException) {
            // Returning rather than falling through: openPickerFor() below would
            // open a picker onto a row that is not there, and the batch banner
            // under it would count against a counterparty this reader cannot see.
            $this->toast(Lang::get('tax::messages.errors.tag_refused'));

            return;
        }

        $this->openPickerFor($id, $c, $writer, $u, $q);

        if (! $this->batchSuggestionDismissed) {
            // Keyed to the trigger row's booked year, not the seasonal current
            // one: the banner must count and apply against the same year.
            $taxYear = $this->pickerPostedYear ?? $this->resolveCurrentTaxYear($c);
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

        // pickerCategoryId and pickerYearOverride are set from the popover with
        // $set, so they carry whatever the client sent. The action's ownership
        // and range guards are the boundary and they answer by throwing; a
        // tampered payload gets a calm flash here, never a 500 or a 404.
        try {
            $tag->execute(
                $u->user()->id,
                $this->taxPickerTxId,
                $this->pickerCategoryId,
                $this->pickerNote !== '' ? $this->pickerNote : null,
                $this->pickerYearOverride,
            );
        } catch (NotFoundHttpException|\InvalidArgumentException) {
            $this->pickerCategoryId = null;
            $this->pickerYearOverride = null;
            $this->toast(Lang::get('tax::messages.errors.tag_refused'));

            return;
        }

        // closePicker() below clears pickerCategoryId and pickerNote, so the
        // batch banner's payload has to be captured before it runs.
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
        } catch (DuplicateTaxCategoryNameException $duplicate) {
            // The box keeps the name and the panel stays open, so the refusal
            // has the field it is about still under it.
            $this->toast($duplicate->getMessage());
        } catch (\RuntimeException) {
            // Its sibling, and not a name clash at all: the row went in and its
            // id could not be read back. Saying "already exists" here would name
            // a cause that had been ruled out.
            $this->toast(Lang::get('tax::messages.errors.category_not_saved'));
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

        // The banner's payload is a public array the component writes and the
        // view only reads, so anything that does not have the shape this
        // component put there is a tampered snapshot and not a banner.
        $cpId = self::batchCounterpartyId($this->batchSuggestion);
        if ($cpId === null) {
            $this->dismissBatch();

            return;
        }

        $user = $u->user();
        // The year the suggestion counted against, so the banner cannot apply to
        // a different one. The seasonal year only covers a pre-snapshot state.
        $taxYear = self::batchTaxYear($this->batchSuggestion) ?? $this->resolveCurrentTaxYear($c);

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

        try {
            foreach ($ids as $txId) {
                $tag->execute(
                    $user->id,
                    $txId,
                    is_int($categoryId) ? $categoryId : null,
                    is_string($note) ? $note : null,
                    null,
                );
            }
        } catch (NotFoundHttpException|\InvalidArgumentException) {
            $this->dismissBatch();
            $this->toast(Lang::get('tax::messages.errors.tag_refused'));

            return;
        }

        $count = count($ids);
        $this->batchSuggestion = null;
        $this->batchSuggestionDismissed = true;

        // Nothing written is still an answer: every candidate was reconciled,
        // and a banner that closed in silence reads as a tag that happened.
        $this->toast($count === 0
            ? Lang::get('tax::messages.batch_none_reconciled')
            : Lang::choice('tax::messages.batch_tagged', $count));
    }

    /**
     * @param  array<mixed>  $suggestion
     */
    private static function batchCounterpartyId(array $suggestion): ?int
    {
        $id = $suggestion['counterpartyId'] ?? null;

        return is_int($id) && $id > 0 ? $id : null;
    }

    /**
     * @param  array<mixed>  $suggestion
     */
    private static function batchTaxYear(array $suggestion): ?int
    {
        $year = $suggestion['taxYear'] ?? null;

        return is_int($year) ? $year : null;
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
        $this->pickerPostedYear = null;
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

        $this->pickerTaxYear = $this->resolveCurrentTaxYear($c);
        $this->pickerPostedYear = $q->postedYearFor($u->user()->id, $id);
    }

    private function resolveCurrentTaxYear(Clock $c): int
    {
        return FilingSeason::defaultYear($c->now());
    }
}
