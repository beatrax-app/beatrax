<?php

declare(strict_types=1);

namespace Modules\Recurring\Public\Events;

final readonly class RecurringSeriesCadenceFlipped
{
    public function __construct(
        public int $seriesId,
        public int $userId,
        public string $oldCadence,
        public string $newCadence,
    ) {}
}
