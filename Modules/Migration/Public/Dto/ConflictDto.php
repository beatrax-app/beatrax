<?php

declare(strict_types=1);

namespace Modules\Migration\Public\Dto;

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
        public readonly string $resolution = 'keep_local',
    ) {}
}
