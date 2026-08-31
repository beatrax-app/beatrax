<?php

declare(strict_types=1);

namespace Modules\OpenBanking\Internal\Contracts;

use Generator;
use Modules\Ingestion\Public\Dto\SourceTransactionDto;
use Modules\OpenBanking\Internal\Dto\FetchWalk;
use Modules\OpenBanking\Internal\Dto\FetchWindow;
use Modules\OpenBanking\Internal\Dto\OpenBankingCredentials;

interface RemoteSourceAdapter
{
    public function format(): string;

    // The generator RETURNS a FetchWalk: a caller has to be able to tell a
    // window the bank had nothing in from one whose pages the walk stopped
    // asking for, and the rows alone cannot say which happened.
    /**
     * @return Generator<int, SourceTransactionDto, mixed, FetchWalk>
     */
    public function fetch(string $institutionId, FetchWindow $window, OpenBankingCredentials $credentials): Generator;
}
