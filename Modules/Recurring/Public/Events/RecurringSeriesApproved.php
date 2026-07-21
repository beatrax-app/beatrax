<?php

declare(strict_types=1);

namespace Modules\Recurring\Public\Events;

final readonly class RecurringSeriesApproved
{
    public function __construct(
        public int $seriesId,
        public int $userId,
    ) {}
}
