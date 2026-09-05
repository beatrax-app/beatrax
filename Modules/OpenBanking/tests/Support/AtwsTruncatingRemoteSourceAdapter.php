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

// A bounded walk that says nothing is a silent truncation: the reader sees a
// green tile, a fresh timestamp and part of a window, with no way to tell which
// part is missing.
final class AtwsTruncatingRemoteSourceAdapter implements RemoteSourceAdapter
{
    public function __construct(private readonly FetchWalk $walk) {}

    public function format(): string
    {
        return 'enable-banking';
    }

    public function fetch(string $institutionId, FetchWindow $window, OpenBankingCredentials $credentials): Generator
    {
        foreach ([['2026-07-17', -1401, 'atws-1'], ['2026-07-18', -1502, 'atws-2']] as $index => [$date, $minor, $ref]) {
            yield new SourceTransactionDto(
                bookedAt: CarbonImmutable::parse($date),
                postedAt: CarbonImmutable::parse($date),
                valueDate: CarbonImmutable::parse($date),
                ownIban: 'NL57ASNB0123456789',
                counterpartyIban: 'NL91ABNA0417164300',
                counterpartyName: 'Fixture Merchant',
                currency: 'EUR',
                amountMinor: $minor,
                sourceRef: $ref,
                description: 'EB row '.$ref,
                rawPayload: [],
                sourceRowIndex: $index,
            );
        }

        return $this->walk;
    }
}
