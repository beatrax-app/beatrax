<?php

declare(strict_types=1);

namespace Modules\Migration\Internal\Dto;

use Spatie\LaravelData\Data;

final class MigrationCategoryDto extends Data
{
    public function __construct(
        public readonly string $sourceExternalId,
        public readonly string $name,
        public readonly ?string $sourceGroupName,
        public readonly ?string $parentSourceExternalId,
        public readonly string $kind,
    ) {}
}
