<?php

declare(strict_types=1);

namespace Modules\Reports\Internal\Dto;

use Spatie\LaravelData\Data;

final class ReportResultRow extends Data
{
    public function __construct(
        public readonly int|string|null $groupKey,
        public readonly string $groupLabel,
        public readonly int $amountMinor,
        public readonly string $currency,
        public readonly ?int $previousAmountMinor = null,
        public readonly ?int $deltaMinor = null,
    ) {}
}
