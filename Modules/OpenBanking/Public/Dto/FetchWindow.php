<?php

declare(strict_types=1);

namespace Modules\OpenBanking\Public\Dto;

use Carbon\CarbonImmutable;

/**
 * @link ../../../../.docs/features/open-banking/architecture.md
 */
final readonly class FetchWindow
{
    public function __construct(
        public CarbonImmutable $dateFrom,
        public CarbonImmutable $dateTo,
    ) {}
}
