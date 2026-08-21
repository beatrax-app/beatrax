<?php

declare(strict_types=1);

namespace Modules\Migration\Internal\Dto;

use Modules\Migration\Internal\Enums\ConflictResolution;
use Spatie\LaravelData\Data;

final class ConflictDto extends Data
{
    public function __construct(
        public readonly string $entityType,
        public readonly ?string $sourceExternalId,
        public readonly string $fieldName,
        public readonly mixed $localValue,
        public readonly mixed $sourceValue,
        public readonly mixed $baselineValue,
        public readonly ?string $currency = null,
        public readonly string $resolution = ConflictResolution::KeepLocal->value,
    ) {}
}
