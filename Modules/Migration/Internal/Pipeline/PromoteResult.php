<?php

declare(strict_types=1);

namespace Modules\Migration\Internal\Pipeline;

/**
 * @link ../../../../.docs/features/migration/architecture.md
 */
final class PromoteResult
{
    public function __construct(
        public readonly int $categoriesCreated,
        public readonly int $accountsCreated,
        public readonly int $transactionsInserted,
        public readonly int $transactionsSkipped,
        public readonly int $splitsCreated,
        public readonly int $transfersPaired,
        public readonly int $counterpartiesResolved,
        public readonly int $goalsCreated,
    ) {}
}
