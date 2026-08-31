<?php

declare(strict_types=1);

namespace Modules\Budgets\Internal\Rekey;

use Carbon\CarbonImmutable;

// Both ends of a period_start_day move: the period the reader is in, read on
// the day they left and on the day they chose, plus the genesis the fold walks
// from on each. A row keeps its distance from the anchor and never lands below
// genesis.
final readonly class PeriodShift
{
    public function __construct(
        public int $previousStartDay,
        public CarbonImmutable $anchorOld,
        public CarbonImmutable $anchorNew,
        public ?CarbonImmutable $genesisOld,
        public ?CarbonImmutable $genesisNew,
    ) {}
}
