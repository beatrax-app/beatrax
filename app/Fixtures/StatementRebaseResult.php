<?php

declare(strict_types=1);

namespace App\Fixtures;

use Carbon\CarbonImmutable;

final readonly class StatementRebaseResult
{
    public function __construct(
        public string $contents,
        public string $format,
        public int $months,
        public CarbonImmutable $oldestBefore,
        public CarbonImmutable $newestBefore,
        public CarbonImmutable $oldestAfter,
        public CarbonImmutable $newestAfter,
        public int $datesRewritten,
    ) {}
}
