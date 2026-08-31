<?php

declare(strict_types=1);

namespace Modules\Migration\Internal\Pipeline;

final readonly class PromoteResult
{
    public function __construct(
        public int $categoriesCreated,
        public int $accountsCreated,
        public int $transactionsInserted,
        public int $transactionsSkipped,
        public int $splitsCreated,
        public int $transfersPaired,
        public int $counterpartiesResolved,
        public int $goalsCreated,
        public int $budgetMonthsWritten,
    ) {}
}
