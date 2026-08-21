<?php

declare(strict_types=1);

namespace Modules\Budgets\Internal\Fold;

use Modules\Budgets\Public\Dto\EnvelopeRow;

final class FoldStep
{
    /**
     * @param  array<int, int>  $carriedIn
     * @param  array<int, EnvelopeRow>  $rows
     */
    public function __construct(
        public readonly int $poolCarry,
        public readonly array $carriedIn,
        public readonly int $toBudgetMinor,
        public readonly int $overspentCount,
        public readonly array $rows,
    ) {}
}
