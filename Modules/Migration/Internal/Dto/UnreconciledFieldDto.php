<?php

declare(strict_types=1);

namespace Modules\Migration\Internal\Dto;

use Spatie\LaravelData\Data;

// A field the merge refused to judge, one entry per currency pair rather than
// per row. Deliberately not a ConflictDto: a conflict offers take-source, and
// taking a source stated in another currency is the write this exists to refuse.
final class UnreconciledFieldDto extends Data
{
    public function __construct(
        public readonly string $entityType,
        public readonly string $fieldName,
        public readonly string $localCurrency,
        public readonly string $sourceCurrency,
    ) {}
}
