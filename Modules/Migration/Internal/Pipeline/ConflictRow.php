<?php

declare(strict_types=1);

namespace Modules\Migration\Internal\Pipeline;

use Modules\Migration\Internal\Enums\ConflictResolution;

final readonly class ConflictRow
{
    public function __construct(
        public string $entityType,
        public ?string $sourceExternalId,
        public string $fieldName,
        public ?string $localValue,
        public ?string $sourceValue,
        public ConflictResolution $resolution,
    ) {}
}
