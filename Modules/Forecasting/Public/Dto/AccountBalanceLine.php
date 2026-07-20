<?php

declare(strict_types=1);

namespace Modules\Forecasting\Public\Dto;

use Carbon\CarbonImmutable;
use Spatie\LaravelData\Data;

/**
 * @link ../../../../.docs/features/forecasting/architecture.md
 */
final class AccountBalanceLine extends Data
{
    public function __construct(
        public readonly int $accountId,
        public readonly string $name,
        public readonly string $kind,
        public readonly int $balanceMinor,
        public readonly string $currency,
        public readonly bool $isLiability,
        public readonly ?int $baseEquivalentMinor = null,
        public readonly ?string $fxRate = null,
        public readonly ?string $fxSource = null,
        public readonly ?CarbonImmutable $fxAsOf = null,
        public readonly bool $fxIsStale = false,
    ) {}

    public function isConverted(): bool
    {
        return $this->baseEquivalentMinor !== null;
    }

    public function hasNoRate(string $baseCurrency): bool
    {
        return $this->currency !== $baseCurrency && $this->baseEquivalentMinor === null;
    }
}
