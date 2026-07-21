<?php

declare(strict_types=1);

namespace Modules\DriftAlerts\Public\Dto;

use Spatie\LaravelData\Data;

final class SavingsInsight extends Data
{
    /**
     * @param  string  $key  stable per-(type, series) identifier used to persist dismissals
     */
    public function __construct(
        public readonly string $key,
        public readonly string $type,
        public readonly int $seriesId,
        public readonly string $name,
        public readonly int $monthlyMinor,
        public readonly string $currency,
        public readonly string $message,
        public readonly string $actionLabel,
        public readonly string $actionUrl,
        public readonly ?string $counterpartySlug = null,
    ) {}
}
