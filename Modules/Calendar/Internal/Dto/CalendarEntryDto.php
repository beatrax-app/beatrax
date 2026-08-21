<?php

declare(strict_types=1);

namespace Modules\Calendar\Internal\Dto;

use Spatie\LaravelData\Data;

final class CalendarEntryDto extends Data
{
    public function __construct(
        public readonly int $seriesId,
        public readonly string $name,
        public readonly int $amountMinor,
        public readonly string $currency,
        public readonly string $direction,
        public readonly ?int $accountId,
        public readonly string $accountName,
        public readonly ?int $counterpartyId,
        public readonly ?string $counterpartySlug,
        public readonly bool $isPaid,
        public readonly bool $isMissed,
        public readonly bool $isApproximate,
    ) {}
}
