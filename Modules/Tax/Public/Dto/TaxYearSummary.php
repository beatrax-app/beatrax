<?php

declare(strict_types=1);

namespace Modules\Tax\Public\Dto;

use Modules\FX\Public\Dto\ConversionDisclosure;
use Spatie\LaravelData\Data;

final class TaxYearSummary extends Data
{
    /**
     * @param  list<string>  $unconvertedCurrencies  codes left out of $totalMinor for
     *                                               want of a rate
     */
    public function __construct(
        public readonly int $year,
        public readonly int $totalMinor,
        public readonly int $count,
        public readonly string $currency = '',
        public readonly array $unconvertedCurrencies = [],
        public readonly ?ConversionDisclosure $conversion = null,
    ) {}

    public function isPartial(): bool
    {
        return $this->unconvertedCurrencies !== [];
    }

    public function unconvertedList(): string
    {
        return implode(', ', $this->unconvertedCurrencies);
    }
}
