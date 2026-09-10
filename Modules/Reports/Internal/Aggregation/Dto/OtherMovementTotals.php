<?php

declare(strict_types=1);

namespace Modules\Reports\Internal\Aggregation\Dto;

// Two facts, because a bucket that cannot be stated is not a bucket of zero:
// the dimension rows have `excludedCurrencies` to say so and this line had
// nothing, so turning an amount filter on removed the only disclosure that
// money the total omits had ever been left out at all.
final readonly class OtherMovementTotals
{
    /**
     * @param  array<string, int>  $byCurrency  settled currency => total, signed the same way the metric signs its own rows
     * @param  list<string>  $excludedCurrencies  currencies whose bucket the reader's own amount bound cannot be restated in, so there is no figure to report and the code is named instead
     */
    public function __construct(
        public array $byCurrency = [],
        public array $excludedCurrencies = [],
    ) {}
}
