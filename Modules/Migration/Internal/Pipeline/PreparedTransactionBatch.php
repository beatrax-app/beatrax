<?php

declare(strict_types=1);

namespace Modules\Migration\Internal\Pipeline;

use Modules\Ledger\Public\Dto\CanonicalTransaction;
use stdClass;

// One chunk of staged transaction rows already prepared for persistence: the
// surviving staging rows and their index-aligned canonical transactions.
// prepareCanonicalRows() produces both together and persistPromotedRows()
// consumes both together, so they travel as one value rather than two args.
/**
 * @link ../../../../.docs/features/migration/architecture.md
 */
final readonly class PreparedTransactionBatch
{
    /**
     * @param  list<stdClass>  $rows
     * @param  list<CanonicalTransaction>  $canonicals
     */
    public function __construct(
        public array $rows,
        public array $canonicals,
    ) {}
}
