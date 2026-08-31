<?php

declare(strict_types=1);

namespace Modules\Ledger\Internal\Support;

use Modules\Ledger\Public\Dto\TransactionRowDto;
use Modules\Search\Public\Dto\SearchResultPage;
use Modules\Search\Public\Dto\SearchRowDto;

// The plain arrays the transactions list is rendered from, kept apart from the
// component that holds the filter state. A list row and a search row are two
// DTOs the same table renders, so the one shape they both project into is
// stated once here rather than beside whichever query produced it.
final class TransactionListViewData
{
    // Money is not Livewire-dehydratable, so a row carries the minor integer
    // and currency code and the blade rebuilds it via Money::ofMinor().
    /** @return array{id: int, postedAt: string, counterpartyName: ?string, counterpartySlug: ?string, categoryId: ?int, amountMinor: int, amountCurrency: string, secondaryMinor: ?int, secondaryCurrency: ?string, taxTagged: bool, taxCategoryShortName: ?string, splitLegs: list<array{id: int, categoryName: string, amountMinor: int, amountCurrency: string, note: ?string, taxTagged: bool, taxCategoryShortName: ?string}>} */
    public static function row(TransactionRowDto $row): array
    {
        return [
            'id' => $row->id,
            'postedAt' => $row->postedAt,
            'counterpartyName' => $row->counterpartyName,
            'counterpartySlug' => $row->counterpartySlug,
            'categoryId' => $row->categoryId,
            'amountMinor' => $row->amount->toMinor(),
            'amountCurrency' => $row->amount->currency(),
            'secondaryMinor' => $row->secondaryAmount?->toMinor(),
            'secondaryCurrency' => $row->secondaryAmount?->currency(),
            'taxTagged' => false,
            'taxCategoryShortName' => null,
            'splitLegs' => [],
        ];
    }

    /** @return array{id: int, postedAt: string, counterpartyName: ?string, counterpartySlug: ?string, categoryId: ?int, amountMinor: int, amountCurrency: string, secondaryMinor: ?int, secondaryCurrency: ?string, taxTagged: bool, taxCategoryShortName: ?string, splitLegs: list<array{id: int, categoryName: string, amountMinor: int, amountCurrency: string, note: ?string, taxTagged: bool, taxCategoryShortName: ?string}>} */
    public static function searchRow(SearchRowDto $row): array
    {
        return [
            'id' => $row->id,
            'postedAt' => $row->postedAt,
            'counterpartyName' => $row->counterpartyName,
            'counterpartySlug' => $row->counterpartySlug,
            'categoryId' => $row->categoryId,
            'amountMinor' => $row->amountMinor,
            'amountCurrency' => $row->amountCurrency,
            'secondaryMinor' => $row->secondaryMinor,
            'secondaryCurrency' => $row->secondaryCurrency,
            'taxTagged' => false,
            'taxCategoryShortName' => null,
            'splitLegs' => [],
        ];
    }

    // Every figure the search strip reads comes off one page, so they are taken
    // off it in one place. The row map is rebuilt per render and never
    // accumulated: a stale highlight must not outlive the query that made it.
    /**
     * @return array<string, mixed>
     */
    public static function searchPage(SearchResultPage $page): array
    {
        $searchRows = [];
        foreach ($page->rows as $row) {
            $searchRows[$row->id] = $row;
        }

        return [
            'page' => $page,
            'searchTotalCount' => $page->totalCount,
            'searchTotalOut' => $page->totalOutMinor,
            'searchTotalIn' => $page->totalInMinor,
            'searchUnconverted' => $page->unconvertedList(),
            'didYouMean' => $page->didYouMean,
            'searchRows' => $searchRows,
        ];
    }
}
