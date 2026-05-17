<?php

declare(strict_types=1);

namespace Modules\Recurring\Public\Events;

/**
 * Dispatched when the detector promotes a series from one cadence
 * class to another (e.g. `monthly` to `irregular` after consecutive
 * misses). The event carries the prior and the new cadence so
 * downstream consumers can compose a single-sentence narration of the
 * change without re-deriving it from the audit table.
 */
final readonly class RecurringSeriesCadenceFlipped
{
    public function __construct(
        public int $seriesId,
        public int $userId,
        public string $oldCadence,
        public string $newCadence,
    ) {}
}
