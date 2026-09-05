<?php

declare(strict_types=1);

namespace Modules\OpenBanking\Tests\Support;

use Generator;
use Modules\Ingestion\Public\Dto\SourceTransactionDto;
use Modules\OpenBanking\Internal\Contracts\RemoteSourceAdapter;
use Modules\OpenBanking\Internal\Dto\FetchWalk;
use Modules\OpenBanking\Internal\Dto\FetchWindow;
use Modules\OpenBanking\Internal\Dto\OpenBankingCredentials;

// Records the credentials each fetch arrived with, so a test can assert that a
// bank was read with its OWN session rather than with whichever one the store
// happened to hold last.
final class AsbPerAccountStubRemoteSourceAdapter implements RemoteSourceAdapter
{
    /** @var list<array{accountUid: string, institutionId: ?string, sessionId: ?string}> */
    public array $seen = [];

    /**
     * @param  array<string, list<SourceTransactionDto>>  $rowsByAccountUid
     */
    public function __construct(private readonly array $rowsByAccountUid) {}

    public function format(): string
    {
        return 'enable-banking';
    }

    public function fetch(string $accountUid, FetchWindow $window, OpenBankingCredentials $credentials): Generator
    {
        $this->seen[] = [
            'accountUid' => $accountUid,
            'institutionId' => $credentials->institutionId,
            'sessionId' => $credentials->sessionId,
        ];

        $rows = $this->rowsByAccountUid[$accountUid] ?? [];
        yield from $rows;

        return FetchWalk::exhausted(1, count($rows));
    }
}
