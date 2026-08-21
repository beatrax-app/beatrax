<?php

declare(strict_types=1);

namespace Modules\OpenBanking\Internal\Contracts;

use Generator;
use Modules\Ingestion\Public\Dto\SourceTransactionDto;
use Modules\OpenBanking\Internal\Dto\FetchWindow;
use Modules\OpenBanking\Internal\Dto\OpenBankingCredentials;

interface RemoteSourceAdapter
{
    public function format(): string;

    /**
     * @return Generator<int, SourceTransactionDto>
     */
    public function fetch(string $institutionId, FetchWindow $window, OpenBankingCredentials $credentials): Generator;
}
