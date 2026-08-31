<?php

declare(strict_types=1);

namespace Modules\Recurring\Internal;

use Carbon\CarbonImmutable;
use Modules\Recurring\Public\Enums\SeriesCadence;

final readonly class InferredCadence
{
    public function __construct(
        public SeriesCadence $cadence,
        public float $medianIntervalDays,
        public ?CarbonImmutable $nextExpectedAt,
        public bool $confidenceLow,
        public int $missedCount,
        public ?int $billingDay = null,
    ) {}
}
