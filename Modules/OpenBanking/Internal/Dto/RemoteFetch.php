<?php

declare(strict_types=1);

namespace Modules\OpenBanking\Internal\Dto;

use Generator;
use Modules\Ingestion\Public\Dto\SourceTransactionDto;

// One materialized fetch: the rows, the run they belong to, the window they
// answer, and how far the page walk actually got.
final readonly class RemoteFetch
{
    /**
     * @param  Generator<int, SourceTransactionDto>  $rows
     */
    public function __construct(
        public Generator $rows,
        public string $idempotencyKey,
        public FetchWindow $window,
        public FetchWalk $walk,
    ) {}
}
