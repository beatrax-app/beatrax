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

// A bank answers for the window it was asked about and nothing else, which is
// what makes the advanced cursor lose the day: ask again from the day after,
// and this transaction is not in the answer.
final class SncrStubRemoteSourceAdapter implements RemoteSourceAdapter
{
    public int $fetches = 0;

    public function format(): string
    {
        return 'enable-banking';
    }

    public function fetch(string $accountUid, FetchWindow $window, OpenBankingCredentials $credentials): Generator
    {
        $this->fetches++;

        $bookedOn = CarbonImmutable::parse(SNCR_BOOKED_ON);

        if ($bookedOn->lessThan($window->dateFrom) || $bookedOn->greaterThan($window->dateTo)) {
            return FetchWalk::exhausted(1, 0);
        }

        yield new SourceTransactionDto(
            bookedAt: $bookedOn,
            postedAt: $bookedOn,
            valueDate: $bookedOn,
            ownIban: SNCR_UNNAMED_IBAN,
            counterpartyIban: 'NL91ABNA0417164300',
            counterpartyName: 'Netflix',
            currency: 'EUR',
            amountMinor: -1299,
            sourceRef: 'eb-sncr-1',
            description: 'Netflix subscription',
            rawPayload: [],
            sourceRowIndex: 0,
        );

        return FetchWalk::exhausted(1, 1);
    }
}
