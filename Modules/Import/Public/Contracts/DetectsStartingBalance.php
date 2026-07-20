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
    // Introspection only — the aggregator itself walks every tagged
    // detector regardless and unions their candidate output.
    public function supports(string $sourceFormat): bool;

    // Stateless: reads statement_summaries/import_runs, holds no
    // per-call state. Empty list when this detector recognises none of
    // the supplied import-run ids, so the aggregator tries the next one.
    /**
     * @param  list<int>  $importRunIds  ImportRun ids of any source format owned by `$user`; the detector filters internally to its own source format.
     * @return list<StartingBalanceCandidate>
     */
    public function detect(array $importRunIds, User $user): array;
}
