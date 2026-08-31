<?php

declare(strict_types=1);

namespace Modules\DriftAlerts\Internal;

use Modules\DriftAlerts\Internal\Enums\ThresholdSource;

// What one drift measurement is, passed whole between the three steps that
// make it. The same six keys used to travel as an array shape, declared in two
// docblocks that had to agree with each other and with every reader.
final readonly class DriftMetrics
{
    public function __construct(
        public int $priorMinor,
        public int $latestMinor,
        public int $deltaMinor,
        public int $annualizedMinor,
        public int $thresholdPercent,
        public ThresholdSource $thresholdSource,
        public int $latestOccurrenceId,
    ) {}
}
