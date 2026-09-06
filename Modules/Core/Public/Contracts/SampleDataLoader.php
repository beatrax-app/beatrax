<?php

declare(strict_types=1);

namespace Modules\Core\Public\Contracts;

// Loads the sample ledger onto an account that already exists. The
// implementation reaches thirty seeders across a dozen modules, which is why
// it lives at the application root and arrives here as a contract: a screen
// that offers the control should not be importing half the tree to do it.
/**
 * @link ../../../../.docs/features/core/architecture.md
 */
interface SampleDataLoader
{
    /**
     * @return array<string, int> step key => rows that step left present
     */
    public function loadFor(int $userId): array;
}
