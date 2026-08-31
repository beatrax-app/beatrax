<?php

declare(strict_types=1);

namespace Modules\Ledger\Internal\Dto;

final readonly class ConvertedCategorySpend
{
    /**
     * @param  array<int, int>  $byCategoryId  category_id => spend in the display currency (positive minor)
     * @param  list<string>  $unconvertedCurrencies  codes left out of $byCategoryId for want of a rate
     */
    public function __construct(
        public array $byCategoryId,
        public array $unconvertedCurrencies = [],
    ) {}
}
