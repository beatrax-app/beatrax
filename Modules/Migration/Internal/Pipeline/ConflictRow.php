<?php

declare(strict_types=1);

namespace Modules\Migration\Internal\Pipeline;

/**
 * @link ../../../../.docs/features/migration/architecture.md
 */
final class ConflictRow
{
    public function __construct(
        public readonly string $entityType,
        public readonly ?string $sourceExternalId,
        public readonly string $fieldName,
        public readonly ?string $localValue,
        public readonly ?string $sourceValue,
        public readonly string $resolution,
    ) {}
}
