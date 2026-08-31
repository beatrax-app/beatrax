<?php

declare(strict_types=1);

namespace Modules\Position\Public\Events;

use Modules\Core\Public\Enums\DigestCadence;
use Modules\Position\Public\Dto\PositionSummaryDto;

final readonly class PositionDigestDue
{
    // Never DigestCadence::Off: EmitPositionDigestJob returns before it
    // dispatches, so no listener has to answer for a cadence of "none".
    public function __construct(
        public int $userId,
        public DigestCadence $cadence,
        public string $occurrence,
        public PositionSummaryDto $position,
    ) {}
}
