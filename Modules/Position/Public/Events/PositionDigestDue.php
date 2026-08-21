<?php

declare(strict_types=1);

namespace Modules\Position\Public\Events;

use Modules\Position\Public\Dto\PositionSummaryDto;

final readonly class PositionDigestDue
{
    /**
     * @param  string  $cadence  'daily' | 'weekly' — never 'off'.
     */
    public function __construct(
        public int $userId,
        public string $cadence,
        public string $occurrence,
        public PositionSummaryDto $position,
    ) {}
}
