<?php

declare(strict_types=1);

namespace Modules\Migration\Internal\Dto;

use Spatie\LaravelData\Data;

final class MigrationConfirmResult extends Data
{
    public function __construct(
        public readonly int $migrationRunId,
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
