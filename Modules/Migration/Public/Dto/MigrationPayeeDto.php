<?php

declare(strict_types=1);

namespace Modules\Migration\Public\Dto;

use Spatie\LaravelData\Data;

/**
 * @link ../../../../.docs/features/migration/architecture.md
 */
final class MigrationPayeeDto extends Data
{
    public function __construct(
        public readonly ?string $sourceExternalId,
        public readonly string $name,
    ) {}
}
