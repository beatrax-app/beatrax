<?php

declare(strict_types=1);

namespace Modules\OpenBanking\Internal\Dto;

use Carbon\CarbonImmutable;

final readonly class FetchWindow
{
    public function __construct(
        public CarbonImmutable $dateFrom,
        public CarbonImmutable $dateTo,
    ) {}
}
