<?php

declare(strict_types=1);

namespace Modules\OpenBanking\Tests\Support;

use Generator;
use Modules\OpenBanking\Internal\Contracts\RemoteSourceAdapter;
use Modules\OpenBanking\Internal\Dto\FetchWalk;
use Modules\OpenBanking\Internal\Dto\FetchWindow;
use Modules\OpenBanking\Internal\Dto\OpenBankingCredentials;
use Throwable;

final class SojaStubRemoteSourceAdapter implements RemoteSourceAdapter
{
    public bool $called = false;

    public function __construct(private readonly ?Throwable $throws = null) {}

    public function format(): string
    {
        return 'enable-banking';
    }

    public function fetch(string $institutionId, FetchWindow $window, OpenBankingCredentials $credentials): Generator
    {
        $this->called = true;
        if ($this->throws !== null) {
            throw $this->throws;
        }

        // This job's contract is the bookkeeping around a fetch, not dedup.
        yield from [];

        return FetchWalk::exhausted();
    }
}
