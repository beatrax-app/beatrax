<?php

declare(strict_types=1);

namespace Modules\OpenBanking\Tests\Support;

use Generator;
use Modules\OpenBanking\Internal\Contracts\RemoteSourceAdapter;
use Modules\OpenBanking\Internal\Dto\FetchWalk;
use Modules\OpenBanking\Internal\Dto\FetchWindow;
use Modules\OpenBanking\Internal\Dto\OpenBankingCredentials;
use Throwable;

final class OmsStubRemoteSourceAdapter implements RemoteSourceAdapter
{
    /**
     * @param  list<SourceTransactionDto>  $rows
     */
    public function __construct(
        private readonly array $rows = [],
        private readonly ?Throwable $throws = null,
        private readonly ?FetchWalk $walk = null,
    ) {}

    public function format(): string
    {
        return 'enable-banking';
    }

    public function fetch(string $institutionId, FetchWindow $window, OpenBankingCredentials $credentials): Generator
    {
        if ($this->throws !== null) {
            throw $this->throws;
        }

        yield from $this->rows;

        return $this->walk ?? FetchWalk::exhausted(1, count($this->rows));
    }
}
