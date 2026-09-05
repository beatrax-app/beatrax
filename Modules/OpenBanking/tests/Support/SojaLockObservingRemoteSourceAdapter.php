<?php

declare(strict_types=1);

namespace Modules\OpenBanking\Tests\Support;

use Closure;
use Generator;
use Illuminate\Bus\UniqueLock;
use Modules\Core\Public\Support\LockStore;
use Modules\OpenBanking\Internal\Contracts\RemoteSourceAdapter;
use Modules\OpenBanking\Internal\Dto\FetchWalk;
use Modules\OpenBanking\Internal\Dto\FetchWindow;
use Modules\OpenBanking\Internal\Dto\OpenBankingCredentials;
use Modules\OpenBanking\Internal\Jobs\SyncOpenBankingAccountJob;

// Reports whether the connection's uniqueness key is still free at the moment
// the fetch runs, which is the window a manual sync would race.
final class SojaLockObservingRemoteSourceAdapter implements RemoteSourceAdapter
{
    /**
     * @param  Closure(bool): void  $report
     */
    public function __construct(
        private readonly int $connectionId,
        private readonly Closure $report,
    ) {}

    public function format(): string
    {
        return 'enable-banking';
    }

    public function fetch(string $accountUid, FetchWindow $window, OpenBankingCredentials $credentials): Generator
    {
        $probe = LockStore::forUniqueJobs()->lock(
            UniqueLock::getKey(new SyncOpenBankingAccountJob($this->connectionId)),
            SyncOpenBankingAccountJob::UNIQUE_FOR_SECONDS,
        );

        $free = $probe->get();
        if ($free) {
            $probe->release();
        }

        ($this->report)($free);

        yield from [];

        return FetchWalk::exhausted();
    }
}
