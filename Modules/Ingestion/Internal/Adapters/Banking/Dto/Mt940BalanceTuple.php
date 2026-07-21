<?php

declare(strict_types=1);

namespace Modules\Ingestion\Internal\Adapters\Banking\Dto;

use Carbon\CarbonImmutable;

/**
 * @link ../../../../../../.docs/features/ingestion/architecture.md
 */
final readonly class Mt940BalanceTuple
{
    public function __construct(
        public int $minor,
        public string $currency,
        public CarbonImmutable $date,
    ) {}
}
