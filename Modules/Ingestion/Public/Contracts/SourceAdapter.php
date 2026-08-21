<?php

declare(strict_types=1);

namespace Modules\Ingestion\Public\Contracts;

use Generator;
use Modules\Ingestion\Public\Dto\SourceTransactionDto;
use Modules\Ledger\Public\Dto\StatementSummaryData;

interface SourceAdapter
{
    public function format(): string;

    /**
     * @return Generator<int, SourceTransactionDto>
     */
    public function parse(string $localPath, AccountResolver $accounts): Generator;

    public function statementMetadata(): ?StatementSummaryData;
}
