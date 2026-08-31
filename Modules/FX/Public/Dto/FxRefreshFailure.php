<?php

declare(strict_types=1);

namespace Modules\FX\Public\Dto;

use Carbon\CarbonImmutable;
use Modules\FX\Public\Enums\FxRefreshFailureReason;

final readonly class FxRefreshFailure
{
    public function __construct(
        public FxRefreshFailureReason $reason,
        public CarbonImmutable $at,
    ) {}
}
