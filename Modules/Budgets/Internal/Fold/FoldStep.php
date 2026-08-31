<?php

declare(strict_types=1);

namespace Modules\Budgets\Internal\Fold;

use Modules\Budgets\Public\Dto\EnvelopeRow;

final readonly class FoldStep
{
    /**
     * @param  array<int, int>  $carriedIn
     * @param  array<int, EnvelopeRow>  $rows
     */
    public function __construct(
        public int $poolCarry,
        public array $carriedIn,
        public int $toBudgetMinor,
        public int $overspentCount,
        public array $rows,
    ) {}
}
