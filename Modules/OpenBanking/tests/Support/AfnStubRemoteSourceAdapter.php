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

final class AfnStubRemoteSourceAdapter implements RemoteSourceAdapter
{
    public int $fetches = 0;

    public function __construct(private readonly int $rowCount = 2) {}

    public function format(): string
    {
        return 'enable-banking';
    }

    public function fetch(string $accountUid, FetchWindow $window, OpenBankingCredentials $credentials): Generator
    {
        $this->fetches++;

        for ($i = 0; $i < $this->rowCount; $i++) {
            yield new SourceTransactionDto(
                bookedAt: CarbonImmutable::parse('2026-07-18'),
                postedAt: CarbonImmutable::parse('2026-07-18'),
                valueDate: CarbonImmutable::parse('2026-07-18'),
                ownIban: AFN_OWN_IBAN,
                counterpartyIban: 'NL91ABNA041716430'.$i,
                counterpartyName: 'Fixture Merchant '.$i,
                currency: 'EUR',
                amountMinor: -1299 - $i,
                sourceRef: 'afn-ref-'.$i,
                description: 'Fixture EB row '.$i,
                rawPayload: [],
                sourceRowIndex: $i,
            );
        }

        return FetchWalk::exhausted(1, $this->rowCount);
    }
}
