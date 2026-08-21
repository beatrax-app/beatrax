<?php

declare(strict_types=1);

namespace Modules\Migration\Internal\Dto;

use Carbon\CarbonImmutable;
use Modules\Ledger\Public\ValueObjects\Money;
use Spatie\LaravelData\Data;

final class MigrationGoalDto extends Data
{
    public function __construct(
        public readonly string $categorySourceExternalId,
        public readonly string $name,
        public readonly Money $targetAmount,
        public readonly ?CarbonImmutable $targetDate,
    ) {}
}
