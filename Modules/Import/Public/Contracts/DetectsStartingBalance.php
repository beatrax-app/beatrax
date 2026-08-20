<?php

declare(strict_types=1);

namespace Modules\Import\Public\Contracts;

use Modules\Core\Models\User;
use Modules\Import\Public\Dto\StartingBalanceCandidate;

/**
 * @link ../../../../.docs/features/import/architecture.md#starting-balance-detection
 */
interface DetectsStartingBalance
{
    // Introspection only: the aggregator walks every tagged detector anyway.
    public function supports(string $sourceFormat): bool;

    // Empty when none of the supplied run ids are this detector's format,
    // which is how the aggregator moves on to the next one.
    /**
     * @param  list<int>  $importRunIds  ImportRun ids of any source format owned by `$user`; the detector filters internally to its own source format.
     * @return list<StartingBalanceCandidate>
     */
    public function detect(array $importRunIds, User $user): array;
}
