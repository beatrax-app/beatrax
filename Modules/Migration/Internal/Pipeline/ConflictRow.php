<?php

declare(strict_types=1);

namespace Modules\Migration\Internal\Pipeline;

use Modules\Migration\Internal\Enums\ConflictResolution;

final class ConflictRow
{
    public function __construct(
        public readonly string $entityType,
        public readonly ?string $sourceExternalId,
        public readonly string $fieldName,
        public readonly ?string $localValue,
        public readonly ?string $sourceValue,
        public readonly ConflictResolution $resolution,
    ) {}
}
