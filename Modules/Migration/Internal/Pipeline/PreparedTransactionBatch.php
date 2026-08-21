<?php

declare(strict_types=1);

namespace Modules\Migration\Internal\Pipeline;

use Modules\Ledger\Public\Dto\CanonicalTransaction;
use stdClass;

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
