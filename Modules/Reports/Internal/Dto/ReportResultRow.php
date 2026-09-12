<?php

declare(strict_types=1);

namespace Modules\Reports\Internal\Dto;

use Modules\FX\Public\Dto\ConversionDisclosure;
use Spatie\LaravelData\Data;

final class ReportResultRow extends Data
{
    /**
     * @param  ?ConversionDisclosure  $conversion  the rates THIS row converted at, where the row is a figure of its own rather than a slice of the headline's conversion. Null on every dimension row: a category total is converted at the one rate set the headline already discloses. A net-worth bucket is priced at the rate in effect on its own day, so its row is the only place that rate can be read.
     */
    public function __construct(
        public readonly int|string|null $groupKey,
        public readonly string $groupLabel,
        public readonly int $amountMinor,
        public readonly string $currency,
        public readonly ?int $previousAmountMinor = null,
        public readonly ?int $deltaMinor = null,
        public readonly ?ConversionDisclosure $conversion = null,
    ) {}
}
