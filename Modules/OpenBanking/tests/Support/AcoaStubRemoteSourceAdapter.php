<?php

declare(strict_types=1);

namespace Modules\OpenBanking\Tests\Support;

use Carbon\CarbonImmutable;
use Generator;
use Modules\Ingestion\Public\Dto\SourceTransactionDto;
use Modules\OpenBanking\Internal\Contracts\RemoteSourceAdapter;
use Modules\OpenBanking\Internal\Dto\FetchWalk;
use Modules\OpenBanking\Internal\Dto\FetchWindow;
use Modules\OpenBanking\Internal\Dto\OpenBankingCredentials;

// A bank answers for the window it was asked about and nothing else. That is
// what makes an over-advanced cursor permanent: a row behind dateFrom is not in
// the answer, this time or ever again.
final class AcoaStubRemoteSourceAdapter implements RemoteSourceAdapter
{
    /** @var list<array{date: string, minor: int, ref: string}> */
    public array $available = [];

    /** @var list<array{from: string, to: string}> */
    public array $windows = [];

    public function format(): string
    {
        return 'enable-banking';
    }

    public function fetch(string $accountUid, FetchWindow $window, OpenBankingCredentials $credentials): Generator
    {
        $this->windows[] = ['from' => $window->dateFrom->toDateString(), 'to' => $window->dateTo->toDateString()];

        $index = 0;
        foreach ($this->available as $row) {
            $bookedOn = CarbonImmutable::parse($row['date']);
            if ($bookedOn->lessThan($window->dateFrom) || $bookedOn->greaterThan($window->dateTo)) {
                continue;
            }

            yield new SourceTransactionDto(
                bookedAt: $bookedOn,
                postedAt: $bookedOn,
                valueDate: $bookedOn,
                ownIban: 'NL57ASNB0123456789',
                counterpartyIban: 'NL91ABNA0417164300',
                counterpartyName: 'Fixture Merchant',
                currency: 'EUR',
                amountMinor: $row['minor'],
                sourceRef: $row['ref'],
                description: 'EB row '.$row['ref'],
                rawPayload: [],
                sourceRowIndex: $index,
            );
            $index++;
        }

        return FetchWalk::exhausted(1, count($this->available));
    }
}
