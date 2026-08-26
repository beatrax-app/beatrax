<?php

declare(strict_types=1);

namespace Modules\Ledger\Public\Dto;

use Carbon\CarbonImmutable;
use Modules\Ledger\Public\Enums\Direction;
use Modules\Ledger\Public\ValueObjects\Money;
use Spatie\LaravelData\Data;

final class BookedFutureRowDto extends Data
{
    /**
     * @param  Money  $settled  the pair as the ACCOUNT holds it, so it lands on the
     *                          same currency line every balance and every projection anchor is read on
     * @param  Direction  $direction  taken from the sign rather than from transactions.type,
     *                                because it is what puts the + or − in front of the figure on screen and a
     *                                refund is an income-direction type carrying a positive amount either way
     */
    public function __construct(
        public readonly int $transactionId,
        public readonly int $accountId,
        public readonly CarbonImmutable $postedAt,
        public readonly Money $settled,
        public readonly Direction $direction,
        public readonly ?string $counterpartyName,
        public readonly ?string $counterpartySlug,
    ) {}
}
