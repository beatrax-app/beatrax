<?php

declare(strict_types=1);

namespace Modules\OpenBanking\Public\Contracts;

use Generator;
use Modules\Ingestion\Public\Dto\SourceTransactionDto;
use Modules\OpenBanking\Public\Dto\FetchWindow;
use Modules\OpenBanking\Public\Dto\OpenBankingCredentials;

/**
 * @link ../../../../.docs/features/open-banking/architecture.md
 */
interface RemoteSourceAdapter
{
    public function format(): string;

    /**
     * @return Generator<int, SourceTransactionDto>
     */
    public function fetch(string $institutionId, FetchWindow $window, OpenBankingCredentials $credentials): Generator;
}
