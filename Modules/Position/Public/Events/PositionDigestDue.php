<?php

declare(strict_types=1);

namespace Modules\Position\Public\Events;

use Modules\Position\Public\Dto\PositionSummaryDto;

/**
 * Dispatched by `EmitPositionDigestJob` exactly once per cadence occurrence
 * (Req 5) — never for `cadence = 'off'` (an 'off' cadence emits nothing at
 * all).
 *
 * `$occurrence` is D-06's convergence key: `$now->toDateString()` for
 * 'daily', the ISO week (`{isoWeekYear}-W{isoWeek, zero-padded}`) for
 * 'weekly'. The occurrence MUST be computed identically on every device —
 * derived from the clock and the cadence only, NEVER from a
 * locale-formatted label — or two independently-computed digests for the
 * same logical occurrence would diverge and fail to collapse to one
 * notification row (mirrors `DeterministicKeyDeriver`'s determinism
 * discipline).
 *
 * This module never imports the Notifications module's namespace (D-28) —
 * this is a plain readonly Public event; a listener in that other module
 * (`PersistPositionDigest`, Task 3) is the sole subscriber.
 */
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
