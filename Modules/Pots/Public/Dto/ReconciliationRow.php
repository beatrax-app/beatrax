<?php

declare(strict_types=1);

namespace Modules\Pots\Public\Dto;

use Modules\FX\Public\Dto\ConversionDisclosure;
use Spatie\LaravelData\Data;

final class ReconciliationRow extends Data
{
    // Every line is one currency's own, so nothing here is ever converted and
    // $conversion carries no rate: it names the codes no line answers for, in
    // the one place every money surface says that.
    /**
     * @param  list<string>  $unconverted  codes the account holds that no pot figure counts
     */
    public function __construct(
        public readonly int $accountId,
        public readonly string $accountName,
        public readonly string $currency,
        public readonly int $realBalanceMinor,
        public readonly int $allocatedMinor,
        public readonly int $unallocatedMinor,
        public readonly bool $isOverAllocated,
        public readonly array $unconverted = [],
        public readonly ?ConversionDisclosure $conversion = null,
    ) {}

    public function isPartial(): bool
    {
        return $this->unconverted !== [];
    }

    public function unconvertedList(): string
    {
        return implode(', ', $this->unconverted);
    }
}
